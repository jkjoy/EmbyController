<?php

namespace app\service;

use app\media\model\FinanceRecordModel;
use DomainException;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;
use Throwable;

/** Both the website and Telegram use the same daily reward transaction. */
class SignInService
{
    public const TOKEN_TTL = 300;

    /** Inclusive reward bounds in cents; zero maximum disables sign-in. */
    public static function rewardRange(): ?array
    {
        $values = Db::name('config')->whereIn('key', ['signInMinAmount', 'signInMaxAmount'])->column('value', 'key');
        $min = $values['signInMinAmount'] ?? 0;
        $max = $values['signInMaxAmount'] ?? 0;
        if (!is_numeric($min) || !is_numeric($max)) return null;
        $min = (float) $min;
        $max = (float) $max;
        if (!is_finite($min) || !is_finite($max) || $min < 0 || $max <= 0 || $max > 10 || $min > $max) return null;
        $minCents = (int) ceil(round($min * 100, 8));
        $maxCents = (int) floor(round($max * 100, 8));
        return $maxCents > 0 && $minCents <= $maxCents ? [$minCents, $maxCents] : null;
    }

    public static function issueToken(int $userId): array
    {
        return self::result(function () use ($userId) {
            self::requireEnabled();
            $user = self::requireUser($userId);
            self::requireUnsigned(self::userInfo($user));
            $token = bin2hex(random_bytes(32));
            if (!Cache::set(self::tokenKey($token), ['userId' => $userId, 'expiresAt' => time() + self::TOKEN_TTL], self::TOKEN_TTL)) {
                throw new DomainException('签到链接生成失败，请稍后重试', 400);
            }
            return ['code' => 200, 'message' => '签到链接已生成', 'token' => $token];
        });
    }

    /** GET only inspects a credential; the successful POST consumes it. */
    public static function inspectToken($token): array
    {
        return self::result(function () use ($token) {
            $credential = self::credential($token);
            self::requireEnabled();
            $user = self::requireUser($credential['userId']);
            $info = self::userInfo($user);
            self::requireUnusedToken($token, $info);
            self::requireUnsigned($info);
            return ['code' => 200, 'message' => '签到链接有效'];
        });
    }

    public static function claimToken($token): array
    {
        return self::result(function () use ($token) {
            $credential = self::credential($token);
            return self::claim($credential['userId'], $token);
        });
    }

    public static function claimUser(int $userId): array
    {
        return self::result(fn() => self::claim($userId));
    }

    private static function claim(int $userId, ?string $token = null): array
    {
        self::requireEnabled();
        $user = self::requireUser($userId);
        $info = self::userInfo($user);
        // Resolve the optional location before taking a database write lock.
        $ip = getRealIp();
        $location = null;
        if (!self::knownIp($info, $ip) && Config::get('map.enable') && Config::get('map.key') && Config::get('map.sk')) {
            $location = getLocation($ip);
        }

        Db::startTrans();
        try {
            $user = self::requireUser($userId, true);
            $info = self::userInfo($user);
            $range = self::requireEnabled();
            if ($token !== null) {
                // Re-read after the same-user lock: another request may have consumed it.
                $credential = self::credential($token);
                if ($credential['userId'] !== $userId) throw new DomainException('签到链接已失效', 400);
                self::requireUnusedToken($token, $info);
            }
            self::requireUnsigned($info);
            if (!self::knownIp($info, $ip) && !self::knownLocation($info, $location)) {
                throw new DomainException('环境异常，请使用登录过的 IP 或同城网络签到', 400);
            }

            $rewardCents = random_int($range[0], $range[1]);
            $reward = number_format($rewardCents / 100, 2, '.', '');
            $info['lastSignTime'] = date('Y-m-d');
            if ($token !== null) $info['lastSignTokenHash'] = hash('sha256', $token);
            Db::name('user')->where('id', $userId)->update([
                'userInfo' => json_encode($info, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'rCoin' => Db::raw('ROUND(rCoin + ' . $reward . ', 2)'),
                'updatedAt' => date('Y-m-d H:i:s'),
            ]);
            (new FinanceRecordModel())->save([
                'userId' => $userId, 'action' => 4, 'count' => $reward,
                'recordInfo' => ['message' => '签到获取' . $reward . currencyName()],
            ]);
            $balance = number_format((float) Db::name('user')->where('id', $userId)->value('rCoin'), 2, '.', '');
            Db::commit();
        } catch (Throwable $error) {
            Db::rollback();
            throw $error;
        }
        // The committed token hash also blocks replay if cache cleanup fails.
        if ($token !== null) {
            try { Cache::delete(self::tokenKey($token)); }
            catch (Throwable $error) { trace('签到凭据缓存清理失败', 'error'); }
        }
        if (function_exists('sendTGMessage')) {
            try { sendTGMessage($userId, '签到成功！今日签到获取' . $reward . currencyNameHtml()); }
            catch (Throwable $error) { trace('签到成功后的 Telegram 通知失败', 'error'); }
        }
        return ['code' => 200, 'message' => '签到成功！今日签到获取' . $reward . currencyName(), 'reward' => $reward, 'rCoin' => $balance];
    }

    private static function requireEnabled(): array
    {
        $range = self::rewardRange();
        if ($range === null) throw new DomainException('签到已关闭', 400);
        return $range;
    }

    private static function requireUser(int $userId, bool $lock = false): array
    {
        $query = Db::name('user')->where('id', $userId);
        if ($lock) $query->lock(true);
        $user = $userId > 0 ? $query->find() : null;
        if (!$user) throw new DomainException('用户信息不存在，请重新核对', 400);
        if ((int) $user['authority'] < 0) throw new DomainException('账号已禁用', 403);
        return $user;
    }

    private static function userInfo(array $user): array
    {
        $info = $user['userInfo'] ?? [];
        if (is_string($info)) $info = json_decode($info, true);
        elseif (is_object($info)) $info = json_decode(json_encode($info), true);
        return is_array($info) ? $info : [];
    }

    private static function requireUnsigned(array $info): void
    {
        if (($info['lastSignTime'] ?? null) === date('Y-m-d')) throw new DomainException('今日已签到，请明天再来', 400);
    }

    private static function credential($token): array
    {
        if (!is_string($token) || preg_match('/\A[0-9a-f]{64}\z/', $token) !== 1) throw new DomainException('签到链接已失效', 400);
        $credential = Cache::get(self::tokenKey($token));
        if (!is_array($credential) || !isset($credential['userId'], $credential['expiresAt'])
            || !is_int($credential['userId']) || !is_int($credential['expiresAt']) || $credential['expiresAt'] <= time()) {
            throw new DomainException('签到链接已失效', 400);
        }
        return $credential;
    }

    private static function requireUnusedToken(string $token, array $info): void
    {
        if (isset($info['lastSignTokenHash']) && is_string($info['lastSignTokenHash'])
            && hash_equals($info['lastSignTokenHash'], hash('sha256', $token))) throw new DomainException('签到链接已失效', 400);
    }

    private static function tokenKey(string $token): string
    {
        return 'sign_token_' . hash('sha256', $token);
    }

    private static function knownIp(array $info, string $ip): bool
    {
        return $ip !== '' && isset($info['loginIps']) && is_array($info['loginIps']) && in_array($ip, $info['loginIps'], true);
    }

    private static function knownLocation(array $info, ?array $location): bool
    {
        $previous = $info['lastLoginLocation'] ?? null;
        if (!is_array($previous) || !$location) return false;
        return !empty($previous['nation']) && !empty($previous['city']) && !empty($location['nation']) && !empty($location['city'])
            && $previous['nation'] === $location['nation'] && $previous['city'] === $location['city'];
    }

    private static function result(callable $operation): array
    {
        try { return $operation(); }
        catch (DomainException $error) { return ['code' => $error->getCode() ?: 400, 'message' => $error->getMessage()]; }
        catch (Throwable $error) {
            trace('签到操作失败', 'error');
            return ['code' => 400, 'message' => '签到失败，请稍后重试'];
        }
    }
}
