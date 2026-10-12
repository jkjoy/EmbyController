<?php

namespace app\service;

use app\media\model\FinanceRecordModel;
use app\media\model\PayRecordModel;
use app\media\model\UserModel;
use DomainException;
use think\facade\Config;
use think\facade\Db;
use think\facade\Log;
use think\facade\Queue;
use think\Request;
use Throwable;

/** Creation, signed callbacks and reconciliation share one atomic settlement. */
class PaymentService
{
    public function __construct(private PaymentGateway $gateway) {}

    public static function cents($money): int
    {
        if (!is_string($money) && !is_int($money)) throw new DomainException('请输入正确的金额', 400);
        if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/', (string) $money)) throw new DomainException('请输入正确的金额', 400);
        [$whole, $fraction] = array_pad(explode('.', (string) $money, 2), 2, '');
        $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($cents > 100000000) throw new DomainException('单笔充值金额不能超过 1000000 元', 400);
        return $cents;
    }

    public static function decimal(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function result(callable $operation): array
    {
        try { return $operation(); }
        catch (DomainException $e) { return ['code' => $e->getCode() ?: 400, 'message' => $e->getMessage()]; }
        catch (Throwable $e) {
            // SDK exceptions may include merchant credentials, signatures and bodies.
            Log::error('支付操作失败，请检查商户配置及支付平台连接');
            return ['code' => 400, 'message' => '支付服务暂不可用，请稍后重试或联系管理员'];
        }
    }

    public function create(int $userId, $money, $method, string $ip): array
    {
        $cents = self::cents($money);
        if ($cents <= 0) throw new DomainException('充值金额必须大于 0', 400);
        if (!is_string($method) || !isset(PaymentGateway::methods()[$method])) throw new DomainException('此支付方式尚未启用，请使用可用的充值或兑换方式', 400);
        $money = self::decimal($cents);
        $user = (new UserModel())->find($userId);
        if (!$user || $user->authority < 0) throw new DomainException('请重新登录', 401);
        $rate = Db::name('config')->where('key', 'chargeRate')->value('value') ?? '1';
        if (!is_numeric($rate) || !is_finite((float) $rate) || (float) $rate < 0 || (float) $rate > 1000000) throw new DomainException('充值比例配置不正确', 400);
        $rate = sprintf('%.8F', (float) $rate);
        $creditCents = bcmul(bcmul($money, $rate, 10), '100', 8);
        $rounded = bcdiv($creditCents, '1', 0);
        if (bccomp($creditCents, $rounded, 8) > 0) $rounded = bcadd($rounded, '1', 0);
        $credit = self::decimal((int) $rounded);
        $record = new PayRecordModel();
        $record->save([
            'payCompleteKey' => bin2hex(random_bytes(16)), 'type' => 1, 'userId' => $userId,
            'tradeNo' => 'EC' . bin2hex(random_bytes(15)), 'name' => currencyName() . '充值', 'money' => $money, 'clientip' => $ip,
            'paymentGateway' => 'yansongda/pay', 'paymentMethod' => $method,
            'payRecordInfo' => ['commodity' => currencyName() . '充值', 'unit' => 'money', 'count' => $money,
                'credit' => $credit, 'chargeRate' => $rate, 'payMethod' => $method,
                'merchant' => $this->gateway->merchant($method), 'expiresAt' => time() + 1800],
        ]);
        // Save before requesting the provider: even an immediate callback can find the order.
        $url = $this->gateway->create($method, $record->tradeNo, $money, $record->name);
        Db::transaction(function () use ($record, $url) {
            $current = (new PayRecordModel())->where('id', $record->id)->lock(true)->find();
            $info = $current->payRecordInfo;
            $info['payUrl'] = $url;
            $current->payRecordInfo = $info;
            $current->save();
        });
        return ['code' => 200, 'message' => '请扫码完成支付', 'qrcodeUrl' => $url, 'payUrl' => $url, 'method' => $method, 'id' => (int) $record->id];
    }

    public function callback(string $method, Request $request): void
    {
        $receipt = $this->gateway->callback($method, $request);
        if (!$receipt['paid']) throw new DomainException('订单尚未支付成功', 400);
        $this->settle($method, $receipt);
    }

    public function check(int $userId, $id, bool $repay = false): array
    {
        if (!is_scalar($id) || !ctype_digit((string) $id)) throw new DomainException('订单不存在', 400);
        $record = (new PayRecordModel())->where('id', $id)->where('userId', $userId)->find();
        if (!$record) throw new DomainException('订单不存在', 400);
        if ((int) $record->type === 2) return ['code' => 200, 'message' => '订单已支付', 'paid' => true, 'action' => 'reload'];
        if ($record->paymentGateway !== 'yansongda/pay') throw new DomainException('旧支付订单仅保留记录，请重新发起充值；已付款未到账请联系管理员核对', 400);
        if ((int) $record->type !== 1) throw new DomainException('订单已取消', 400);
        $receipt = $this->gateway->query($record->paymentMethod, $record->tradeNo);
        if ($receipt['tradeNo'] !== $record->tradeNo) throw new DomainException('支付平台订单不匹配', 400);
        if ($receipt['paid']) {
            $this->settle($record->paymentMethod, $receipt);
            return ['code' => 200, 'message' => '订单已支付并到账', 'paid' => true, 'action' => 'reload'];
        }
        // A callback may have settled while the query was in flight.
        if ((int) (new PayRecordModel())->where('id', $id)->value('type') === 2) return ['code' => 200, 'message' => '订单已支付', 'paid' => true, 'action' => 'reload'];
        $info = $record->payRecordInfo;
        if (!$repay) return ['code' => 400, 'message' => '订单尚未支付', 'paid' => false];
        if (($info['expiresAt'] ?? 0) <= time()) throw new DomainException('支付二维码已过期，请重新发起充值', 400);
        if (empty($info['payUrl'])) throw new DomainException('暂无可用的支付二维码，请重新发起充值', 400);
        return ['code' => 200, 'message' => '订单尚未支付，请扫码支付', 'paid' => false, 'payUrl' => $info['payUrl'], 'method' => $record->paymentMethod];
    }

    private function settle(string $method, array $receipt): void
    {
        if (!is_string($receipt['tradeNo']) || !is_string($receipt['transactionId']) || $receipt['transactionId'] === '' || strlen($receipt['transactionId']) > 128) throw new DomainException('无效的支付交易信息', 400);
        $settled = Db::transaction(function () use ($method, $receipt) {
            $record = (new PayRecordModel())->where('tradeNo', $receipt['tradeNo'])->where('paymentGateway', 'yansongda/pay')->lock(true)->find();
            if (!$record) throw new DomainException('订单不存在', 400);
            $info = $record->payRecordInfo;
            if ($record->paymentMethod !== $method || self::cents($record->money) !== $receipt['cents'] || $receipt['currency'] !== 'CNY'
                || ($info['merchant'] ?? []) !== $receipt['merchant']) throw new DomainException('支付订单金额、币种或商户不匹配', 400);
            if ((int) $record->type === 2) {
                if ($record->providerTransactionId !== $receipt['transactionId']) throw new DomainException('支付交易号不匹配', 400);
                return null;
            }
            if ((int) $record->type !== 1) throw new DomainException('订单已取消', 400);
            $user = (new UserModel())->where('id', $record->userId)->lock(true)->find();
            if (!$user) throw new DomainException('订单账户不存在', 400);
            $credit = $info['credit'] ?? null;
            if (!is_string($credit) || !preg_match('/^\d+\.\d{2}$/', $credit)) throw new DomainException('订单到账金额无效', 400);
            $record->type = 2;
            $record->providerTransactionId = $receipt['transactionId'];
            $record->save();
            Db::name('user')->where('id', $user->id)->inc('rCoin', $credit)->update();
            $label = $method === 'alipay' ? '支付宝' : '微信支付';
            (new FinanceRecordModel())->save(['userId' => $user->id, 'action' => 1, 'count' => $credit,
                'recordInfo' => ['message' => '使用' . $label . '支付 ' . $record->money . ' 元，充值 ' . $credit . ' ' . currencyName(),
                    'payRecordId' => (int) $record->id, 'paidMoney' => $record->money, 'chargeRate' => $info['chargeRate'], 'transactionId' => $receipt['transactionId']]]);
            return ['userId' => (int) $user->id, 'money' => $record->money, 'credit' => $credit];
        });
        if ($settled !== null) $this->notify($settled);
    }

    private function notify(array $settled): void
    {
        try {
            $user = (new UserModel())->find($settled['userId']);
            $message = '您已支付 ' . $settled['money'] . ' 元，获得 ' . $settled['credit'] . ' ' . currencyNameHtml();
            try { sendTGMessage($settled['userId'], $message); } catch (Throwable $e) { Log::error('充值 Telegram 通知失败'); }
            $info = $user ? json_decode(json_encode($user->userInfo), true) : [];
            if ($user && $user->email && Config::get('mailer.enable') && empty($info['banEmail'])) {
                Queue::push('app\\api\\job\\SendMailMessage', ['to' => $user->email, 'subject' => '账单支付成功 - ' . Config::get('app.app_name'), 'content' => $message, 'isHtml' => true], 'main');
            }
        } catch (Throwable $e) {
            Log::error('充值通知失败');
        }
    }
}
