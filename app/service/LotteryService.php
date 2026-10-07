<?php

namespace app\service;

use DomainException;
use think\facade\Db;
use Throwable;

class LotteryService
{
    /** All lottery mutations take the same short SQL lock; network calls happen after commit. */
    private static function transaction(callable $callback)
    {
        return Db::transaction(function () use ($callback) {
            if (Db::name('lottery_mutex')->where('id', 1)->inc('revision')->update() !== 1) {
                throw new \RuntimeException('抽奖数据库未升级，请先执行数据库迁移');
            }
            return $callback();
        });
    }

    public static function join(int $lotteryId, string $telegramId, ?string $chatId = null): array
    {
        self::telegramId($telegramId);
        return self::transaction(function () use ($lotteryId, $telegramId, $chatId) {
            $lottery = self::openLottery($lotteryId);
            self::checkChat($lottery, $chatId);
            $user = self::boundUser($telegramId);
            if (!$user) throw new DomainException('请先绑定有效的管理站账号，禁用或解绑账号不能参与抽奖', 400);
            if (Db::name('lottery_participant')->where('lotteryId', $lotteryId)->where('telegramId', $telegramId)->count()) {
                throw new DomainException('您已经参与过此次抽奖', 400);
            }
            $linkedIds = Db::name('telegram_user')->where('userId', $user['id'])->where('type', 1)->column('telegramId');
            if ($linkedIds && Db::name('lottery_participant')->where('lotteryId', $lotteryId)->whereIn('telegramId', $linkedIds)->count()) {
                throw new DomainException('您的管理站账号已经参与过此次抽奖', 400);
            }
            self::checkRequirements($lottery, $user);
            $id = Db::name('lottery_participant')->insertGetId([
                'lotteryId' => $lotteryId, 'telegramId' => $telegramId, 'status' => 0, 'createTime' => date('Y-m-d H:i:s'),
            ]);
            return ['lotteryId' => $lotteryId, 'participantId' => $id];
        });
    }

    public static function exit(int $lotteryId, string $telegramId, ?string $chatId = null): void
    {
        self::telegramId($telegramId);
        self::transaction(function () use ($lotteryId, $telegramId, $chatId) {
            $lottery = self::openLottery($lotteryId);
            self::checkChat($lottery, $chatId);
            if (Db::name('lottery_participant')->where('lotteryId', $lotteryId)->where('telegramId', $telegramId)->where('status', 0)->delete() !== 1) {
                throw new DomainException('您未参与当前进行中的抽奖', 400);
            }
        });
    }

    public static function startNext(string $chatId): array
    {
        if (preg_match('/\A-[1-9][0-9]{0,18}\z/', $chatId) !== 1) throw new DomainException('群组ID格式错误', 400);
        return self::transaction(function () use ($chatId) {
            if (Db::name('lottery')->where('chatId', $chatId)->whereIn('status', [1, 3])->count()) {
                throw new DomainException('当前已有进行中的抽奖，请先结束当前抽奖', 400);
            }
            $lottery = Db::name('lottery')->where('status', 0)->where('drawTime', '>', date('Y-m-d H:i:s'))->order('id')->find();
            if (!$lottery) throw new DomainException('当前没有未开始且尚未过期的抽奖', 400);
            self::prizes($lottery['prizes']);
            Db::name('lottery')->where('id', $lottery['id'])->update(['status' => 1, 'chatId' => $chatId]);
            return array_replace($lottery, ['status' => 1, 'chatId' => $chatId]);
        });
    }

    public static function createLottery(array $data): int
    {
        return self::transaction(function () use ($data) {
            $data = self::lotteryData($data);
            $data['status'] = empty($data['chatId']) ? 0 : 1;
            if ($data['status'] === 1) self::checkChatAvailable($data['chatId']);
            $data['createTime'] = date('Y-m-d H:i:s');
            return Db::name('lottery')->insertGetId($data);
        });
    }

    public static function updateLottery(int $id, array $data): void
    {
        self::transaction(function () use ($id, $data) {
            $lottery = self::editableLottery($id);
            $data = self::lotteryData($data);
            if ((int) $lottery['status'] === 1) {
                if (empty($data['chatId'])) throw new DomainException('进行中的抽奖必须填写群组ID', 400);
                self::checkChatAvailable($data['chatId'], $id);
            }
            Db::name('lottery')->where('id', $id)->update($data);
        });
    }

    public static function setStatus(int $id, bool $enabled): void
    {
        self::transaction(function () use ($id, $enabled) {
            $lottery = self::editableLottery($id);
            if ($enabled) {
                if (empty($lottery['chatId'])) throw new DomainException('请先填写群组ID，或在群内使用 /startlottery 开始抽奖', 400);
                if (strtotime($lottery['drawTime']) <= time()) throw new DomainException('开奖时间已过，请先编辑开奖时间', 400);
                self::prizes($lottery['prizes']);
                self::checkChatAvailable($lottery['chatId'], $id);
            }
            Db::name('lottery')->where('id', $id)->update(['status' => $enabled ? 1 : -1]);
        });
    }

    public static function drawDue(): array
    {
        $results = [];
        $ids = Db::name('lottery')->whereIn('status', [1, 3])->where('drawTime', '<=', date('Y-m-d H:i:s'))->order('id')->column('id');
        foreach ($ids as $id) {
            try { $results[$id] = self::draw((int) $id); }
            catch (Throwable $error) { $results[$id] = ['error' => $error->getMessage()]; }
        }
        return $results;
    }

    public static function draw(int $id): array
    {
        return self::transaction(function () use ($id) {
            $lottery = Db::name('lottery')->where('id', $id)->find();
            if (!$lottery || !in_array((int) $lottery['status'], [1, 3], true) || strtotime($lottery['drawTime']) > time()) {
                return ['drawn' => false, 'winners' => []];
            }
            $recovering = (int) $lottery['status'] === 3;
            Db::name('lottery')->where('id', $id)->update(['status' => 3]);
            $winners = Db::name('lottery_participant')->where('lotteryId', $id)->where('status', 1)->order('id')->select()->toArray();
            // 旧实现可能已发经验但没记录中奖。恢复时只保留已记录结果，避免重发任何旧奖励。
            if (!$recovering) {
                $prizes = self::prizes($lottery['prizes']);
                $participants = Db::name('lottery_participant')->where('lotteryId', $id)->where('status', 0)->select()->toArray();
                for ($position = count($participants) - 1; $position > 0; $position--) {
                    $other = random_int(0, $position);
                    [$participants[$position], $participants[$other]] = [$participants[$other], $participants[$position]];
                }
                $wonUsers = [];
                $usedSlots = [];
                foreach ($winners as $winner) {
                    $user = self::boundUser($winner['telegramId']);
                    if ($user) $wonUsers[(int) $user['id']] = true;
                    $savedPrize = json_decode($winner['prize'] ?? '', true) ?: [];
                    foreach ($prizes as $index => $prize) {
                        if (($savedPrize['name'] ?? null) === $prize['name']) {
                            $usedSlots[$index] = ($usedSlots[$index] ?? 0) + 1;
                            break;
                        }
                    }
                }
                foreach ($prizes as $index => $prize) {
                    for ($slot = $usedSlots[$index] ?? 0; $slot < $prize['count']; $slot++) {
                        $winner = null;
                        $user = null;
                        while ($participants) {
                            $candidate = array_pop($participants);
                            $candidateUser = self::boundUser($candidate['telegramId']);
                            if ($candidateUser && !isset($wonUsers[(int) $candidateUser['id']])) {
                                $winner = $candidate;
                                $user = $candidateUser;
                                break;
                            }
                        }
                        if (!$winner) break;
                        $content = $prize['contents'][$slot];
                        if (preg_match('/「Exp([0-9]+)」/u', $content, $matches)) {
                            $exp = min(100, (int) $matches[1]);
                            // authority=0 是管理员身份；正等级经验封顶100，不将管理员变成普通用户。
                            if ((int) $user['authority'] > 0) {
                                Db::name('user')->where('id', $user['id'])->update([
                                    'authority' => min(100, (int) $user['authority'] + $exp), 'updatedAt' => date('Y-m-d H:i:s'),
                                ]);
                            }
                        }
                        $savedPrize = ['name' => $prize['name'], 'content' => $content];
                        Db::name('lottery_participant')->where('id', $winner['id'])->update([
                            'status' => 1, 'prize' => json_encode($savedPrize, JSON_THROW_ON_ERROR),
                        ]);
                        $wonUsers[(int) $user['id']] = true;
                        $winners[] = array_replace($winner, ['status' => 1, 'prize' => json_encode($savedPrize, JSON_THROW_ON_ERROR)]);
                    }
                }
            }
            Db::name('lottery_participant')->where('lotteryId', $id)->where('status', 0)->update(['status' => 2]);
            self::enqueueResults($lottery, $winners, $recovering);
            Db::name('lottery')->where('id', $id)->update(['status' => 2]);
            return ['drawn' => true, 'winners' => $winners, 'recovered' => $recovering];
        });
    }

    /** Delivery is at least once: a process dying after Telegram accepts may retry the message, never the reward. */
    public static function deliverNotifications(callable $sender, int $limit = 100): array
    {
        $result = ['delivered' => 0, 'failed' => 0];
        $now = date('Y-m-d H:i:s');
        $pending = Db::name('lottery_notification')->whereNull('deliveredAt')->where('availableAt', '<=', $now)->order('id')->limit(max(1, min($limit, 1000)))->select()->toArray();
        foreach ($pending as $notification) {
            $lease = bin2hex(random_bytes(16));
            $claimed = Db::name('lottery_notification')->where('id', $notification['id'])->whereNull('deliveredAt')->where('availableAt', '<=', $now)
                ->update(['availableAt' => date('Y-m-d H:i:s', time() + 120), 'leaseToken' => $lease]);
            if ($claimed !== 1) continue;
            try {
                $sender(['chat_id' => $notification['chatId'], 'text' => json_decode($notification['message'], true, 512, JSON_THROW_ON_ERROR)]);
                Db::name('lottery_notification')->where('id', $notification['id'])->where('leaseToken', $lease)
                    ->update(['deliveredAt' => date('Y-m-d H:i:s'), 'leaseToken' => null, 'lastError' => null]);
                $result['delivered']++;
            } catch (Throwable $error) {
                $attempts = (int) $notification['attempts'] + 1;
                Db::name('lottery_notification')->where('id', $notification['id'])->where('leaseToken', $lease)->update([
                    'attempts' => $attempts, 'leaseToken' => null,
                    'lastError' => substr(json_encode($error->getMessage(), JSON_INVALID_UTF8_SUBSTITUTE), 0, 500),
                    'availableAt' => date('Y-m-d H:i:s', time() + min(3600, 30 * (2 ** min(7, $attempts - 1)))),
                ]);
                $result['failed']++;
            }
        }
        return $result;
    }

    private static function openLottery(int $id): array
    {
        $lottery = Db::name('lottery')->where('id', $id)->find();
        if (!$lottery) throw new DomainException('抽奖不存在', 404);
        if ((int) $lottery['status'] !== 1 || strtotime($lottery['drawTime']) <= time()) throw new DomainException('抽奖已截止或未开放报名', 400);
        return $lottery;
    }

    private static function editableLottery(int $id): array
    {
        $lottery = Db::name('lottery')->where('id', $id)->find();
        if (!$lottery) throw new DomainException('抽奖不存在', 404);
        if (!in_array((int) $lottery['status'], [-1, 0, 1], true)) throw new DomainException('已结束或开奖中的抽奖不能修改', 400);
        return $lottery;
    }

    private static function checkChat(array $lottery, ?string $chatId): void
    {
        if ($chatId !== null && (string) $lottery['chatId'] !== $chatId) {
            throw new DomainException('该抽奖不在当前群组中，无法报名或退出', 400);
        }
    }

    private static function boundUser(string $telegramId): ?array
    {
        $bindings = Db::name('telegram_user')->where('telegramId', $telegramId)->where('type', 1)->lock(true)->select()->toArray();
        $userIds = array_unique(array_map(fn($binding) => (int) $binding['userId'], $bindings));
        if (count($userIds) !== 1) return null;
        $user = Db::name('user')->where('id', reset($userIds))->lock(true)->find();
        return $user && (int) $user['authority'] >= 0 ? $user : null;
    }

    private static function checkRequirements(array $lottery, array $user): void
    {
        $description = (string) $lottery['description'];
        if (preg_match('/「LockTime-([0-9]+)h-([0-9]+)」/u', $description, $matches) && (int) $matches[1] > 0 && (int) $matches[2] > 0) {
            $hours = min(876000, (int) $matches[1]);
            $count = Db::name('media_history')->where('userId', $user['id'])
                ->where('createdAt', '>=', date('Y-m-d H:i:s', time() - $hours * 3600))->where('createdAt', '<=', date('Y-m-d H:i:s'))->count();
            if ($count < (int) $matches[2]) throw new DomainException('您在规定时间内的观影次数为' . $count . '次，未达到要求，无法参与抽奖', 400);
        }
        if (preg_match('/「LockExp-([0-9]+)」/u', $description, $matches) && (int) $user['authority'] !== 0 && (int) $user['authority'] < (int) $matches[1]) {
            throw new DomainException('您的Exp为' . $user['authority'] . '，未达到要求，无法参与抽奖', 400);
        }
    }

    private static function telegramId(string $id): void
    {
        if (preg_match('/\A[1-9][0-9]{0,19}\z/', $id) !== 1) throw new DomainException('Telegram账号ID格式错误', 400);
    }

    private static function checkChatAvailable(string $chatId, int $except = 0): void
    {
        if (Db::name('lottery')->where('chatId', $chatId)->where('id', '<>', $except)->whereIn('status', [1, 3])->count()) {
            throw new DomainException('该群组已有进行中的抽奖', 400);
        }
    }

    private static function lotteryData(array $data): array
    {
        $result = array_intersect_key($data, array_flip(['title', 'description', 'drawTime', 'keywords', 'prizes', 'chatId']));
        if (!is_string($result['title'] ?? null) || trim($result['title']) === '' || mb_strlen($result['title']) > 100
            || !is_string($result['description'] ?? null) || trim($result['description']) === '' || mb_strlen($result['description']) > 500) {
            throw new DomainException('标题或描述格式不正确', 400);
        }
        $drawTime = self::dateTimestamp($result['drawTime'] ?? null);
        if ($drawTime === null || $drawTime <= time()) {
            throw new DomainException('开奖时间必须大于当前时间', 400);
        }
        $result['drawTime'] = date('Y-m-d H:i:s', $drawTime);
        if (!is_string($result['keywords'] ?? '') || (!is_string($result['chatId'] ?? '') && !is_int($result['chatId'] ?? ''))) {
            throw new DomainException('关键词或群组ID格式错误', 400);
        }
        $result['keywords'] = trim((string) ($result['keywords'] ?? ''));
        if (mb_strlen($result['keywords']) > 100) throw new DomainException('报名关键词不能超过100个字符', 400);
        $result['chatId'] = trim((string) ($result['chatId'] ?? '')) ?: null;
        if ($result['chatId'] !== null && preg_match('/\A-[1-9][0-9]{0,18}\z/', $result['chatId']) !== 1) throw new DomainException('群组ID格式错误', 400);
        $result['prizes'] = json_encode(self::prizes($result['prizes'] ?? null), JSON_THROW_ON_ERROR);
        return $result;
    }

    private static function dateTimestamp($value): ?int
    {
        if (!is_string($value)) return null;
        foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date && $date->format($format) === $value) return $date->getTimestamp();
        }
        return null;
    }

    public static function prizes($value): array
    {
        $prizes = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($prizes) || !$prizes || count($prizes) > 100 || array_keys($prizes) !== range(0, count($prizes) - 1)) throw new DomainException('奖品须为1至100项的列表', 400);
        $total = 0;
        $names = [];
        foreach ($prizes as &$prize) {
            if (!is_array($prize) || !is_string($prize['name'] ?? null) || trim($prize['name']) === '' || mb_strlen($prize['name']) > 100
                || isset($names[$prize['name']]) || !is_int($prize['count'] ?? null) || $prize['count'] < 1 || $prize['count'] > 1000
                || !is_array($prize['contents'] ?? null) || count($prize['contents']) !== (int) $prize['count']) {
                throw new DomainException('奖品名称须唯一，数量须为正整数且与内容数量一致', 400);
            }
            $names[$prize['name']] = true;
            $prize['count'] = (int) $prize['count'];
            $total += $prize['count'];
            if ($total > 1000) throw new DomainException('单次抽奖奖品总数不能超过1000份', 400);
            $prize['contents'] = array_values($prize['contents']);
            foreach ($prize['contents'] as $content) {
                if (!is_string($content) || trim($content) === '' || mb_strlen($content) > 3000) throw new DomainException('奖品内容不能为空且不能超过3000个字符', 400);
            }
        }
        unset($prize);
        // MySQL现有prizes为TEXT（65535字节）；ASCII JSON转义可能放大中文/表情。
        if (strlen(json_encode($prizes, JSON_THROW_ON_ERROR)) > 60000) {
            throw new DomainException('奖品内容总量过大，编码后不能超过60000字节，请减少奖品数量或缩短内容', 400);
        }
        return $prizes;
    }

    private static function enqueueResults(array $lottery, array $winners, bool $recovering): void
    {
        $group = $recovering
            ? "旧开奖任务恢复通知\n\n「" . $lottery['title'] . "」已记录的中奖结果：\n\n"
            : '🎉 抽奖结果公布 🎉' . "\n\n「" . $lottery['title'] . "」开奖啦！\n\n";
        foreach ($winners as $winner) {
            $prize = json_decode($winner['prize'] ?? '', true) ?: [];
            $name = (string) ($prize['name'] ?? '历史奖品');
            $content = (string) ($prize['content'] ?? '请联系管理员核实奖品详情');
            self::enqueue((int) $lottery['id'], 'winner-' . $winner['id'], (string) $winner['telegramId'],
                "🎉 恭喜您！\n\n您在「" . $lottery['title'] . "」抽奖活动中获得了：\n🎁 " . $name . "\n\n奖品内容：" . $content . "\n\n请注意查收您的奖品！");
            $group .= '🎁 ' . $name . '：' . $winner['telegramId'] . "\n";
        }
        if (!$winners) $group .= "本次没有符合要求的中奖者。\n";
        else $group .= "\n奖品详情将私信通知，请注意查收。";
        if ($recovering) $group .= "\n\n本次为旧开奖任务恢复，仅公布已记录结果；未记录的历史奖励请管理员核实，系统不会重复发放经验。";
        if (!empty($lottery['chatId'])) self::enqueue((int) $lottery['id'], 'group', (string) $lottery['chatId'], $group);
    }

    private static function enqueue(int $lotteryId, string $key, string $chatId, string $message): void
    {
        $part = 0;
        while ($message !== '') {
            // Telegram限制4096字符，按3500字节分段也能安全容纳UTF-16代理对。
            $chunk = mb_strcut($message, 0, 3500, 'UTF-8');
            $message = substr($message, strlen($chunk));
            $notificationKey = $key . '-' . ++$part;
            if (!Db::name('lottery_notification')->where('lotteryId', $lotteryId)->where('notificationKey', $notificationKey)->count()) {
                Db::name('lottery_notification')->insert([
                    // ASCII JSON兼容历史MySQL utf8连接；发送前还原完整Unicode文本。
                    'lotteryId' => $lotteryId, 'notificationKey' => $notificationKey, 'chatId' => $chatId, 'message' => json_encode($chunk, JSON_THROW_ON_ERROR),
                    'availableAt' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }
}
