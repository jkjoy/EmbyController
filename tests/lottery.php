<?php
/** php tests/lottery.php: real migrations/services, disposable SQLite, fake delivery only. */
ini_set('display_errors', 'stderr');
require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';

use app\service\LotteryService;
use think\facade\Db;

function lotteryExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function lotteryError(callable $operation, string $message): void
{
    try { $operation(); }
    catch (DomainException $error) { lotteryExpect(str_contains($error->getMessage(), $message), 'Unexpected business error: ' . $error->getMessage()); return; }
    throw new RuntimeException('Expected business failure: ' . $message);
}

function lotteryApp(string $root, string $database): think\App
{
    $app = new think\App($root);
    $app->bind(require dirname(__DIR__) . '/app/provider.php');
    $app->setRuntimePath($root . '/runtime/');
    foreach (['app', 'cache', 'log'] as $name) {
        $config = require dirname(__DIR__) . '/config/' . $name . '.php';
        if ($name === 'cache') $config['stores']['file']['path'] = $root . '/runtime/cache/';
        $app->config->set($config, $name);
    }
    $config = require dirname(__DIR__) . '/config/database.php';
    $config['default'] = 'sqlite';
    $config['connections']['sqlite']['database'] = $database;
    $config['connections']['sqlite']['prefix'] = 'rc_';
    $app->config->set($config, 'database');
    think\Model::setDb($app->make('think\DbManager'));
    (new think\service\ModelService($app))->boot();
    return $app;
}

function lotteryProcess(array $arguments, string $root, ?array $environment = null): array
{
    $command = [PHP_BINARY];
    if (php_ini_loaded_file()) array_push($command, '-c', php_ini_loaded_file());
    $command = array_merge($command, ['-d', 'variables_order=EGPCS', '-d', 'opcache.enable_cli=0'], $arguments);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment, ['bypass_shell' => true]);
    lotteryExpect(is_resource($process), 'Cannot start lottery fixture');
    fclose($pipes[0]);
    return [$process, $pipes];
}

function lotteryResult(array $worker): string
{
    [$process, $pipes] = $worker;
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    lotteryExpect(proc_close($process) === 0, 'Fixture process failed: ' . $output . $errors);
    return $output;
}

function lotteryParallel(string $root, string $database, string $operation, int $id, string $telegramId = '200'): array
{
    $barrier = $root . '/parallel-' . bin2hex(random_bytes(3));
    mkdir($barrier);
    $workers = [];
    try {
        for ($worker = 0; $worker < 2; $worker++) {
            $workers[] = lotteryProcess([__FILE__, '--worker', $barrier, $database, $operation, (string) $id, $telegramId, (string) $worker], $root);
        }
        $deadline = microtime(true) + 20;
        while (count(glob($barrier . '/ready-*')) !== 2) {
            lotteryExpect(microtime(true) < $deadline, 'Parallel lottery barrier timeout');
            usleep(10000);
        }
        file_put_contents($barrier . '/go', 'go');
        return array_map(fn($worker) => json_decode(lotteryResult($worker), true, 512, JSON_THROW_ON_ERROR), $workers);
    } finally {
        foreach ($workers as [$process, $pipes]) {
            if (is_resource($process)) {
                proc_terminate($process);
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
                proc_close($process);
            }
        }
    }
}

function lotterySeed(array $overrides = []): int
{
    return Db::name('lottery')->insertGetId(array_replace([
        'title' => '回归抽奖', 'description' => '正常抽奖', 'drawTime' => date('Y-m-d H:i:s', time() + 3600), 'keywords' => '加入',
        'chatId' => '-10001', 'status' => 1, 'prizes' => json_encode([['name' => '经验', 'count' => 1, 'contents' => ['「Exp5」']]], JSON_UNESCAPED_UNICODE),
    ], $overrides));
}

function lotteryDue(int $id): void
{
    Db::name('lottery')->where('id', $id)->update(['drawTime' => date('Y-m-d H:i:s', time() - 1)]);
}

function lotteryRemove(string $path): void
{
    foreach (scandir($path) as $name) {
        if ($name === '.' || $name === '..') continue;
        $child = $path . '/' . $name;
        if (is_dir($child) && !is_link($child)) lotteryRemove($child);
        else unlink($child);
    }
    rmdir($path);
}

if (($argv[1] ?? '') === '--worker') {
    [, , $barrier, $database, $operation, $id, $telegramId, $worker] = $argv;
    mkdir($barrier . '/worker-' . $worker);
    lotteryApp($barrier . '/worker-' . $worker, $database);
    file_put_contents($barrier . '/ready-' . $worker, 'ready');
    $deadline = microtime(true) + 20;
    while (!is_file($barrier . '/go')) {
        lotteryExpect(microtime(true) < $deadline, 'Worker lottery barrier timeout');
        usleep(10000);
    }
    try {
        if ($operation === 'join') $result = ['ok' => true, 'result' => LotteryService::join((int) $id, $telegramId)];
        elseif ($operation === 'draw') $result = LotteryService::draw((int) $id);
        else $result = LotteryService::deliverNotifications(function ($parameters) use ($barrier) {
            file_put_contents($barrier . '/deliveries', json_encode($parameters) . "\n", FILE_APPEND | LOCK_EX);
            usleep(50000);
        });
    } catch (DomainException $error) { $result = ['ok' => false, 'error' => $error->getMessage()]; }
    echo json_encode($result, JSON_THROW_ON_ERROR);
    Db::connect()->close();
    exit;
}

$projectRoot = dirname(__DIR__);
$root = sys_get_temp_dir() . '/emby-lottery-' . bin2hex(random_bytes(8));
mkdir($root);
try {
    foreach (['app', 'config', 'database/migrations'] as $directory) mkdir($root . '/' . $directory, 0777, true);
    foreach (glob($projectRoot . '/config/*.php') as $file) file_put_contents($root . '/config/' . basename($file), '<?php return require ' . var_export($file, true) . ';');
    foreach (glob($projectRoot . '/database/migrations/*.php') as $file) copy($file, $root . '/database/migrations/' . basename($file));
    file_put_contents($root . '/app/service.php', '<?php return [\\think\\migration\\Service::class];');
    file_put_contents($root . '/app/provider.php', '<?php return require ' . var_export($projectRoot . '/app/provider.php', true) . ';');
    file_put_contents($root . '/think', '<?php require ' . var_export($projectRoot . '/vendor/autoload.php', true) . '; (new \\think\\App(__DIR__))->console->run();');
    $database = $root . '/data/lottery.sqlite';
    $environment = array_filter(getenv() ?: [], fn($key) => !preg_match('/^(DB_|APP_|EMBY_|TG_|MAIL_|REDIS_)/', $key), ARRAY_FILTER_USE_KEY);
    $environment = array_merge($environment, ['DB_DRIVER' => 'sqlite', 'DB_TYPE' => 'sqlite', 'DB_NAME' => $database, 'DB_PREFIX' => 'rc_']);
    lotteryResult(lotteryProcess([$root . '/think', 'migrate:run', '--target=20261007000000'], $root, $environment));
    $pdo = new PDO('sqlite:' . $database);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("INSERT INTO rc_lottery_participant (id,lotteryId,telegramId,status,prize) VALUES (10,999,'999',0,NULL), (11,999,'999',1,'{\"name\":\"保留中奖\",\"content\":\"历史礼品\"}'), (12,999,'999',1,'{\"name\":\"另一个旧中奖\",\"content\":\"已发奖励\"}')");
    $longHistoricalPrize = json_encode(['name' => '另一个旧中奖', 'content' => str_repeat('历史', 6000)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $historicalUpdate = $pdo->prepare('UPDATE rc_lottery_participant SET prize = ? WHERE id = 12');
    $historicalUpdate->execute([$longHistoricalPrize]);
    lotteryResult(lotteryProcess([$root . '/think', 'migrate:run'], $root, $environment));
    $app = lotteryApp($root, $database);
    $kept = Db::name('lottery_participant')->where('lotteryId', 999)->find();
    lotteryExpect((int) $kept['id'] === 11 && (int) $kept['status'] === 1, 'Migration failed to preserve winning duplicate');
    $archive = Db::name('lottery_participant_archive')->order('originalId')->select()->toArray();
    lotteryExpect(count($archive) === 2 && (int) $archive[0]['originalId'] === 10 && (int) $archive[1]['originalId'] === 12
        && strlen($archive[1]['participantData']) > 65535
        && json_decode($archive[1]['participantData'], true)['prize'] === $longHistoricalPrize, 'Migration lost expanded duplicate original data');
    try { $pdo->exec("INSERT INTO rc_lottery_participant (lotteryId,telegramId) VALUES (999,'999')"); throw new RuntimeException('Missing unique enrollment index'); }
    catch (PDOException $error) { lotteryExpect($error->getCode() === '23000', 'Unexpected unique constraint error'); }
    $pdo = null;
    Db::connect()->close();
    lotteryResult(lotteryProcess([$root . '/think', 'migrate:rollback', '--target=20261007000000'], $root, $environment));
    lotteryExpect(Db::name('lottery_participant')->where('lotteryId', 999)->count() === 3
        && Db::name('lottery_participant')->where('id', 12)->value('prize') === $longHistoricalPrize, 'Migration rollback lost historical duplicates');
    Db::connect()->close();
    lotteryResult(lotteryProcess([$root . '/think', 'migrate:run'], $root, $environment));
    lotteryExpect(Db::name('lottery_participant')->where('lotteryId', 999)->count() === 1 && Db::name('lottery_participant_archive')->count() === 2, 'Migration reapply duplicated archives');
    echo "PASS: migration keeps historical winner, archives duplicates, restores on rollback and enforces unique enrollment\n";

    foreach ([2 => 10, 3 => -1, 4 => 5, 5 => 95, 6 => 10, 7 => 10, 8 => 10] as $id => $authority) {
        Db::name('user')->insert(['id' => $id, 'userName' => 'fixture' . $id, 'password' => 'unused', 'authority' => $authority]);
        Db::name('telegram_user')->insert(['userId' => $id, 'telegramId' => (string) ($id * 100), 'type' => $id === 7 ? 2 : 1]);
    }
    Db::name('telegram_user')->insertAll([['userId' => 1, 'telegramId' => '100', 'type' => 1], ['userId' => 2, 'telegramId' => '201', 'type' => 1]]);
    $id = lotterySeed(['description' => '要求「LockTime-24h-2」和「LockExp-8」']);
    for ($i = 0; $i < 3; $i++) Db::name('media_history')->insert(['userId' => 2, 'createdAt' => date('Y-m-d H:i:s', time() - 172800)]);
    Db::name('media_history')->insert(['userId' => 2, 'createdAt' => date('Y-m-d H:i:s', time() - 120)]);
    lotteryError(fn() => LotteryService::join($id, '200'), '观影次数为1次');
    Db::name('media_history')->insert(['userId' => 2, 'createdAt' => date('Y-m-d H:i:s', time() - 60)]);
    lotteryError(fn() => LotteryService::join($id, '200', '-other-chat'), '不在当前群组');
    lotteryExpect(Db::name('lottery_participant')->where('lotteryId', $id)->count() === 0, 'Wrong-chat enrollment mutated participants');
    LotteryService::join($id, '200', '-10001');
    lotteryError(fn() => LotteryService::exit($id, '200', '-other-chat'), '不在当前群组');
    lotteryExpect(Db::name('lottery_participant')->where('lotteryId', $id)->count() === 1, 'Wrong-chat exit deleted participant');
    lotteryError(fn() => LotteryService::join($id, '200'), '已经参与');
    lotteryError(fn() => LotteryService::join($id, '201'), '管理站账号已经参与');
    lotteryError(fn() => LotteryService::join($id, '300'), '禁用或解绑');
    lotteryError(fn() => LotteryService::join($id, '700'), '禁用或解绑');
    lotteryError(fn() => LotteryService::join($id, '9999'), '绑定');
    LotteryService::exit($id, '200');
    lotteryError(fn() => LotteryService::exit($id, '200'), '未参与');
    LotteryService::join($id, '200');
    $exp = lotterySeed(['description' => '要求「LockExp-8」']);
    lotteryError(fn() => LotteryService::join($exp, '400'), 'Exp为5');
    LotteryService::join($exp, '100');
    lotteryDue($id);
    lotteryError(fn() => LotteryService::join($id, '600'), '截止');
    lotteryError(fn() => LotteryService::exit($id, '200'), '截止');
    echo "PASS: recent viewing requirement, Exp, active binding, banned users, duplicate account, exit and deadline\n";

    $valid = ['title' => '新抽奖', 'description' => '奖品描述', 'drawTime' => date('Y-m-d\TH:i', time() + 7200), 'keywords' => '', 'chatId' => '-10009', 'prizes' => [['name' => '礼品', 'count' => 1, 'contents' => ['0']]]];
    $mediumAdapter = new Phinx\Db\Adapter\MysqlAdapter([]);
    lotteryExpect($mediumAdapter->getSqlType('text', Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM)['name'] === 'mediumtext', 'Archive limit does not generate MySQL MEDIUMTEXT');
    $withinTextLimit = [['name' => '礼品', 'count' => 3, 'contents' => array_fill(0, 3, str_repeat('奖', 3000))]];
    lotteryExpect(strlen(json_encode(LotteryService::prizes($withinTextLimit), JSON_THROW_ON_ERROR)) < 60000, 'Valid below-TEXT-cap prizes rejected');
    $overTextLimit = [['name' => '礼品', 'count' => 4, 'contents' => array_fill(0, 4, str_repeat('奖', 3000))]];
    lotteryExpect(strlen(json_encode($overTextLimit, JSON_THROW_ON_ERROR)) > 65535, 'TEXT overflow fixture is too small');
    lotteryError(fn() => LotteryService::prizes($overTextLimit), '不能超过60000字节');
    $beforeOversize = Db::name('lottery')->count();
    lotteryError(fn() => LotteryService::createLottery(array_replace($valid, ['chatId' => '-10008', 'prizes' => $overTextLimit])), '不能超过60000字节');
    lotteryExpect(Db::name('lottery')->count() === $beforeOversize, 'Oversized prize creation partially wrote lottery');
    $new = LotteryService::createLottery($valid);
    lotteryError(fn() => LotteryService::createLottery($valid), '已有进行');
    LotteryService::setStatus($new, false);
    LotteryService::setStatus($new, true);
    foreach ([['count' => 1.5], ['count' => '1'], ['contents' => [' ']], ['count' => 0], ['count' => 1001]] as $invalid) {
        lotteryError(fn() => LotteryService::createLottery(array_replace($valid, ['chatId' => '-10010', 'prizes' => [array_replace($valid['prizes'][0], $invalid)]])), '奖品');
    }
    foreach (['2026-02-30 15:00:00', 'tomorrow', '2099-13-01 00:00:00'] as $date) lotteryError(fn() => LotteryService::updateLottery($new, array_replace($valid, ['drawTime' => $date])), '开奖时间');
    LotteryService::updateLottery($new, array_replace($valid, ['title' => '已更新', 'prizes' => [['name' => '两份', 'count' => 2, 'contents' => ['A', 'B']]]]));
    lotteryExpect(json_decode(Db::name('lottery')->where('id', $new)->value('prizes'), true)[0]['count'] === 2, 'Edit lost prize count');
    $notStarted = LotteryService::createLottery(array_replace($valid, ['chatId' => '']));
    $started = LotteryService::startNext('-10011');
    lotteryExpect((int) $started['id'] === $notStarted && $started['chatId'] === '-10011' && (int) $started['status'] === 1, 'Could not start upcoming lottery without keyword');
    lotteryError(fn() => LotteryService::startNext('-10011'), '已有进行');
    echo "PASS: create/edit/start/disable serialized, prizes/date validation and no-keyword lottery\n";

    $parallel = lotterySeed(['chatId' => '-10020']);
    $joined = lotteryParallel($root, $database, 'join', $parallel);
    lotteryExpect(count(array_filter($joined, fn($row) => $row['ok'])) === 1 && Db::name('lottery_participant')->where('lotteryId', $parallel)->count() === 1, 'Concurrent join duplicated participation');
    lotteryDue($parallel);
    $beforeExp = (int) Db::name('user')->where('id', 2)->value('authority');
    $drawn = lotteryParallel($root, $database, 'draw', $parallel);
    lotteryExpect(count(array_filter($drawn, fn($row) => $row['drawn'])) === 1 && (int) Db::name('user')->where('id', 2)->value('authority') === $beforeExp + 5, 'Concurrent draw duplicated or lost Exp');
    lotteryExpect(Db::name('lottery_notification')->where('lotteryId', $parallel)->count() === 2, 'Concurrent draw duplicated notifications');
    LotteryService::draw($parallel);
    lotteryError(fn() => LotteryService::updateLottery($parallel, $valid), '不能修改');
    lotteryError(fn() => LotteryService::setStatus($parallel, true), '不能修改');
    echo "PASS: simultaneous enrollments and draws are idempotent and completed lottery is immutable\n";

    $rollback = lotterySeed(['chatId' => '-10021']);
    LotteryService::join($rollback, '600');
    lotteryDue($rollback);
    Db::execute('CREATE TEMP TRIGGER lottery_abort_end BEFORE UPDATE ON rc_lottery WHEN NEW.id = ' . $rollback . " AND NEW.status = 2 BEGIN SELECT RAISE(ABORT, 'fixture final status failure'); END");
    try { LotteryService::draw($rollback); throw new RuntimeException('Draw failed to surface transaction error'); }
    catch (think\db\exception\PDOException $error) { lotteryExpect(str_contains($error->getMessage(), 'fixture final status failure'), 'Unexpected draw SQL error'); }
    lotteryExpect((int) Db::name('lottery')->where('id', $rollback)->value('status') === 1 && (int) Db::name('user')->where('id', 6)->value('authority') === 10
        && (int) Db::name('lottery_participant')->where('lotteryId', $rollback)->value('status') === 0 && Db::name('lottery_notification')->where('lotteryId', $rollback)->count() === 0, 'Failure left partial claim/reward/notification');
    Db::execute('DROP TRIGGER lottery_abort_end');
    LotteryService::draw($rollback);
    lotteryExpect((int) Db::name('user')->where('id', 6)->value('authority') === 15, 'Failed draw could not retry');
    echo "PASS: final-write failure rolls back claim, Exp, results and outbox; retry grants once\n";

    $filtered = lotterySeed(['chatId' => '-10022', 'prizes' => json_encode([['name' => '经验', 'count' => 5, 'contents' => array_fill(0, 5, '「Exp20」')]], JSON_UNESCAPED_UNICODE)]);
    foreach (['100', '200', '201', '300', '500', '700', '8888'] as $telegramId) {
        Db::name('lottery_participant')->insert(['lotteryId' => $filtered, 'telegramId' => $telegramId, 'status' => 0]);
    }
    lotteryDue($filtered);
    $result = LotteryService::draw($filtered);
    $ids = array_column($result['winners'], 'telegramId');
    lotteryExpect(count($ids) === 3 && in_array('100', $ids, true) && in_array('500', $ids, true) && (in_array('200', $ids, true) xor in_array('201', $ids, true)), 'Draw skipped eligible replacement or granted duplicate account');
    lotteryExpect((int) Db::name('user')->where('id', 1)->value('authority') === 0 && (int) Db::name('user')->where('id', 5)->value('authority') === 100
        && (int) Db::name('user')->where('id', 3)->value('authority') === -1, 'Draw altered admin/banned identity or exceeded Exp cap');
    echo "PASS: ineligible participants replaced until eligible pool exhausted, one prize per account, Exp cap and admin identity\n";

    $legacy = lotterySeed(['chatId' => '-10023', 'status' => 3, 'prizes' => 'broken old json']);
    Db::name('lottery_participant')->insertAll([
        ['lotteryId' => $legacy, 'telegramId' => '600', 'status' => 1, 'prize' => '{"name":"历史经验","content":"「Exp50」"}'],
        ['lotteryId' => $legacy, 'telegramId' => '800', 'status' => 0, 'prize' => null],
    ]);
    lotteryDue($legacy);
    $legacyExp = (int) Db::name('user')->where('id', 6)->value('authority');
    $legacyResult = LotteryService::draw($legacy);
    lotteryExpect($legacyResult['recovered'] && count($legacyResult['winners']) === 1 && (int) Db::name('user')->where('id', 6)->value('authority') === $legacyExp
        && (int) Db::name('user')->where('id', 8)->value('authority') === 10 && (int) Db::name('lottery')->where('id', $legacy)->value('status') === 2, 'Legacy recovery duplicated rewards or lost winner');
    lotteryExpect(str_contains(json_decode(Db::name('lottery_notification')->where('lotteryId', $legacy)->where('notificationKey', 'group-1')->value('message'), true), '管理员核实'), 'Legacy recovery hides ambiguous historical rewards');
    echo "PASS: interrupted historical draws retain results and request manual review without reissuing Exp\n";

    $snapshot = Db::name('user')->order('id')->column('authority', 'id');
    $sent = [];
    $delivery = LotteryService::deliverNotifications(function ($parameters) use (&$sent) {
        $sent[] = $parameters;
        lotteryExpect((int) Db::name('lottery')->where('chatId', $parameters['chat_id'])->where('status', 3)->count() === 0, 'Notification ran inside unfinished draw');
        if ($parameters['chat_id'] === '600') throw new RuntimeException('fixture unavailable Telegram 🎉');
    }, 1000);
    lotteryExpect($delivery['failed'] > 0 && $delivery['delivered'] > 0, 'Notification failure was lost');
    foreach (Db::name('lottery_notification')->whereNull('deliveredAt')->select()->toArray() as $pending) {
        lotteryExpect(preg_match('/\A[\x00-\x7f]*\z/', $pending['lastError']) === 1, 'Notification error was not safe for legacy MySQL utf8');
    }
    lotteryExpect(Db::name('user')->order('id')->column('authority', 'id') === $snapshot, 'Telegram failure changed rewards');
    lotteryExpect(LotteryService::deliverNotifications(function () { throw new RuntimeException('should be delayed'); }) === ['delivered' => 0, 'failed' => 0], 'Notification backoff failed');
    Db::name('lottery_notification')->whereNull('deliveredAt')->update(['availableAt' => date('Y-m-d H:i:s', time() - 1)]);
    $retried = LotteryService::deliverNotifications(function ($parameters) use (&$sent) { $sent[] = $parameters; });
    lotteryExpect($retried['delivered'] === $delivery['failed'] && $retried['failed'] === 0 && Db::name('lottery_notification')->whereNull('deliveredAt')->count() === 0, 'Persistent notification retry failed');
    lotteryExpect(Db::name('user')->order('id')->column('authority', 'id') === $snapshot, 'Notification retry reissued Exp');
    echo "PASS: notification failure preserves awards, persistent backoff retry delivers, no replayed Exp\n";

    $large = lotterySeed(['chatId' => '-10024', 'prizes' => json_encode([['name' => '<含HTML字符>', 'count' => 1, 'contents' => [str_repeat('奖', 3000)]]], JSON_UNESCAPED_UNICODE)]);
    LotteryService::join($large, '800');
    lotteryDue($large);
    LotteryService::draw($large);
    $parts = array_map(fn($message) => json_decode($message, true, 512, JSON_THROW_ON_ERROR), Db::name('lottery_notification')->where('lotteryId', $large)->where('chatId', '800')->order('id')->column('message'));
    lotteryExpect(count($parts) > 1 && str_contains(implode('', $parts), str_repeat('奖', 3000)), 'Long Unicode prize notification truncated');
    foreach ($parts as $part) lotteryExpect(strlen($part) <= 3500 && mb_check_encoding($part, 'UTF-8'), 'Invalid Telegram notification segment');
    $deliveries = lotteryParallel($root, $database, 'deliver', $large);
    lotteryExpect(array_sum(array_column($deliveries, 'delivered')) === count($parts) + 1 && array_sum(array_column($deliveries, 'failed')) === 0, 'Parallel senders duplicated notification lease');
    Db::name('lottery_notification')->insert(['lotteryId' => $large, 'notificationKey' => 'stale-lease', 'chatId' => '800', 'message' => json_encode('expired worker lease'), 'leaseToken' => 'old', 'availableAt' => date('Y-m-d H:i:s', time() - 1)]);
    lotteryExpect(LotteryService::deliverNotifications(fn($parameters) => null)['delivered'] === 1, 'Expired notification lease could not recover');
    echo "PASS: Unicode notification splitting, concurrent sender lease and crash lease recovery\n";
    $invalidDue = lotterySeed(['chatId' => '-10025', 'prizes' => '{bad prizes}']);
    $emptyDue = lotterySeed(['chatId' => '-10026']);
    lotteryDue($invalidDue);
    lotteryDue($emptyDue);
    $schedule = LotteryService::drawDue();
    lotteryExpect(isset($schedule[$invalidDue]['error']) && (int) Db::name('lottery')->where('id', $invalidDue)->value('status') === 1
        && $schedule[$emptyDue]['drawn'] && !$schedule[$emptyDue]['winners'] && (int) Db::name('lottery')->where('id', $emptyDue)->value('status') === 2
        && Db::name('lottery_notification')->where('lotteryId', $emptyDue)->count() === 1 && !isset($schedule[$new]), 'Scheduler stopped on failed draw, claimed invalid row, or drew before deadline');
    echo "PASS: due scheduler isolates failures, keeps failed lottery retryable and completes zero-participant draw\n";
    echo "LOTTERY_REGRESSION_OK\n";
} finally {
    if (isset($app)) Db::connect()->close();
    lotteryRemove($root);
}
