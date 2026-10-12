<?php

namespace app\service;

use DomainException;
use InvalidArgumentException;
use think\facade\Config;
use think\Request;
use Yansongda\Pay\Pay;

/** Official Alipay RSA2 and WeChat v3 APIs; SDK verifies all provider responses. */
class PaymentGateway
{
    public static function provider(string $method): string
    {
        return match ($method) {
            'alipay' => 'alipay', 'wxpay' => 'wechat',
            default => throw new DomainException('此支付方式尚未启用', 400),
        };
    }

    public static function validateConfig(string $method, array $config): void
    {
        if ($config === []) return;
        $required = $method === 'alipay'
            ? ['app_id', 'seller_id', 'app_secret_cert', 'app_public_cert_path', 'alipay_public_cert_path', 'alipay_root_cert_path']
            : ['mch_id', 'mp_app_id', 'mch_secret_key', 'mch_secret_cert', 'mch_public_cert_path', 'wechat_public_cert_path'];
        foreach ($required as $key) {
            if ($key === 'wechat_public_cert_path') {
                if (!isset($config[$key]) || !is_array($config[$key]) || $config[$key] === []) {
                    throw new InvalidArgumentException('微信支付配置需要平台证书/公钥 ID 到 PEM 或路径的映射');
                }
                foreach ($config[$key] as $serial => $cert) {
                    if ((!is_string($serial) && !is_int($serial)) || (string) $serial === '' || !is_string($cert) || trim($cert) === '') {
                        throw new InvalidArgumentException('微信支付平台证书/公钥配置不正确');
                    }
                }
            } elseif (!isset($config[$key]) || !is_string($config[$key]) || trim($config[$key]) === '') {
                throw new InvalidArgumentException('支付商户配置缺少 ' . $key . '（请填写字符串）');
            }
        }
        if ($method === 'wxpay' && strlen($config['mch_secret_key']) !== 32) {
            throw new InvalidArgumentException('微信 API v3 密钥需要 32 个字符');
        }
        if (isset($config['mode']) && !in_array($config['mode'], $method === 'alipay' ? [0, 1] : [0], true)) {
            throw new InvalidArgumentException('仅支持普通商户模式及支付宝沙箱模式');
        }
        foreach ($config as $key => $value) {
            if (!in_array($key, array_merge($required, ['mode']), true)) {
                throw new InvalidArgumentException('支付商户配置包含不支持的字段：' . $key);
            }
        }
    }

    public static function methods(): array
    {
        $methods = [];
        foreach (['alipay' => '支付宝', 'wxpay' => '微信支付'] as $method => $label) {
            $provider = self::provider($method);
            $config = Config::get('payment.' . $provider . '.config', []);
            if (Config::get('payment.' . $provider . '.enabled') && is_array($config) && $config !== []
                && Config::get('app.app_host', '') !== '') $methods[$method] = $label;
        }
        return $methods;
    }

    public function merchant(string $method): array
    {
        $config = $this->credentials($method);
        return $method === 'alipay'
            ? ['app_id' => $config['app_id'], 'seller_id' => $config['seller_id']]
            : ['appid' => $config['mp_app_id'], 'mchid' => $config['mch_id']];
    }

    protected function credentials(string $method): array
    {
        $config = Config::get('payment.' . self::provider($method) . '.config', []);
        if (!is_array($config) || $config === []) throw new DomainException('支付商户尚未配置', 400);
        self::validateConfig($method, $config);
        return $config;
    }

    protected function boot(string $method): void
    {
        $config = $this->credentials($method);
        $config['notify_url'] = rtrim(Config::get('app.app_host'), '/') . '/server/paymentNotify?method=' . $method;
        // Reset cached SDK configuration so key rotations take effect in long-lived workers.
        Pay::clear();
        Pay::config([
            self::provider($method) => ['default' => $config],
            'logger' => ['enable' => false],
            'http' => ['timeout' => 15, 'connect_timeout' => 5],
        ]);
    }

    public function create(string $method, string $tradeNo, string $money, string $subject): string
    {
        $this->boot($method);
        if ($method === 'alipay') {
            $result = Pay::alipay()->scan(['out_trade_no' => $tradeNo, 'total_amount' => $money, 'subject' => $subject, 'timeout_express' => '30m']);
            $url = $result->get('qr_code');
        } else {
            $result = Pay::wechat()->scan(['out_trade_no' => $tradeNo, 'description' => $subject,
                'time_expire' => date(DATE_RFC3339, time() + 1800), 'amount' => ['total' => PaymentService::cents($money), 'currency' => 'CNY']]);
            $url = $result->get('code_url');
        }
        if (!is_string($url) || !preg_match($method === 'alipay' ? '~^https://[^\s<>"\x27]+$~' : '~^weixin://[^\s<>"\x27]+$~', $url)) {
            throw new DomainException('支付平台未返回有效的二维码，请稍后重试', 400);
        }
        return $url;
    }

    public function query(string $method, string $tradeNo): array
    {
        $this->boot($method);
        $data = $method === 'alipay' ? Pay::alipay()->query(['out_trade_no' => $tradeNo])->all()
            : Pay::wechat()->query(['out_trade_no' => $tradeNo, '_action' => 'native'])->all();
        return $this->receipt($method, $data, true);
    }

    public function callback(string $method, Request $request): array
    {
        $this->boot($method);
        if ($method === 'alipay') {
            parse_str($request->getInput(), $params);
            foreach ($params as $key => $value) {
                if (str_starts_with($key, '_') || !is_string($value)) throw new DomainException('无效的支付通知参数', 400);
            }
            // Only the signed POST body is used; query parameters cannot override it.
            $data = Pay::alipay()->callback($params)->all();
        } else {
            $data = Pay::wechat()->callback(['body' => $request->getInput(), 'headers' => $request->header()])->all();
            if (($data['event_type'] ?? '') !== 'TRANSACTION.SUCCESS') throw new DomainException('无效的支付通知事件', 400);
            $data = $data['resource']['ciphertext'] ?? [];
            if (!is_array($data)) throw new DomainException('无效的支付通知内容', 400);
        }
        return $this->receipt($method, $data, false);
    }

    private function receipt(string $method, array $data, bool $query): array
    {
        $merchant = $method === 'alipay'
            ? ['app_id' => $data['app_id'] ?? ($query ? $this->merchant($method)['app_id'] : ''), 'seller_id' => $data['seller_id'] ?? ($query ? $this->merchant($method)['seller_id'] : '')]
            : ['appid' => $data['appid'] ?? '', 'mchid' => $data['mchid'] ?? ''];
        $paid = $method === 'alipay' ? in_array($data['trade_status'] ?? '', ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)
            : ($data['trade_state'] ?? '') === 'SUCCESS';
        $money = $method === 'alipay' ? ($data['total_amount'] ?? '') : ($data['amount']['total'] ?? null);
        $cents = $method === 'alipay' ? ($paid ? PaymentService::cents($money) : -1) : (is_int($money) ? $money : -1);
        return ['paid' => $paid, 'tradeNo' => $data['out_trade_no'] ?? '', 'transactionId' => $data[$method === 'alipay' ? 'trade_no' : 'transaction_id'] ?? '',
            'cents' => $cents, 'currency' => $method === 'alipay' ? 'CNY' : ($data['amount']['currency'] ?? ''), 'merchant' => $merchant];
    }

    public function success(string $method): \think\Response
    {
        $response = $method === 'alipay' ? Pay::alipay()->success() : Pay::wechat()->success();
        return response((string) $response->getBody(), $response->getStatusCode(), array_map(fn($values) => implode(', ', $values), $response->getHeaders()));
    }
}
