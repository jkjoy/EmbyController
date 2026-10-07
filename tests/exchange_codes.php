<?php
/** php tests/exchange_codes.php: real migrations/controllers, disposable SQLite and loopback Emby. */
$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';
require_once $projectRoot . '/vendor/topthink/framework/src/helper.php';
require_once $projectRoot . '/app/common.php';
require_once $projectRoot . '/app/media/common.php';

use app\media\controller\Admin;
use app\media\controller\Server;
use app\media\model\UserModel;
use app\service\SystemSettings;
use think\facade\Db;
use think\facade\Session;
use think\facade\View;

function expectExchange(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function exchangePhp(): array
{
    $command = [PHP_BINARY];
    if (php_ini_loaded_file()) array_push($command, '-c', php_ini_loaded_file());
    return array_merge($command, ['-d', 'variables_order=EGPCS', '-d', 'opcache.enable_cli=0']);
}

function exchangeProcess(array $arguments, string $root, ?array $environment = null): array
{
    $process = proc_open(array_merge(exchangePhp(), $arguments), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment, ['bypass_shell' => true]);
    expectExchange(is_resource($process), 'Cannot start fixture process');
    fclose($pipes[0]);
    return [$process, $pipes];
}

function exchangeResult(array $worker): string
{
    [$process, $pipes] = $worker;
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    expectExchange(proc_close($process) === 0, 'Fixture process failed: ' . $output . $errors);
    return $output;
}

function exchangeApp(string $root, string $database, string $emby): think\App
{
    $app = new think\App($root);
    $app->bind(require dirname(__DIR__) . '/app/provider.php');
    $app->setRuntimePath($root . '/runtime/');
    foreach (['app', 'cache', 'session', 'cookie', 'log', 'telegram', 'map', 'apiinfo', 'database', 'media', 'view', 'route'] as $name) {
        $config = require dirname(__DIR__) . '/config/' . $name . '.php';
        if ($name === 'cache') $config['stores']['file']['path'] = $root . '/runtime/cache/';
        if ($name === 'session') $config['path'] = $root . '/runtime/session/';
        if ($name === 'view') {
            $config['view_path'] = dirname(__DIR__) . '/app/media/view/';
            $config['tpl_cache'] = false;
            $config['cache_path'] = $root . '/runtime/temp/';
        }
        if ($name === 'telegram') $config['botConfig']['bots']['randallanjie_bot']['token'] = '';
        if ($name === 'database') {
            $config['default'] = 'sqlite';
            $config['connections']['sqlite']['database'] = $database;
            $config['connections']['sqlite']['prefix'] = 'rc_';
            $config['connections'] = ['sqlite' => $config['connections']['sqlite']];
        }
        $app->config->set($config, $name);
    }
    think\Model::setDb($app->make('think\DbManager'));
    (new think\service\ModelService($app))->boot();
    $app->config->set(['urlBase' => $emby . '/', 'apiKey' => 'fixture-only-key', 'UserTemplateId' => 'template'], 'media');
    return $app;
}

function exchangeRequest(think\App $app, string $method = 'POST', array $data = [], ?int $userId = 1): void
{
    $_GET = $_COOKIE = [];
    $_POST = $method === 'POST' ? $data : [];
    $_GET = $method === 'GET' ? $data : [];
    $_REQUEST = $data;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_HOST'] = 'exchange.test';
    $_SERVER['SERVER_NAME'] = 'exchange.test';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_SERVER['REQUEST_URI'] = '/server/redeemCode';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $request = app\Request::__make($app);
    $request->setMethod($method)->setController('Server')->setAction('redeemCode')->setHost('exchange.test');
    $app->instance('request', $request);
    $app->instance('think\Request', $request);
    $request->withSession($app->session);
    Session::delete('r_user');
    if ($userId !== null) Session::set('r_user', (new UserModel())->find($userId));
}

function exchangeInvoke(think\App $app, string $controller, string $method): array
{
    try {
        $response = (new $controller($app))->$method();
    } catch (think\exception\HttpResponseException $error) {
        $response = $error->getResponse();
    }
    expectExchange($response instanceof think\Response, 'Controller returned no response for ' . $method);
    return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
}

function generateExchange(think\App $app, int $type, string $count, int $quantity = 1): array
{
    exchangeRequest($app, 'POST', ['mode' => $quantity === 1 ? 'single' : 'batch', 'exchangeType' => (string) $type, 'exchangeCount' => $count, 'generateCount' => (string) $quantity, 'remark' => '回归备注']);
    $result = exchangeInvoke($app, Admin::class, 'addExchangeCode');
    expectExchange($result['code'] === 200, 'Generation failed: ' . json_encode($result));
    $codes = $result['data']['codes'];
    expectExchange(count($codes) === $quantity && count(array_unique($codes)) === $quantity, 'Generated code count/uniqueness failed');
    foreach ($codes as $code) {
        expectExchange(preg_match('/^[0-9A-F]{32}$/D', $code) === 1, 'Generated code is not 128-bit hexadecimal');
        $row = Db::name('exchange_code')->where('code', $code)->find();
        expectExchange((int) $row['exchangeType'] === $type && (float) $row['exchangeCount'] === (float) $count && (int) $row['type'] === 0, 'Generation lost type/decimal/status');
    }
    return $codes;
}

function redeemExchange(think\App $app, $code, int $userId = 2, string $method = 'redeemCode'): array
{
    exchangeRequest($app, 'POST', ['code' => $code], $userId);
    return exchangeInvoke($app, Server::class, $method);
}

function exchangeUnused(string $code): void
{
    $row = Db::name('exchange_code')->where('code', $code)->find();
    expectExchange((int) $row['type'] === 0 && $row['usedByUserId'] === null && $row['exchangeDate'] === null, 'Failure consumed code: ' . $code);
}

function exchangeStubPosts(string $root): array
{
    $requests = array_map(fn($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($root . '/stub-requests', FILE_IGNORE_NEW_LINES));
    return array_values(array_filter($requests, fn($request) => $request['method'] === 'POST'));
}

function exchangeConcurrent(string $root, string $database, string $emby, array $codes, int $userId = 2): array
{
    $barrier = $root . '/parallel-' . bin2hex(random_bytes(3));
    mkdir($barrier);
    $workers = [];
    try {
        foreach ($codes as $id => $code) {
            $workers[] = exchangeProcess([__FILE__, '--redeem-worker', $barrier, $database, $emby, (string) $id, (string) $userId, $code], $root);
        }
        $deadline = microtime(true) + 20;
        while (count(glob($barrier . '/ready-*')) < count($workers)) {
            expectExchange(microtime(true) < $deadline, 'Concurrent workers did not reach barrier');
            usleep(10000);
        }
        file_put_contents($barrier . '/go', 'go');
        return array_map(fn($worker) => json_decode(exchangeResult($worker), true, 512, JSON_THROW_ON_ERROR), $workers);
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

if (($argv[1] ?? '') === '--redeem-worker') {
    [, , $barrier, $database, $emby, $id, $userId, $code] = $argv;
    $root = $barrier . '/worker-' . $id;
    mkdir($root);
    $app = exchangeApp($root, $database, $emby);
    file_put_contents($barrier . '/ready-' . $id, 'ready');
    $deadline = microtime(true) + 20;
    while (!is_file($barrier . '/go')) {
        expectExchange(microtime(true) < $deadline, 'Concurrent worker barrier timeout');
        usleep(10000);
    }
    echo json_encode(redeemExchange($app, $code, (int) $userId), JSON_THROW_ON_ERROR);
    Db::connect()->close();
    exit;
}

if (($argv[1] ?? '') === '--currency-worker') {
    $app = exchangeApp($argv[2], $argv[3], 'http://127.0.0.1:1');
    SystemSettings::apply($app, true);
    echo json_encode(currencyName(), JSON_THROW_ON_ERROR);
    Db::connect()->close();
    exit;
}

$root = sys_get_temp_dir() . '/emby-exchange-codes-' . bin2hex(random_bytes(8));
$stub = null;
$app = null;
mkdir($root);
try {
    foreach (['app', 'config', 'database/migrations', 'runtime/temp'] as $directory) mkdir($root . '/' . $directory, 0777, true);
    foreach (glob($projectRoot . '/config/*.php') as $file) file_put_contents($root . '/config/' . basename($file), '<?php return require ' . var_export($file, true) . ';');
    foreach (glob($projectRoot . '/database/migrations/*.php') as $file) copy($file, $root . '/database/migrations/' . basename($file));
    file_put_contents($root . '/app/service.php', '<?php return [\\think\\migration\\Service::class];');
    file_put_contents($root . '/app/provider.php', '<?php return require ' . var_export($projectRoot . '/app/provider.php', true) . ';');
    file_put_contents($root . '/think', '<?php require ' . var_export($projectRoot . '/vendor/autoload.php', true) . '; (new \\think\\App(__DIR__))->console->run();');
    $database = $root . '/data/exchange.sqlite';
    $environment = array_filter(getenv() ?: [], fn($key) => !preg_match('/^(DB_|APP_|EMBY_|TG_|MAIL_|REDIS_)/', $key), ARRAY_FILTER_USE_KEY);
    $environment = array_merge($environment, ['DB_DRIVER' => 'sqlite', 'DB_TYPE' => 'sqlite', 'DB_NAME' => $database, 'DB_PREFIX' => 'rc_']);
    exchangeResult(exchangeProcess([$root . '/think', 'migrate:run'], $root, $environment));

    // The real curl client talks only to this disposable Emby server; mode controls API failures.
    file_put_contents($root . '/stub-mode', 'ok');
    file_put_contents($root . '/stub.php', <<<'PHP'
<?php
$mode = trim(file_get_contents(__DIR__ . '/stub-mode'));
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');
file_put_contents(__DIR__ . '/stub-requests', json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'body' => json_decode($body, true)]) . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('~^/Users/[^/]+$~', $path)) {
    if ($mode === 'fail-get') { http_response_code(503); echo '{"error":"private Emby upstream failure"}'; }
    elseif ($mode === 'bad-policy') echo '{"Name":"MissingPolicy"}';
    else echo json_encode(['Id' => 'fixture-emby', 'Policy' => ['IsDisabled' => $mode !== 'active-policy', 'EnableMediaPlayback' => true, 'EnableVideoPlaybackTranscoding' => false]]);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('~^/Users/[^/]+/Policy$~', $path)) {
    if ($mode === 'fail-post') { http_response_code(503); echo '{"error":"private Emby upstream failure"}'; }
    else http_response_code(204);
} else { http_response_code(404); echo '{}'; }
PHP
    );
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    expectExchange(is_resource($listener), 'Cannot reserve loopback stub port');
    $emby = 'http://' . stream_socket_get_name($listener, false);
    fclose($listener);
    $stub = proc_open(array_merge(exchangePhp(), ['-S', substr($emby, 7), $root . '/stub.php']), [0 => ['pipe', 'r'], 1 => ['file', $root . '/stub.log', 'a'], 2 => ['file', $root . '/stub.log', 'a']], $pipes, $root, null, ['bypass_shell' => true]);
    expectExchange(is_resource($stub), 'Cannot start loopback Emby stub');
    fclose($pipes[0]);
    $deadline = microtime(true) + 10;
    do {
        $connection = @stream_socket_client(substr($emby, 7), $errorCode, $errorMessage, 0.1);
        if (is_resource($connection)) { fclose($connection); break; }
        expectExchange(microtime(true) < $deadline, 'Emby stub failed to listen');
        usleep(10000);
    } while (true);

    $app = exchangeApp($root, $database, $emby);
    foreach ([2 => 10, 3 => 10, 4 => -1, 5 => 101, 6 => 10] as $id => $authority) {
        Db::name('user')->insert(['id' => $id, 'userName' => 'fixture' . $id, 'password' => 'unused', 'authority' => $authority, 'rCoin' => 0, 'userInfo' => '{}']);
    }
    foreach ([2, 4, 5] as $id) Db::name('emby_user')->insert(['userId' => $id, 'embyId' => 'fixture-' . $id, 'activateTo' => $id === 5 ? null : date('Y-m-d H:i:s', time() - 86400), 'userInfo' => '{}']);

    $coin = generateExchange($app, 4, '2.75')[0];
    generateExchange($app, 4, '0.25', 3);
    generateExchange($app, 2, '7', 2);
    foreach ([[], ['exchangeType' => '5'], ['exchangeType' => '4', 'exchangeCount' => '0'], ['exchangeType' => '4', 'exchangeCount' => '-1'], ['exchangeType' => '4', 'exchangeCount' => '1e3'], ['exchangeType' => '4', 'exchangeCount' => '1.234'], ['exchangeType' => '4', 'exchangeCount' => '1000000.01'], ['exchangeType' => '2', 'exchangeCount' => '1.5'], ['exchangeType' => '2', 'exchangeCount' => '3651'], ['exchangeType' => '3', 'exchangeCount' => '121'], ['mode' => 'other'], ['mode' => 'batch', 'generateCount' => '101'], ['mode' => 'batch', 'generateCount' => '0'], ['remark' => str_repeat('测', 501)], ['exchangeType' => ['4']]] as $invalid) {
        $before = Db::name('exchange_code')->count();
        exchangeRequest($app, 'POST', array_replace(['mode' => 'single', 'exchangeType' => '4', 'exchangeCount' => '2.75', 'generateCount' => '3'], $invalid));
        if ($invalid === []) exchangeRequest($app, 'POST', []);
        expectExchange(exchangeInvoke($app, Admin::class, 'addExchangeCode')['code'] === 400, 'Invalid generation accepted: ' . json_encode($invalid));
        expectExchange(Db::name('exchange_code')->count() === $before, 'Invalid generation partially inserted codes');
    }
    foreach ([null, 2] as $actor) {
        exchangeRequest($app, 'POST', ['exchangeType' => '4', 'exchangeCount' => '99'], $actor);
        expectExchange(exchangeInvoke($app, Admin::class, 'addExchangeCode')['code'] !== 200, 'Unauthorized code generation accepted');
    }
    $before = Db::name('exchange_code')->count();
    Db::execute("CREATE TEMP TRIGGER fail_exchange_batch BEFORE INSERT ON rc_exchange_code WHEN NEW.exchangeCount = 23.75 AND EXISTS (SELECT 1 FROM rc_exchange_code WHERE exchangeCount = 23.75) BEGIN SELECT RAISE(ABORT, 'fixture second insert'); END");
    exchangeRequest($app, 'POST', ['mode' => 'batch', 'exchangeType' => '4', 'exchangeCount' => '23.75', 'generateCount' => '3']);
    $result = exchangeInvoke($app, Admin::class, 'addExchangeCode');
    expectExchange(in_array($result['code'], [400, 500], true) && Db::name('exchange_code')->count() === $before, 'Batch failure was accepted/left partial codes: ' . json_encode($result) . ' count ' . Db::name('exchange_code')->count() . '/' . $before);
    Db::execute('DROP TRIGGER fail_exchange_batch');
    echo "PASS: administrator generation, decimal amount and strict type/count/batch/remark validation.\n";

    $result = redeemExchange($app, $coin);
    expectExchange($result['code'] === 200 && (float) $result['rCoin'] === 2.75 && (float) $result['exchangeCount'] === 2.75, 'Decimal redemption failed');
    expectExchange(redeemExchange($app, $coin)['code'] === 400, 'Code redeemed twice');
    expectExchange((float) Db::name('user')->where('id', 2)->value('rCoin') === 2.75 && Db::name('finance_record')->where('userId', 2)->count() === 1, 'Repeat credited/recorded twice');
    $disabled = generateExchange($app, 4, '1.25')[0];
    $id = Db::name('exchange_code')->where('code', $disabled)->value('id');
    exchangeRequest($app, 'POST', ['id' => $id, 'status' => 'true'], 2);
    expectExchange(exchangeInvoke($app, Admin::class, 'changeExchangeCodeStatus')['code'] !== 200, 'Unauthorized code disabling accepted');
    exchangeUnused($disabled);
    foreach ([['id' => 0, 'status' => 'true'], ['id' => 'bad', 'status' => 'true'], ['id' => $id, 'status' => 'bad'], ['id' => $id, 'status' => ['true']]] as $invalid) {
        exchangeRequest($app, 'POST', $invalid);
        expectExchange(exchangeInvoke($app, Admin::class, 'changeExchangeCodeStatus')['code'] === 400, 'Invalid status request accepted');
        exchangeUnused($disabled);
    }
    exchangeRequest($app, 'GET', ['id' => $id, 'status' => 'true']);
    expectExchange(exchangeInvoke($app, Admin::class, 'changeExchangeCodeStatus')['code'] === 405, 'GET status mutation accepted');
    exchangeRequest($app, 'POST', ['id' => $id, 'status' => 'true']);
    expectExchange(exchangeInvoke($app, Admin::class, 'changeExchangeCodeStatus')['code'] === 200 && redeemExchange($app, $disabled)['code'] === 400, 'Disabled code redeemed');
    exchangeRequest($app, 'POST', ['id' => $id, 'status' => 'false']);
    expectExchange(exchangeInvoke($app, Admin::class, 'changeExchangeCodeStatus')['code'] === 200 && redeemExchange($app, $disabled)['code'] === 200, 'Reenabled code failed');
    exchangeRequest($app, 'POST', ['id' => $id, 'status' => 'false']);
    expectExchange(exchangeInvoke($app, Admin::class, 'changeExchangeCodeStatus')['code'] === 400, 'Used code could be reenabled');
    $before = (float) Db::name('user')->where('id', 2)->value('rCoin');
    foreach ([null, '', 'unknown-code', ['bad'], str_repeat('A', 65)] as $invalid) expectExchange(redeemExchange($app, $invalid)['code'] === 400, 'Invalid redemption input accepted');
    exchangeRequest($app, 'GET', ['code' => $coin], 2);
    expectExchange(exchangeInvoke($app, Server::class, 'redeemCode')['code'] === 405, 'GET redemption accepted');
    exchangeRequest($app, 'POST', ['code' => $coin], null);
    expectExchange(exchangeInvoke($app, Server::class, 'redeemCode')['code'] === 401, 'Unauthenticated redemption accepted');
    $bannedCode = generateExchange($app, 4, '1.00')[0];
    expectExchange(redeemExchange($app, $bannedCode, 4)['code'] === 403, 'Banned redemption accepted');
    exchangeUnused($bannedCode);
    foreach ([[null, 401], [4, 403]] as [$actor, $expected]) {
        exchangeRequest($app, 'POST', ['code' => $bannedCode], $actor);
        $response = (new app\media\middleware\MediaAuth())->handle($app->request, fn() => json(['code' => 200]));
        $result = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        expectExchange($result['code'] === $expected && $response->getCode() === $expected, 'Real MediaAuth did not return redemption JSON/status ' . $expected);
    }
    expectExchange((float) Db::name('user')->where('id', 2)->value('rCoin') === $before, 'Rejected redemption changed balance');
    echo "PASS: one-time consumption, disable/reenable, method/login/ban and malformed-code enforcement.\n";

    $rollback = generateExchange($app, 4, '8.25')[0];
    Db::execute("CREATE TEMP TRIGGER fail_exchange_finance BEFORE INSERT ON rc_finance_record BEGIN SELECT RAISE(ABORT, 'fixture rollback'); END");
    expectExchange(in_array(redeemExchange($app, $rollback)['code'], [400, 500], true), 'Finance failure was accepted');
    expectExchange((float) Db::name('user')->where('id', 2)->value('rCoin') === $before, 'Finance rollback left partial balance');
    exchangeUnused($rollback);
    Db::execute('DROP TRIGGER fail_exchange_finance');
    expectExchange(redeemExchange($app, $rollback)['code'] === 200, 'Rolled-back code could not be retried');

    foreach ([1 => '1', 2 => '3', 3 => '2'] as $type => $count) {
        $code = generateExchange($app, $type, $count)[0];
        $base = $type === 1 ? time() : strtotime(Db::name('emby_user')->where('userId', 2)->value('activateTo'));
        $result = redeemExchange($app, $code);
        $days = $type === 3 ? 60 : (int) $count;
        $expiry = strtotime(Db::name('emby_user')->where('userId', 2)->value('activateTo'));
        expectExchange($result['code'] === 200 && abs($expiry - $base - $days * 86400) <= 2, 'Membership duration failed for type ' . $type);
    }
    $membership = generateExchange($app, 2, '2')[0];
    $expiry = Db::name('emby_user')->where('userId', 2)->value('activateTo');
    foreach (['fail-get', 'bad-policy', 'fail-post'] as $mode) {
        file_put_contents($root . '/stub-mode', $mode);
        $result = redeemExchange($app, $membership);
        expectExchange($result['code'] === 400 && !str_contains(json_encode($result), 'private Emby upstream failure'), 'Emby failure accepted/exposed: ' . $mode);
        exchangeUnused($membership);
        expectExchange(Db::name('emby_user')->where('userId', 2)->value('activateTo') === $expiry, 'Emby failure changed expiration');
    }
    file_put_contents($root . '/stub-mode', 'ok');
    foreach ([3, 5] as $actor) {
        expectExchange(redeemExchange($app, $membership, $actor)['code'] === 400, 'Membership accepted without finite Emby account');
        exchangeUnused($membership);
        expectExchange(redeemExchange($app, generateExchange($app, 4, '1.75')[0], $actor)['code'] === 200, 'Balance requires finite Emby account');
    }
    expectExchange(redeemExchange($app, $membership)['code'] === 200, 'Membership could not retry after API failure');
    foreach (exchangeStubPosts($root) as $request) expectExchange($request['body']['IsDisabled'] === false && $request['body']['EnableVideoPlaybackTranscoding'] === false, 'Emby policy was not preserved/enabled');
    file_put_contents($root . '/stub-mode', 'active-policy');
    $active = generateExchange($app, 2, '1')[0];
    $posts = count(exchangeStubPosts($root));
    expectExchange(redeemExchange($app, $active)['code'] === 200 && count(exchangeStubPosts($root)) === $posts, 'Active membership unnecessarily rewrote Emby policy');
    file_put_contents($root . '/stub-mode', 'ok');
    $rollbackMember = generateExchange($app, 2, '1')[0];
    $expiry = Db::name('emby_user')->where('userId', 2)->value('activateTo');
    $posts = count(exchangeStubPosts($root));
    Db::execute("CREATE TEMP TRIGGER fail_exchange_finance BEFORE INSERT ON rc_finance_record BEGIN SELECT RAISE(ABORT, 'fixture rollback'); END");
    expectExchange(in_array(redeemExchange($app, $rollbackMember)['code'], [400, 500], true), 'Membership finance failure was accepted');
    exchangeUnused($rollbackMember);
    expectExchange(Db::name('emby_user')->where('userId', 2)->value('activateTo') === $expiry, 'Membership finance rollback changed expiration');
    $compensation = array_slice(exchangeStubPosts($root), $posts);
    expectExchange(count($compensation) === 0 || (count($compensation) === 2 && $compensation[0]['body']['IsDisabled'] === false && $compensation[1]['body']['IsDisabled'] === true), 'Membership finance rollback did not restore original Emby policy');
    Db::execute('DROP TRIGGER fail_exchange_finance');
    echo "PASS: finance rollback, one/day/month membership, missing/lifetime accounts and real Emby failures without consumption.\n";

    // Legacy addresses retain their original type restrictions while sharing one transaction.
    foreach ([['activateEmbyUserByCode', 1], ['continueSubscribeEmbyUserByCode', 2], ['exchangeCode', 4]] as [$method, $type]) {
        $code = generateExchange($app, $type, '1')[0];
        $wrongMethod = $type === 4 ? 'activateEmbyUserByCode' : 'exchangeCode';
        expectExchange(redeemExchange($app, $code, 2, $wrongMethod)['code'] === 400, 'Legacy route accepted incorrect code type');
        exchangeUnused($code);
        expectExchange(redeemExchange($app, $code, 2, $method)['code'] === 200, 'Legacy compatible route failed');
    }
    Db::name('exchange_code')->insert(['code' => 'Legacy!#-Mix', 'exchangeType' => 4, 'exchangeCount' => '0.10']);
    $result = redeemExchange($app, 'Legacy!#-Mix', 3);
    expectExchange($result['code'] === 200 && $result['rCoin'] === '1.85' && $result['exchangeCount'] === '0.10', 'Legacy symbols or cent precision were lost');
    foreach (['0.10', '0.20'] as $amount) expectExchange(redeemExchange($app, generateExchange($app, 4, $amount)[0], 6)['code'] === 200, 'Cent redemption failed');
    expectExchange((float) Db::name('user')->where('id', 6)->value('rCoin') === 0.30, '0.10 + 0.20 retained floating point drift');
    Db::name('exchange_code')->insertAll([['code' => 'DUPLICATE-OLD', 'exchangeType' => 4, 'exchangeCount' => '1'], ['code' => 'DUPLICATE-OLD', 'exchangeType' => 4, 'exchangeCount' => '1']]);
    expectExchange(redeemExchange($app, 'DUPLICATE-OLD')['code'] === 400 && Db::name('exchange_code')->where('code', 'DUPLICATE-OLD')->where('type', 0)->count() === 2, 'Ambiguous old duplicate code was redeemed');
    echo "PASS: legacy endpoint type restrictions, old code symbols, cent precision and ambiguous duplicate rejection.\n";

    $coin = generateExchange($app, 4, '5.50')[0];
    $balance = (float) Db::name('user')->where('id', 2)->value('rCoin');
    $records = Db::name('finance_record')->where('userId', 2)->count();
    $results = exchangeConcurrent($root, $database, $emby, [$coin, $coin, $coin]);
    expectExchange(count(array_filter($results, fn($result) => $result['code'] === 200)) === 1, 'Concurrent same-code balance had multiple/no winners');
    expectExchange((float) Db::name('user')->where('id', 2)->value('rCoin') === $balance + 5.5 && Db::name('finance_record')->where('userId', 2)->count() === $records + 1, 'Concurrent same-code balance/finance mismatch');
    $codes = [generateExchange($app, 4, '2.25')[0], generateExchange($app, 4, '3.50')[0], generateExchange($app, 4, '4.75')[0]];
    $results = exchangeConcurrent($root, $database, $emby, $codes);
    expectExchange(count(array_filter($results, fn($result) => $result['code'] === 200)) === 3 && (float) Db::name('user')->where('id', 2)->value('rCoin') === $balance + 16, 'Concurrent different-code balances lost updates');
    $code = generateExchange($app, 2, '2')[0];
    $expiry = strtotime(Db::name('emby_user')->where('userId', 2)->value('activateTo'));
    $posts = count(exchangeStubPosts($root));
    $results = exchangeConcurrent($root, $database, $emby, [$code, $code, $code]);
    expectExchange(count(array_filter($results, fn($result) => $result['code'] === 200)) === 1 && strtotime(Db::name('emby_user')->where('userId', 2)->value('activateTo')) === $expiry + 2 * 86400, 'Concurrent same-code membership accumulated incorrectly');
    expectExchange(count(exchangeStubPosts($root)) === $posts + 1, 'Concurrent same-code membership issued duplicate activation');
    $codes = [generateExchange($app, 1, '1')[0], generateExchange($app, 2, '2')[0], generateExchange($app, 3, '1')[0]];
    $results = exchangeConcurrent($root, $database, $emby, $codes);
    expectExchange(count(array_filter($results, fn($result) => $result['code'] === 200)) === 3 && strtotime(Db::name('emby_user')->where('userId', 2)->value('activateTo')) === $expiry + 35 * 86400, 'Concurrent different-code memberships lost expiration updates');
    echo "PASS: independent-process same-code uniqueness and different-code balance/expiration accumulation.\n";

    // Persist through the actual settings controller and render its real template/layout.
    SystemSettings::apply($app, true);
    expectExchange(currencyName() === 'R币', 'Missing currency name did not fall back');
    $currency = 'X<&"币\'';
    exchangeRequest($app, 'POST', ['currencyName' => $currency]);
    expectExchange(exchangeInvoke($app, Admin::class, 'setting')['code'] === 200 && currencyName() === $currency, 'Currency POST did not apply');
    expectExchange(currencyNameHtml() === htmlspecialchars($currency, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'Currency HTML helper did not escape exactly once');
    exchangeRequest($app, 'GET');
    View::assign('user', (new UserModel())->find(1));
    View::assign('embyUser', null);
    View::assign('enableMoviepilot', false);
    $html = (new Admin($app))->setting()->getContent();
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    $inputs = (new DOMXPath($document))->query('//input[@name="currencyName"]');
    expectExchange($inputs->length === 1 && $inputs[0]->getAttribute('value') === $currency, 'Currency setting value was lost/double escaped');
    expectExchange(!str_contains($html, $currency), 'Currency raw markup leaked into template');
    $workerRoot = $root . '/currency-worker';
    mkdir($workerRoot);
    $persisted = exchangeResult(exchangeProcess([__FILE__, '--currency-worker', $workerRoot, $database], $root));
    expectExchange(json_decode($persisted, true, 512, JSON_THROW_ON_ERROR) === $currency, 'Currency did not persist across independent process');
    foreach (['', str_repeat('币', 21), ['invalid']] as $invalid) {
        exchangeRequest($app, 'POST', ['currencyName' => $invalid]);
        expectExchange(exchangeInvoke($app, Admin::class, 'setting')['code'] === 400 && SystemSettings::get('currencyName') === $currency, 'Invalid currency changed saved setting');
    }
    echo "PASS: currency controller persistence, fresh process, input validation and full-template HTML escaping.\n";
} finally {
    if (is_resource($stub)) { proc_terminate($stub); proc_close($stub); }
    if ($app !== null) Db::connect()->close();
    $remove = function (string $path) use (&$remove): void {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $entry) if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
            rmdir($path);
        } elseif (file_exists($path)) unlink($path);
    };
    $remove($root);
}
