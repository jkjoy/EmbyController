<?php
// Real controller/validator/template and bot command regression tests; temporary SQLite, no Telegram calls.
$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';
require_once $projectRoot . '/vendor/topthink/framework/src/helper.php';
require_once $projectRoot . '/app/common.php';
require_once $projectRoot . '/app/media/common.php';

use app\api\controller\Telegram;
use app\media\controller\Admin;
use app\media\validate\LotteryValidate;
use app\service\LotteryService;
use think\facade\Db;
use think\facade\Session;

function expectLotteryAdmin(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function lotteryAdminRequest(think\App $app, string $method, array $data = [], bool $admin = true): void
{
    $_GET = $method === 'GET' ? $data : [];
    $_POST = $method === 'POST' ? $data : [];
    $_COOKIE = [];
    $_REQUEST = $data;
    $_SERVER = array_merge($_SERVER, ['REQUEST_METHOD' => $method, 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'lottery.test',
        'SERVER_NAME' => 'lottery.test', 'SERVER_PORT' => '80', 'REQUEST_URI' => '/admin/lotteryList', 'SCRIPT_NAME' => '/index.php']);
    $request = app\Request::__make($app);
    $request->setMethod($method)->setController('Admin')->setAction('lotteryList')->setHost('lottery.test');
    $request->withSession($app->session);
    $app->instance('request', $request);
    $app->instance('think\Request', $request);
    Session::delete('r_user');
    if ($admin) Session::set('r_user', ['id' => 1, 'authority' => 0]);
}

function lotteryAdminInvoke(think\App $app, string $action): think\Response
{
    try {
        return (new Admin($app))->$action();
    } catch (think\exception\HttpResponseException $error) {
        return $error->getResponse();
    }
}

function lotteryAdminJson(think\App $app, string $action): array
{
    return json_decode(lotteryAdminInvoke($app, $action)->getContent(), true, 512, JSON_THROW_ON_ERROR);
}

$root = sys_get_temp_dir() . '/emby-lottery-admin-' . bin2hex(random_bytes(8));
mkdir($root);
touch($root . '/lottery.sqlite');
$app = new think\App($root);
$app->bind(require $projectRoot . '/app/provider.php');
$app->setRuntimePath($root . '/runtime/');
foreach (['app', 'cache', 'session', 'cookie', 'log', 'telegram', 'database', 'view', 'route'] as $name) {
    $config = require $projectRoot . '/config/' . $name . '.php';
    if ($name === 'cache') $config['stores']['file']['path'] = $root . '/runtime/cache/';
    if ($name === 'session') $config['path'] = $root . '/runtime/session/';
    if ($name === 'telegram') {
        $config['botConfig']['bots']['randallanjie_bot']['token'] = '';
        $config['botConfig']['bots']['randallanjie_bot']['username'] = 'custom_bot';
        $config['adminId'] = '100';
    }
    if ($name === 'view') {
        $config['view_path'] = $projectRoot . '/app/media/view/';
        $config['layout_on'] = false;
        $config['tpl_cache'] = false;
        $config['cache_path'] = $root . '/runtime/temp/';
    }
    if ($name === 'database') {
        $config['default'] = 'sqlite';
        $config['connections']['sqlite']['database'] = $root . '/lottery.sqlite';
        $config['connections']['sqlite']['prefix'] = 'rc_';
        $config['connections'] = ['sqlite' => $config['connections']['sqlite']];
    }
    $app->config->set($config, $name);
}
think\Model::setDb($app->make('think\DbManager'));
(new think\service\ModelService($app))->boot();
try {
    foreach ([
        'lottery_mutex' => 'id INTEGER PRIMARY KEY, revision INTEGER NOT NULL DEFAULT 0',
        'lottery' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, description TEXT, drawTime TEXT, prizes TEXT, keywords TEXT, chatId TEXT, status INTEGER, createTime TEXT',
        'lottery_participant' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, lotteryId INTEGER, telegramId TEXT, status INTEGER, prize TEXT, createTime TEXT',
        'lottery_notification' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, lotteryId INTEGER, notificationKey TEXT, chatId TEXT, message TEXT, deliveredAt TEXT, lastError TEXT',
        'telegram_user' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, userId INTEGER, telegramId TEXT, type INTEGER',
        'user' => 'id INTEGER PRIMARY KEY, authority INTEGER',
    ] as $table => $schema) Db::execute('CREATE TABLE rc_' . $table . ' (' . $schema . ')');
    Db::name('lottery_mutex')->insert(['id' => 1]);
    Db::name('user')->insert(['id' => 1, 'authority' => 10]);
    Db::name('telegram_user')->insert(['userId' => 1, 'telegramId' => '101', 'type' => 1]);

    $data = ['title' => '奖品 <b>演示</b>', 'description' => '活动 <script>alert(1)</script>', 'drawTime' => date('Y-m-d H:i:s', time() + 7200),
        'keywords' => '', 'chatId' => '-100', 'prizes' => json_encode([['name' => '礼品 "<b>"', 'count' => 1, 'contents' => ['</script><script>alert(2)</script>']]], JSON_UNESCAPED_UNICODE)];
    expectLotteryAdmin((new LotteryValidate())->scene('add')->check($data), 'Valid lottery with optional keyword failed');
    foreach ([
        array_replace($data, ['drawTime' => '2030-02-31 12:00:00']),
        array_replace($data, ['drawTime' => date('Y-m-d H:i:s', time() - 1)]),
        array_replace($data, ['chatId' => '100']),
        array_replace($data, ['prizes' => '[{"name":"A","count":1.5,"contents":["x"]}]']),
        array_replace($data, ['prizes' => '[{"name":"A","count":"1","contents":["x"]}]']),
        array_replace($data, ['prizes' => '[{"name":"A","count":1,"contents":[""]}]']),
        array_replace($data, ['prizes' => '[{"name":"A","count":2,"contents":["x"]}]']),
    ] as $invalid) expectLotteryAdmin(!(new LotteryValidate())->scene('add')->check($invalid), 'Invalid lottery input accepted');
    expectLotteryAdmin((new LotteryValidate())->scene('add')->check(array_replace($data, ['prizes' => '[{"name":"A","count":1,"contents":["0"]}]'])), 'String zero reward rejected');

    lotteryAdminRequest($app, 'POST', $data, false);
    expectLotteryAdmin(lotteryAdminInvoke($app, 'addLottery')->getCode() === 302 && Db::name('lottery')->count() === 0, 'Anonymous created lottery');
    lotteryAdminRequest($app, 'POST', $data);
    expectLotteryAdmin(lotteryAdminJson($app, 'addLottery')['code'] === 200, 'Admin create failed');
    $id = (int) Db::name('lottery')->value('id');
    lotteryAdminRequest($app, 'POST', $data);
    expectLotteryAdmin(lotteryAdminJson($app, 'addLottery')['code'] === 400 && Db::name('lottery')->count() === 1, 'Second active lottery accepted in same group');
    lotteryAdminRequest($app, 'POST', array_replace($data, ['id' => $id]));
    expectLotteryAdmin(lotteryAdminJson($app, 'editLottery')['code'] === 200, 'Admin edit failed');
    lotteryAdminRequest($app, 'GET', ['id' => $id, 'status' => 'true']);
    expectLotteryAdmin(lotteryAdminJson($app, 'changeLotteryStatus')['code'] === 405, 'GET changed lottery status');
    lotteryAdminRequest($app, 'POST', ['id' => $id, 'status' => 'true']);
    expectLotteryAdmin(lotteryAdminJson($app, 'changeLotteryStatus')['code'] === 200 && (int) Db::name('lottery')->where('id', $id)->value('status') === -1, 'Disable direction is wrong');
    lotteryAdminRequest($app, 'POST', ['id' => $id, 'status' => 'false']);
    expectLotteryAdmin(lotteryAdminJson($app, 'changeLotteryStatus')['code'] === 200 && (int) Db::name('lottery')->where('id', $id)->value('status') === 1, 'No-keyword lottery cannot be re-enabled');
    lotteryAdminRequest($app, 'GET', ['id' => $id]);
    $html = lotteryAdminInvoke($app, 'editLottery')->getContent();
    expectLotteryAdmin(str_contains($html, '/static/media/lottery-form.js') && str_contains($html, 'value="' . date('Y-m-d\TH:i', strtotime($data['drawTime'])) . '"'), 'Edit script/date field not rendered');
    expectLotteryAdmin(!str_contains($html, '</script><script>alert(2)</script>') && str_contains($html, '\\u003C'), 'Prize JSON escaped unsafely in script');

    $telegram = new Telegram($app);
    $chat = new ReflectionProperty($telegram, 'chat_id');
    $chat->setValue($telegram, '-100');
    $command = new ReflectionMethod($telegram, 'handleLotteryCommand');
    $announcement = $command->invoke($telegram, '/lottery', '101');
    expectLotteryAdmin(str_contains($announcement, '/joinlottery ' . $id) && !str_contains($announcement, '<script>') && !str_contains($announcement, 'alert(2)'), 'Announcement lacks registration or exposes prize');
    try {
        LotteryService::join($id, '101', '-200');
        throw new RuntimeException('Transaction accepted registration after lottery changed group');
    } catch (DomainException $error) {
        expectLotteryAdmin(Db::name('lottery_participant')->where('lotteryId', $id)->count() === 0, 'Rejected group registration still inserted record');
    }
    expectLotteryAdmin(str_contains($command->invoke($telegram, '/joinlottery', '101'), '成功'), 'No-keyword command registration failed');
    expectLotteryAdmin(str_contains($command->invoke($telegram, '/joinlottery', '101'), '已经参与'), 'Duplicate command registration accepted');
    $chat->setValue($telegram, '-200');
    expectLotteryAdmin(str_contains($command->invoke($telegram, '/joinlottery', '101', (string) $id), '没有进行'), 'Cross-group ID registration accepted');
    $chat->setValue($telegram, '-100');
    expectLotteryAdmin(str_contains($command->invoke($telegram, '/exitlottery', '101', (string) $id), '成功'), 'ID-specific exit failed');
    Db::name('lottery')->where('id', $id)->update(['drawTime' => date('Y-m-d H:i:s', time() - 1)]);
    expectLotteryAdmin(str_contains($command->invoke($telegram, '/joinlottery', '101'), '截止'), 'Deadline registration accepted');
    Db::name('lottery')->where('id', $id)->update(['status' => 0]);
    expectLotteryAdmin(str_contains($command->invoke($telegram, '/startlottery', '100'), '过期'), 'Expired draft was started');
    $draftId = LotteryService::createLottery(array_replace($data, ['chatId' => '']));
    $chat->setValue($telegram, '-200');
    expectLotteryAdmin(str_contains($command->invoke($telegram, '/startlottery', '101'), '没有权限'), 'Ordinary user started lottery');
    expectLotteryAdmin(str_contains($command->invoke($telegram, '/startlottery', '100'), '/joinlottery ' . $draftId)
        && Db::name('lottery')->where('id', $draftId)->value('chatId') === '-200', 'Bot cannot start future draft without keyword');

    $parse = new ReflectionMethod($telegram, 'parseBotText');
    $botCommand = '/joinlottery@CUSTOM_bot';
    $parsed = $parse->invoke($telegram, '🎉 ' . $botCommand . ' 7', [['type' => 'bot_command', 'offset' => 3, 'length' => strlen($botCommand)]]);
    expectLotteryAdmin($parsed['commands'] === ['/joinlottery'] && $parsed['text'] === '7' && $parsed['addressed'], 'Configured username or UTF-16 entity parsing failed');
    $foreign = '/joinlottery@other_bot';
    expectLotteryAdmin($parse->invoke($telegram, $foreign, [['type' => 'bot_command', 'offset' => 0, 'length' => strlen($foreign)]])['foreignCommand'], 'Foreign bot command accepted');

    Db::name('lottery')->where('id', $id)->update(['drawTime' => $data['drawTime'], 'status' => 2]);
    lotteryAdminRequest($app, 'POST', array_replace($data, ['id' => $id]));
    expectLotteryAdmin(lotteryAdminJson($app, 'editLottery')['code'] === 400, 'Ended lottery modified');
    lotteryAdminRequest($app, 'POST', ['id' => $id, 'status' => 'false']);
    expectLotteryAdmin(lotteryAdminJson($app, 'changeLotteryStatus')['code'] === 400, 'Ended lottery restarted');
    Db::name('lottery')->where('id', $id)->update(['status' => 3]);
    lotteryAdminRequest($app, 'POST', array_replace($data, ['id' => $id]));
    expectLotteryAdmin(lotteryAdminJson($app, 'editLottery')['code'] === 400, 'Drawing lottery modified');
    lotteryAdminRequest($app, 'GET', ['pageSize' => '0']);
    expectLotteryAdmin(str_contains(lotteryAdminInvoke($app, 'lotteryList')->getContent(), '开奖中'), 'Drawing state or zero pagination failed');

    $participantId = Db::name('lottery_participant')->insertGetId(['lotteryId' => $id, 'telegramId' => '101', 'status' => 1,
        'prize' => json_encode(['name' => '正确奖品', 'content' => '领取码 <script>alert(3)</script>']), 'createTime' => date('Y-m-d H:i:s')]);
    Db::name('lottery_notification')->insert(['lotteryId' => $id, 'notificationKey' => 'winner-' . $participantId . '-1', 'chatId' => '101',
        'message' => '中奖私信', 'lastError' => 'Forbidden <b>blocked</b>']);
    Db::name('lottery_notification')->insert(['lotteryId' => $id, 'notificationKey' => 'group-1', 'chatId' => '-100',
        'message' => json_encode('本次为旧开奖任务恢复'), 'deliveredAt' => date('Y-m-d H:i:s')]);
    lotteryAdminRequest($app, 'GET', ['id' => $id]);
    $html = lotteryAdminInvoke($app, 'lotteryParticipants')->getContent();
    expectLotteryAdmin(str_contains($html, '正确奖品') && str_contains($html, '已送达0条，待发送1条') && str_contains($html, '旧开奖任务恢复'), 'Participant prize/delivery/recovery details missing');
    expectLotteryAdmin(!str_contains($html, '<script>alert(3)</script>') && !str_contains($html, '<b>blocked</b>'), 'Participant prize/error is unsafe HTML');
    echo "Lottery admin, template and Telegram command checks passed\n";
} finally {
    Db::connect()->close();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($root);
}
