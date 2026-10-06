<?php
/**
 * Run: php tests/sqlite_business.php
 * Real controller/ORM regressions using a disposable SQLite database, never deployment data.
 */
$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';
require_once $projectRoot . '/vendor/topthink/framework/src/helper.php';
require_once $projectRoot . '/app/common.php';
require_once $projectRoot . '/app/media/common.php';

use app\api\model\LotteryParticipantModel;
use app\media\controller\Server;
use app\media\controller\User;
use app\media\model\UserModel;
use think\facade\Db;
use think\facade\Session;

function expectBusiness(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fixtureApp(string $root, string $database): think\App
{
    $app = new think\App($root);
    $app->bind(require dirname(__DIR__) . '/app/provider.php');
    $app->setRuntimePath($root . '/runtime/');
    foreach (['app', 'cache', 'session', 'cookie', 'log', 'telegram', 'map', 'apiinfo', 'database'] as $name) {
        $config = require dirname(__DIR__) . '/config/' . $name . '.php';
        if ($name === 'cache') {
            $config['stores']['file']['path'] = $root . '/runtime/cache/';
        } elseif ($name === 'session') {
            $config['path'] = $root . '/runtime/session/';
        } elseif ($name === 'telegram') {
            $config['botConfig']['bots']['randallanjie_bot']['token'] = '';
        } elseif ($name === 'database') {
            $connection = $config['connections']['sqlite'] ?? ['type' => 'sqlite'];
            $connection['database'] = $database;
            $connection['prefix'] = 'rc_';
            $config['default'] = 'sqlite';
            $config['connections'] = ['sqlite' => $connection];
        }
        $app->config->set($config, $name);
    }
    // This fixture deliberately skips AppInit and service discovery, so bind models explicitly.
    think\Model::setDb($app->make('think\DbManager'));
    (new think\service\ModelService($app))->boot();
    return $app;
}

function businessRequest(think\App $app, array $data = []): void
{
    $_GET = $_COOKIE = [];
    $_POST = $_REQUEST = $data;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_HOST'] = 'sqlite.test';
    $_SERVER['REQUEST_URI'] = '/media/user/getNotifications';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $request = app\Request::__make($app);
    $request->setMethod('POST');
    $app->instance('request', $request);
    $app->instance('think\Request', $request);
    $request->withSession($app->session);
}

function responseData(think\Response $response): array
{
    return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
}

function concurrentResults(string $root, string $database, string $mode): array
{
    $barrierRoot = $root . '/concurrent-' . $mode;
    mkdir($barrierRoot);
    $workers = [];
    try {
        for ($id = 1; $id <= 3; $id++) {
            $process = proc_open([PHP_BINARY, __FILE__, '--' . $mode . '-worker', $barrierRoot, $database, (string) $id], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            expectBusiness(is_resource($process), 'Failed to start concurrency worker');
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 15;
        while (count(glob($barrierRoot . '/ready-*')) < 3) {
            expectBusiness(microtime(true) < $deadline, 'Workers did not reach concurrency barrier');
            usleep(10000);
        }
        file_put_contents($barrierRoot . '/go', 'go');
        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            expectBusiness(proc_close($process) === 0, 'Concurrency worker failed: ' . $errors);
            $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        foreach ($workers as [$process, $pipes]) {
            if (is_resource($process)) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) fclose($pipe);
                }
                proc_close($process);
            }
        }
    }
}

// Subprocesses share only the temporary database; each gets an independent session/connection.
if (in_array($argv[1] ?? '', ['--renew-worker', '--sign-worker'], true)) {
    [$script, $mode, $root, $database, $workerId] = $argv;
    $workerRoot = $root . '/worker-' . $workerId;
    mkdir($workerRoot);
    $app = fixtureApp($workerRoot, $database);
    businessRequest($app);
    Session::set('r_user', (new UserModel())->find(1));
    file_put_contents($root . '/ready-' . $workerId, 'ready');
    $deadline = microtime(true) + 15;
    while (!is_file($root . '/go')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for concurrency barrier');
        }
        usleep(10000);
    }
    $response = $mode === '--sign-worker' ? (new User($app))->sign() : (new Server($app))->continueSubscribeEmbyUserByBalance();
    echo json_encode(responseData($response), JSON_THROW_ON_ERROR);
    exit;
}

$testRoot = sys_get_temp_dir() . '/emby-sqlite-business-' . bin2hex(random_bytes(8));
mkdir($testRoot);
$database = $testRoot . '/business.sqlite';
// The application connector opens only initialized files; fixture schema creation is explicit.
$bootstrap = new PDO('sqlite:' . $database);
$bootstrap = null;
$app = fixtureApp($testRoot, $database);
try {
    foreach ([
        'rc_user' => 'id INTEGER PRIMARY KEY, createdAt TEXT, updatedAt TEXT, userName TEXT, nickName TEXT, password TEXT, authority INTEGER, email TEXT, rCoin REAL, userInfo TEXT',
        'rc_notification' => 'id INTEGER PRIMARY KEY, createdAt TEXT, updatedAt TEXT, type INTEGER, readStatus INTEGER, fromUserId INTEGER, toUserId INTEGER, message TEXT, notificationInfo TEXT',
        'rc_media_info' => 'id INTEGER PRIMARY KEY, mediaName TEXT, mediaYear TEXT, mediaType INTEGER, mediaMainId TEXT',
        'rc_media_comment' => 'id INTEGER PRIMARY KEY, createdAt TEXT, updatedAt TEXT, userId INTEGER, mediaId INTEGER, rating REAL, comment TEXT',
        'rc_lottery_participant' => 'id INTEGER PRIMARY KEY, lotteryId INTEGER, telegramId TEXT, status INTEGER, prize TEXT, createTime TEXT',
        'rc_emby_user' => 'id INTEGER PRIMARY KEY, createdAt TIMESTAMP, updatedAt TIMESTAMP, activateTo TIMESTAMP, userId INTEGER, embyId TEXT, userInfo TEXT',
        'rc_finance_record' => 'id INTEGER PRIMARY KEY, createdAt TEXT, updatedAt TEXT, userId INTEGER, action INTEGER, count TEXT, recordInfo TEXT',
        'rc_exchange_code' => 'id INTEGER PRIMARY KEY, createdAt TEXT, updatedAt TEXT, code TEXT, type INTEGER, exchangeType INTEGER, exchangeCount INTEGER, exchangeDate TEXT, usedByUserId INTEGER, codeInfo TEXT',
        'rc_config' => 'id INTEGER PRIMARY KEY, createdAt TEXT, updatedAt TEXT, appName TEXT, key TEXT, value TEXT, type INTEGER, status INTEGER',
    ] as $table => $columns) {
        Db::execute('CREATE TABLE ' . $table . ' (' . $columns . ')');
    }
    foreach ([1, 2, 3, 4] as $id) {
        Db::name('user')->insert(['id' => $id, 'userName' => 'user' . $id, 'nickName' => 'User ' . $id, 'authority' => 10, 'rCoin' => 25, 'userInfo' => '{}']);
    }
    businessRequest($app);
    Session::set('r_user', (new UserModel())->find(1));

    // Opposite directions form one conversation; unrelated and system messages stay excluded.
    foreach ([
        [1, 1, 2, 1, '2026-01-01 10:00:00', 'old'],
        [2, 2, 1, 1, '2026-01-01 12:00:00', 'latest pair 1-2'],
        [3, 1, 3, 1, '2026-01-01 11:00:00', 'pair 1-3'],
        [4, 2, 3, 1, '2026-01-01 14:00:00', 'unrelated'],
        [5, 0, 1, 0, '2026-01-01 15:00:00', 'system'],
    ] as [$id, $from, $to, $type, $time, $message]) {
        Db::name('notification')->insert(['id' => $id, 'fromUserId' => $from, 'toUserId' => $to, 'type' => $type, 'createdAt' => $time, 'message' => $message, 'readStatus' => 0]);
    }
    $notifications = responseData((new User($app))->getNotifications());
    expectBusiness($notifications['code'] === 200 && array_column($notifications['data'], 'id') === [2, 3], 'Conversation grouping or newest-first order failed: ' . json_encode($notifications) . ' ' . Db::getLastSql());
    expectBusiness($notifications['data'][0]['fromUserName'] === 'user2' && $notifications['data'][0]['toUserName'] === 'user1', 'Notification user joins failed');
    businessRequest($app, ['page' => 2, 'pageSize' => 1]);
    expectBusiness(array_column(responseData((new User($app))->getNotifications())['data'], 'id') === [3], 'SQLite notification pagination failed');

    Db::name('media_info')->insert(['id' => 1, 'mediaName' => 'Movie', 'mediaYear' => '2026', 'mediaType' => 1, 'mediaMainId' => 'movie1']);
    Db::name('media_comment')->insertAll([['id' => 1, 'mediaId' => 1, 'rating' => 6], ['id' => 2, 'mediaId' => 1, 'rating' => 8]]);
    businessRequest($app);
    $comments = responseData((new User($app))->getCommentList())['data'];
    expectBusiness(count($comments) === 1 && (float) $comments[0]['averageRating'] === 7.0 && (int) $comments[0]['commentCount'] === 2, 'SQLite comment aggregates failed');

    Db::name('lottery_participant')->insertAll([
        ['id' => 1, 'lotteryId' => 1, 'telegramId' => 'first', 'status' => 0],
        ['id' => 2, 'lotteryId' => 1, 'telegramId' => 'excluded', 'status' => 2],
        ['id' => 3, 'lotteryId' => 2, 'telegramId' => 'other lottery', 'status' => 0],
    ]);
    $winner = (new LotteryParticipantModel())->where('lotteryId', 1)->where('status', 0)->orderRand()->find();
    expectBusiness($winner && (int) $winner->id === 1, 'SQLite random lottery fallback failed');

    $expires = date('Y-m-d H:i:s', time() + 86400);
    Db::name('emby_user')->insert(['id' => 1, 'userId' => 1, 'activateTo' => $expires, 'userInfo' => '{}']);
    Db::name('emby_user')->insert(['id' => 2, 'userId' => 2, 'activateTo' => '2026-01-01 09:00:00']);
    expectBusiness(Db::name('emby_user')->whereTime('activateTo', 'between', ['2026-01-01 09:00:00', '2026-01-01 10:00:00'])->column('id') === [2], 'SQLite expiration date range failed');
    businessRequest($app);
    expectBusiness(responseData((new Server($app))->continueSubscribeEmbyUserByBalance())['code'] === 200, 'SQLite balance renewal failed');
    expectBusiness((float) Db::name('user')->where('id', 1)->value('rCoin') === 15.0, 'Renewal debit failed');
    expectBusiness(strtotime(Db::name('emby_user')->where('id', 1)->value('activateTo')) === strtotime($expires) + 2592000, 'Renewal expiration did not accumulate');
    expectBusiness(Db::name('finance_record')->where('userId', 1)->where('action', 3)->count() === 1, 'Renewal finance record missing');

    Db::name('exchange_code')->insert(['id' => 1, 'code' => 'SQLITE-ONCE', 'type' => 0, 'exchangeType' => 4, 'exchangeCount' => 7]);
    businessRequest($app, ['code' => 'SQLITE-ONCE']);
    expectBusiness(responseData((new Server($app))->exchangeCode())['code'] === 200, 'SQLite code redemption failed');
    expectBusiness(responseData((new Server($app))->exchangeCode())['code'] === 400, 'Code redeemed twice');
    expectBusiness((float) Db::name('user')->where('id', 1)->value('rCoin') === 22.0 && Db::name('finance_record')->where('action', 2)->count() === 1, 'Code redemption credited more than once');

    // A finance write failure must roll back both renewal fields, never partially charge.
    $beforeExpiry = Db::name('emby_user')->where('id', 1)->value('activateTo');
    Db::execute("CREATE TEMP TRIGGER fail_finance BEFORE INSERT ON rc_finance_record BEGIN SELECT RAISE(ABORT, 'fixture rollback'); END");
    businessRequest($app);
    expectBusiness(responseData((new Server($app))->continueSubscribeEmbyUserByBalance())['code'] === 400, 'Finance failure was not reported');
    expectBusiness((float) Db::name('user')->where('id', 1)->value('rCoin') === 22.0 && Db::name('emby_user')->where('id', 1)->value('activateTo') === $beforeExpiry, 'Renewal rollback left a partial balance/expiration change');
    Db::execute('DROP TRIGGER fail_finance');

    // Three FPM-equivalent processes try to spend a 25-coin balance: exactly two may succeed.
    Db::name('user')->where('id', 1)->update(['rCoin' => 25]);
    Db::name('emby_user')->where('id', 1)->update(['activateTo' => $expires]);
    $financeCount = Db::name('finance_record')->where('action', 3)->count();
    $successes = 0;
    foreach (concurrentResults($testRoot, $database, 'renew') as $result) {
        $successes += $result['code'] === 200 ? 1 : 0;
        expectBusiness($result['code'] === 200 || ($result['code'] === 400 && $result['message'] === '余额不足'), 'Concurrent renewal failed to serialize: ' . $result['message']);
    }
    expectBusiness($successes === 2 && (float) Db::name('user')->where('id', 1)->value('rCoin') === 5.0, 'Concurrent renewal overspent or lost a balance update');
    expectBusiness(strtotime(Db::name('emby_user')->where('id', 1)->value('activateTo')) === strtotime($expires) + 2 * 2592000 && Db::name('finance_record')->where('action', 3)->count() === $financeCount + 2, 'Concurrent renewal expiration/finance mismatch');

    Db::name('config')->insertAll([['key' => 'signInMinAmount', 'value' => '1'], ['key' => 'signInMaxAmount', 'value' => '2']]);
    Db::name('user')->where('id', 1)->update(['rCoin' => 0, 'userInfo' => json_encode(['loginIps' => ['127.0.0.1']])]);
    $signResults = concurrentResults($testRoot, $database, 'sign');
    expectBusiness(count(array_filter($signResults, fn($result) => $result['code'] === 200)) === 1, 'Concurrent sign-in awarded more than once');
    foreach ($signResults as $result) {
        expectBusiness($result['code'] === 200 || $result['message'] !== '签到失败，请稍后重试', 'Concurrent sign-in failed its write transaction');
    }
    $reward = (float) Db::name('finance_record')->where('action', 4)->value('count');
    $userInfo = json_decode(Db::name('user')->where('id', 1)->value('userInfo'), true);
    expectBusiness(Db::name('finance_record')->where('action', 4)->count() === 1 && $reward >= 1 && $reward <= 2 && (float) Db::name('user')->where('id', 1)->value('rCoin') === $reward && $userInfo['lastSignTime'] === date('Y-m-d'), 'Concurrent sign-in balance/date/finance mismatch');
    echo "SQLite business regressions passed\n";
} finally {
    Db::connect()->close();
    $remove = function (string $path) use (&$remove): void {
        if (is_dir($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
            }
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    };
    $remove($testRoot);
}
