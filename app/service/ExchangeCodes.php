<?php

namespace app\service;

use app\media\model\ExchangeCodeModel;
use app\media\model\FinanceRecordModel;
use DomainException;
use think\facade\Config;
use think\facade\Db;
use Throwable;

class ExchangeCodes
{
    public static function generate(array $data): array
    {
        $mode = $data['mode'] ?? 'single';
        if (!in_array($mode, ['single', 'batch'], true)) {
            throw new DomainException('请选择正确的生成方式', 400);
        }
        $type = self::positiveInteger($data['exchangeType'] ?? null, 4, '请选择正确的兑换类型');
        $count = self::quantity($type, $data['exchangeCount'] ?? null);
        $number = $mode === 'single' ? 1 : self::positiveInteger($data['generateCount'] ?? null, 100, '批量数量必须为1至100的整数');
        $remark = $data['remark'] ?? '';
        if (!is_string($remark) || preg_match('/\A.{0,500}\z/us', $remark) !== 1) {
            throw new DomainException('备注不能超过500个字符', 400);
        }

        return Db::transaction(function () use ($type, $count, $number, $remark) {
            $codes = [];
            for ($i = 0; $i < $number; $i++) {
                do {
                    $code = strtoupper(bin2hex(random_bytes(16)));
                } while (Db::name('exchange_code')->where('code', $code)->count() > 0);
                $model = new ExchangeCodeModel();
                $model->save([
                    'code' => $code,
                    'exchangeType' => $type,
                    'exchangeCount' => $count,
                    'type' => 0,
                    'codeInfo' => ['remark' => $remark],
                ]);
                $codes[] = $code;
            }
            return ['codes' => $codes];
        });
    }

    public static function setDisabled(int $id, bool $disabled): void
    {
        if ($id <= 0) throw new DomainException('参数错误', 400);
        $target = $disabled ? -1 : 0;
        // 条件更新与兑换竞争，已使用的码永远不能被重新启用。
        $changed = Db::name('exchange_code')->where('id', $id)->where('type', $disabled ? 0 : -1)
            ->update(['type' => $target, 'updatedAt' => date('Y-m-d H:i:s')]);
        if ($changed === 1) return;
        $row = Db::name('exchange_code')->where('id', $id)->find();
        if (!$row) throw new DomainException('兑换码不存在', 404);
        if ((int) $row['type'] !== $target) throw new DomainException('已使用的兑换码无法修改状态', 400);
    }

    public static function redeem(int $userId, $code, ?array $allowedTypes = null): array
    {
        if ($userId <= 0) throw new DomainException('请先登录', 401);
        if (!is_string($code) || preg_match('/\A[A-Za-z0-9!#-]{1,64}\z/', trim($code)) !== 1) {
            throw new DomainException('请输入正确的兑换码', 400);
        }
        $code = trim($code);
        // 不改变历史发行码；有重复的历史码必须由管理员核实，不能任选一条消费。
        $matches = Db::name('exchange_code')->where('code', $code)->limit(2)->select()->toArray();
        if (count($matches) !== 1 || !hash_equals((string) $matches[0]['code'], $code) || (int) $matches[0]['type'] !== 0) {
            throw new DomainException('兑换码无效、已使用或已禁用', 400);
        }
        $exchange = $matches[0];
        $type = (int) $exchange['exchangeType'];
        self::checkType($type, $allowedTypes);
        self::quantity($type, $exchange['exchangeCount'], true);
        self::checkUser(Db::name('user')->where('id', $userId)->find());

        $emby = null;
        $policy = null;
        if ($type !== 4) {
            $emby = Db::name('emby_user')->where('userId', $userId)->find();
            self::checkMembership($emby);
            // 网络读取不占 SQLite 全站写锁，锁定后会重新校验账号与到期状态。
            $policy = self::readPolicy((string) $emby['embyId']);
        }

        $policyChanged = false;
        Db::startTrans();
        try {
            // 第一条事务语句就是条件写入，避免 SQLite 从读锁升级导致并发 SQLITE_BUSY。
            $claimed = Db::name('exchange_code')->where('id', $exchange['id'])->where('code', $code)->where('type', 0)
                ->update(['type' => 1, 'usedByUserId' => $userId, 'exchangeDate' => date('Y-m-d H:i:s'), 'updatedAt' => date('Y-m-d H:i:s')]);
            if ($claimed !== 1) throw new DomainException('兑换码无效、已使用或已禁用', 400);
            $currentCode = Db::name('exchange_code')->where('id', $exchange['id'])->find();
            if ((int) $currentCode['exchangeType'] !== $type) throw new DomainException('兑换码已变更，请重试', 400);
            $quantity = self::quantity($type, $currentCode['exchangeCount'], true);
            $user = Db::name('user')->where('id', $userId)->lock(true)->find();
            self::checkUser($user);
            $result = ['exchangeType' => $type, 'exchangeCount' => $quantity];

            if ($type === 4) {
                // quantity 是已校验的固定两位小数字面量；统一在 SQL 中按分舍入并原子累加。
                Db::name('user')->where('id', $userId)->update([
                    'rCoin' => Db::raw('ROUND(rCoin + ' . $quantity . ', 2)'),
                    'updatedAt' => date('Y-m-d H:i:s'),
                ]);
                $message = '使用兑换码' . $code . '充值' . $quantity . currencyName();
            } else {
                $currentEmby = Db::name('emby_user')->where('userId', $userId)->lock(true)->find();
                self::checkMembership($currentEmby);
                if ($currentEmby['id'] !== $emby['id'] || $currentEmby['embyId'] !== $emby['embyId']) {
                    throw new DomainException('Emby账号已变更，请重试', 400);
                }
                $seconds = ($type === 3 ? 2592000 : 86400) * (int) $quantity;
                $activateTo = date('Y-m-d H:i:s', max(time(), strtotime($currentEmby['activateTo'])) + $seconds);
                Db::name('emby_user')->where('id', $currentEmby['id'])->update([
                    'activateTo' => $activateTo, 'updatedAt' => date('Y-m-d H:i:s'),
                ]);
                $result['activateTo'] = $activateTo;
                $message = '使用兑换码' . $code . '续期Emby账号至' . $activateTo;
            }

            (new FinanceRecordModel())->save([
                'userId' => $userId, 'action' => 2, 'count' => $code,
                'recordInfo' => ['message' => $message, 'exchangeType' => $type, 'exchangeCount' => $quantity],
            ]);
            $result['rCoin'] = number_format((float) Db::name('user')->where('id', $userId)->value('rCoin'), 2, '.', '');
            if ($policy !== null && $policy['IsDisabled']) {
                self::writePolicy((string) $emby['embyId'], array_replace($policy, ['IsDisabled' => false]));
                $policyChanged = true;
            }
            Db::commit();
            return $result;
        } catch (Throwable $e) {
            // Emby 和 SQL 不支持共同提交；在仍持有锁时尽力恢复原策略，避免覆盖另一次续期。
            if ($policyChanged) {
                try { self::writePolicy((string) $emby['embyId'], $policy); }
                catch (Throwable $restoreError) { trace('兑换事务回滚时无法恢复Emby策略', 'error'); }
            }
            Db::rollback();
            throw $e;
        }
    }

    private static function checkType(int $type, ?array $allowedTypes): void
    {
        if (!in_array($type, [1, 2, 3, 4], true) || ($allowedTypes !== null && !in_array($type, $allowedTypes, true))) {
            throw new DomainException('兑换码类型不适用于此操作', 400);
        }
    }

    private static function checkUser(?array $user): void
    {
        if (!$user) throw new DomainException('请先登录', 401);
        if ((int) $user['authority'] < 0) throw new DomainException('账号已禁用', 403);
    }

    private static function checkMembership(?array $emby): void
    {
        if (!$emby || empty($emby['embyId'])) throw new DomainException('请先创建Emby账号', 400);
        if ($emby['activateTo'] === null) throw new DomainException('终身会员无需续期', 400);
        if (!is_string($emby['activateTo']) || strtotime($emby['activateTo']) === false) {
            throw new DomainException('账号到期时间异常，请联系管理员', 400);
        }
    }

    private static function positiveInteger($value, int $max, string $message): int
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/\A[0-9]+\z/', (string) $value) !== 1
            || strlen((string) $value) > 10 || (int) $value < 1 || (int) $value > $max) {
            throw new DomainException($message, 400);
        }
        return (int) $value;
    }

    private static function quantity(int $type, $value, bool $stored = false): string
    {
        if ($type !== 4) {
            if ($stored && (is_string($value) || is_float($value))) $value = preg_replace('/\.0+\z/', '', (string) $value);
            // 旧激活码一直按一天生效；保留旧码语义，同时新生成严格固定为一天。
            $max = $type === 3 ? 120 : ($type === 1 && !$stored ? 1 : 3650);
            $number = self::positiveInteger($value, $max, $type === 1 ? '激活码固定兑换1天' : '会员时长必须为有效的正整数');
            return (string) ($type === 1 ? 1 : $number);
        }
        if ((!is_string($value) && !is_int($value) && !is_float($value))
            || preg_match('/\A([0-9]{1,7})(?:\.([0-9]{1,2}))?\z/', (string) $value, $parts) !== 1) {
            throw new DomainException('金额必须大于0，最多两位小数且不超过1000000.00', 400);
        }
        $cents = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
        if ($cents < 1 || $cents > 100000000) throw new DomainException('金额必须为0.01至1000000.00', 400);
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private static function readPolicy(string $embyId): array
    {
        $response = self::requestEmby($embyId, null);
        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['Policy']) || !is_array($data['Policy'])
            || !array_key_exists('IsDisabled', $data['Policy']) || !is_bool($data['Policy']['IsDisabled'])) {
            throw new DomainException('无法读取Emby账号策略，请稍后重试', 400);
        }
        return $data['Policy'];
    }

    private static function writePolicy(string $embyId, array $policy): void
    {
        self::requestEmby($embyId, $policy);
    }

    private static function requestEmby(string $embyId, ?array $policy): string
    {
        $base = Config::get('media.urlBase', '');
        $key = Config::get('media.apiKey', '');
        if (!is_string($base) || !is_string($key) || $base === '' || $key === '') {
            throw new DomainException('Emby服务尚未配置，请联系管理员', 400);
        }
        $url = rtrim($base, '/') . '/Users/' . rawurlencode($embyId) . ($policy === null ? '' : '/Policy') . '?api_key=' . rawurlencode($key);
        $ch = curl_init($url);
        try {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            ]);
            if ($policy !== null) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($policy, JSON_THROW_ON_ERROR));
            }
            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($body === false || ($policy === null ? $status !== 200 : !in_array($status, [200, 204], true))) {
                throw new DomainException('Emby服务请求失败，兑换码未消耗，请稍后重试', 400);
            }
            return $body;
        } finally {
            curl_close($ch);
        }
    }
}
