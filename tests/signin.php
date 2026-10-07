<?php
/** php tests/signin.php: real controllers, shared file cache and SQLite transactions; no network messages. */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';
require_once $projectRoot . '/vendor/topthink/framework/src/helper.php';
require_once $projectRoot . '/app/common.php';
require_once $projectRoot . '/app/media/common.php';

use app\api\controller\Telegram;
use app\media\controller\Account;
use app\media\controller\User;
use app\media\middleware\MediaAuth;
use app\media\model\UserModel;
use app\service\SignInService;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Session;

function expectSign(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function signApp(string $root, string $database, string $runtime): think\App
{
    $app = new think\App($root);
    $app->bind(require dirname(__DIR__) . '/app/provider.php');
    $app->setRuntimePath($root . '/runtime/' . $runtime . '/');
    foreach (['app', 'cache', 'session', 'cookie', 'log', 'telegram', 'map', 'apiinfo', 'database', 'media', 'view', 'route'] as $name) {
        $config = require dirname(__DIR__) . '/config/' . $name . '.php';
        if ($name === 'app') $config['app_host'] = 'https://signin.test';
        if ($name === 'session') $config['path'] = $root . '/runtime/' . $runtime . '/session/';
        if ($name === 'view') {
            $config['tpl_cache'] = false;
            $config['cache_path'] = $root . '/runtime/' . $runtime . '/temp/';
        }
        if ($name === 'telegram') $config['botConfig']['bots']['randallanjie_bot']['token'] = '';
        if ($name === 'map') $config['enable'] = false;
        if ($name === 'apiinfo') $config['cloudflareTurnstile'] = ['noninteractive' => ['sitekey' => '', 'secret' => ''], 'invisible' => ['sitekey' => '', 'secret' => '']];
        if ($name === 'database') {
            $config['default'] = 'sqlite';
            $config['connections']['sqlite']['database'] = $database;
            $config['connections']['sqlite']['prefix'] = 'rc_';
            $config['connections'] = ['sqlite' => $config['connections']['sqlite']];
        }
        $app->config->set($config, $name);
    }
    // Use the real cache config: it must stay shared when the active application runtime changes.
    expectSign($app->config->get('cache.stores.file.path') === $root . '/runtime/cache/', 'File cache is not shared across applications');
    think\Model::setDb($app->make('think\DbManager'));
    (new think\service\ModelService($app))->boot();
    return $app;
}

function signRequest(think\App $app, string $path, array $data = [], string $method = 'POST', string $ip = '127.0.0.1'): void
{
    $_COOKIE = [];
    $_POST = $method === 'POST' ? $data : [];
    $_GET = $method === 'GET' ? $data : [];
    $_REQUEST = $data;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REMOTE_ADDR'] = $ip;
    unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_CF_CONNECTING_IP']);
    $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'signin.test';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = $path;
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $request = app\Request::__make($app);
    $request->setMethod($method)->setController(str_starts_with($path, '/user/') ? 'User' : 'Account')->setAction('sign')->setHost('signin.test');
    $app->instance('request', $request);
    $app->instance('think\Request', $request);
    $request->withSession($app->session);
}

function signResult(think\Response $response): array
{
    return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
}

function signTokenFromBot(think\App $app, string $telegramId): string
{
    $message = (new ReflectionMethod(Telegram::class, 'getSign'))->invoke(new Telegram($app), $telegramId);
    expectSign(preg_match('~https://signin\.test/account/sign\?signkey=([0-9a-f]{64})~', $message, $matches) === 1, 'Bot did not issue a secure root-path sign-in link: ' . $message);
    return $matches[1];
}

function signConfigure(string $min, string $max): void
{
    foreach (['signInMinAmount' => $min, 'signInMaxAmount' => $max] as $key => $value) {
        Db::name('config')->where('key', $key)->update(['value' => $value]);
    }
}

function signAssertReward(int $id, string $reward): void
{
    $user = Db::name('user')->where('id', $id)->find();
    $record = Db::name('finance_record')->where('userId', $id)->where('action', 4)->find();
    expectSign(Db::name('finance_record')->where('userId', $id)->where('action', 4)->count() === 1, 'Daily sign-in wrote multiple or missing ledger rows');
    expectSign(number_format((float) $user['rCoin'], 2, '.', '') === $reward && $record['count'] === $reward, 'Sign-in balance and finance record differ');
    expectSign((json_decode($user['userInfo'], true)['lastSignTime'] ?? null) === date('Y-m-d'), 'Daily sign-in date missing');
}

if (($argv[1] ?? '') === '--worker') {
    [$script, $mode, $root, $database, $workerId, $token] = $argv;
    $app = signApp($root, $database, 'worker-' . $workerId);
    signRequest($app, (int) $workerId <= 2 ? '/account/sign' : '/user/sign', ['signkey' => $token]);
    if ((int) $workerId > 2) Session::set('r_user', (new UserModel())->find(5));
    file_put_contents($root . '/ready-' . $workerId, 'ready');
    $deadline = microtime(true) + 15;
    while (!is_file($root . '/go')) {
        expectSign(microtime(true) < $deadline, 'Concurrency barrier timed out');
        usleep(10000);
    }
    $response = (int) $workerId <= 2 ? (new Account($app))->sign() : (new User($app))->sign();
    echo json_encode(signResult($response), JSON_THROW_ON_ERROR);
    exit;
}

$root = sys_get_temp_dir() . '/emby-signin-' . bin2hex(random_bytes(8));
mkdir($root);
$database = $root . '/signin.sqlite';
$bootstrap = new PDO('sqlite:' . $database);
$bootstrap = null;
$app = signApp($root, $database, 'api');
$workers = [];
try {
    foreach ([
        'user' => 'id INTEGER PRIMARY KEY, createdAt TEXT, updatedAt TEXT, userName TEXT, nickName TEXT, password TEXT, authority INTEGER, email TEXT, rCoin REAL, userInfo TEXT',
        'finance_record' => 'id INTEGER PRIMARY KEY, createdAt TEXT, updatedAt TEXT, userId INTEGER, action INTEGER, count TEXT, recordInfo TEXT',
        'config' => 'id INTEGER PRIMARY KEY, createdAt TEXT, updatedAt TEXT, appName TEXT, key TEXT, value TEXT, type INTEGER, status INTEGER',
        'telegram_user' => 'id INTEGER PRIMARY KEY, createdAt TEXT, updatedAt TEXT, userId INTEGER, telegramId TEXT, type INTEGER, userInfo TEXT',
    ] as $table => $columns) Db::execute('CREATE TABLE rc_' . $table . ' (' . $columns . ')');
    for ($id = 1; $id <= 12; $id++) {
        Db::name('user')->insert(['id' => $id, 'userName' => 'sign' . $id, 'nickName' => 'Sign ' . $id, 'password' => password_hash('fixture', PASSWORD_DEFAULT), 'authority' => 10, 'rCoin' => 0, 'userInfo' => json_encode(['loginIps' => ['127.0.0.1']])]);
        Db::name('telegram_user')->insert(['userId' => $id, 'telegramId' => 'telegram-' . $id, 'userInfo' => '{}']);
    }
    Db::name('config')->insertAll([['key' => 'signInMinAmount', 'value' => '0.37'], ['key' => 'signInMaxAmount', 'value' => '0.37']]);
    signRequest($app, '/account/sign');
    // The former time-based generator collided for accounts requesting links within one second.
    $first = signTokenFromBot($app, 'telegram-1');
    $second = signTokenFromBot($app, 'telegram-2');
    expectSign($first !== $second, 'Different users received the same sign-in credential');
    $credential = Cache::get('sign_token_' . hash('sha256', $first));
    expectSign($credential['userId'] === 1 && $credential['expiresAt'] > time() && $credential['expiresAt'] <= time() + 300, 'Credential identity or five-minute lifetime incorrect');
    Db::connect()->close();

    // Separate media runtime must render the API-issued credential without a login session.
    $app = signApp($root, $database, 'media');
    Session::clear();
    signRequest($app, '/account/sign', ['signkey' => $first], 'GET');
    $response = (new MediaAuth())->handle($app->request, fn() => (new Account($app))->sign());
    $html = $response->getContent();
    expectSign($response->getCode() === 200 && str_contains($html, 'name="signkey" value="' . $first . '"'), 'Anonymous token page did not render after application switch');
    expectSign(str_contains($html, "fetch('/account/sign'") && !str_contains($html, '/index/account/sign') && !str_contains($html, '/media/'), 'Token page still uses a removed route');
    expectSign(!str_contains($html, 'challenges.cloudflare.com/turnstile/v0/api.js') && SignInService::inspectToken($first)['code'] === 200, 'GET consumed the credential or requires unconfigured Turnstile');
    signRequest($app, '/account/sign', ['signkey' => $first]);
    expectSign(signResult((new Account($app))->sign())['code'] === 200, 'Token sign-in failed without Turnstile keys');
    signAssertReward(1, '0.37');
    expectSign(SignInService::claimToken($first)['code'] !== 200 && SignInService::claimUser(1)['code'] !== 200, 'Consumed token or another channel rewarded the same user again');
    signRequest($app, '/account/sign', ['signkey' => $second]);
    expectSign(signResult((new Account($app))->sign())['code'] === 200, 'First user consumed second user credential');
    signAssertReward(2, '0.37');

    // A committed hash blocks cache replay, including a request spanning midnight.
    Cache::set('sign_token_' . hash('sha256', $first), ['userId' => 1, 'expiresAt' => time() + 300], 300);
    $info = json_decode(Db::name('user')->where('id', 1)->value('userInfo'), true);
    $info['lastSignTime'] = date('Y-m-d', time() - 86400);
    Db::name('user')->where('id', 1)->update(['userInfo' => json_encode($info)]);
    expectSign(SignInService::claimToken($first)['message'] === '签到链接已失效', 'Replayed credential survived the committed token hash');
    expectSign(Db::name('finance_record')->where('userId', 1)->count() === 1, 'Token replay wrote another ledger row');

    // Both minimum zero and equal positive bounds are supported.
    signConfigure('0', '0.01');
    $zeroMinimum = SignInService::claimUser(3);
    expectSign($zeroMinimum['code'] === 200 && in_array($zeroMinimum['reward'], ['0.00', '0.01'], true), 'Zero minimum reward range rejected');
    signAssertReward(3, $zeroMinimum['reward']);
    signConfigure('0.29', '0.29');
    Session::set('r_user', (new UserModel())->find(4));
    signRequest($app, '/user/sign');
    expectSign(signResult((new User($app))->sign())['reward'] === '0.29', 'Web sign-in did not honor a fixed configured reward');
    signAssertReward(4, '0.29');
    expectSign(number_format((float) Session::get('r_user')['rCoin'], 2, '.', '') === '0.29', 'Web session balance was not refreshed');

    $disabledToken = SignInService::issueToken(6)['token'];
    signConfigure('0', '0');
    expectSign(SignInService::claimToken($disabledToken)['message'] === '签到已关闭' && SignInService::claimUser(6)['message'] === '签到已关闭', 'Existing token claimed after sign-in disabled');
    signConfigure('0.50', '0.10');
    expectSign(SignInService::issueToken(6)['message'] === '签到已关闭', 'Reversed reward bounds were accepted');
    signConfigure('0.17', '0.17');
    $bannedToken = SignInService::issueToken(7)['token'];
    Db::name('user')->where('id', 7)->update(['authority' => -1]);
    expectSign(SignInService::claimToken($bannedToken)['code'] === 403 && SignInService::claimUser(7)['code'] === 403 && SignInService::issueToken(7)['code'] === 403, 'Banned user can still sign in');
    expectSign(Db::name('finance_record')->whereIn('userId', [6, 7])->count() === 0, 'Closed or banned sign-in wrote a ledger row');

    $expired = SignInService::issueToken(8)['token'];
    Cache::set('sign_token_' . hash('sha256', $expired), ['userId' => 8, 'expiresAt' => time() - 1], 300);
    expectSign(SignInService::inspectToken($expired)['code'] === 400 && SignInService::claimToken($expired)['code'] === 400, 'Expired token can be consumed');
    expectSign(SignInService::claimToken(['malformed'])['code'] === 400 && SignInService::claimToken(str_repeat('0', 64))['code'] === 400, 'Malformed or unknown token was accepted');

    $environmentToken = SignInService::issueToken(9)['token'];
    signRequest($app, '/account/sign', ['signkey' => $environmentToken], 'POST', '192.0.2.1');
    expectSign(signResult((new Account($app))->sign())['code'] === 400 && SignInService::inspectToken($environmentToken)['code'] === 200, 'Unknown IP claimed a reward or burned a reusable credential');
    signRequest($app, '/account/sign');
    $rollbackToken = SignInService::issueToken(10)['token'];
    $before = Db::name('user')->where('id', 10)->find();
    Db::execute("CREATE TEMP TRIGGER fail_sign_finance BEFORE INSERT ON rc_finance_record BEGIN SELECT RAISE(ABORT, 'fixture rollback'); END");
    expectSign(SignInService::claimToken($rollbackToken)['message'] === '签到失败，请稍后重试', 'Finance insertion failure did not report sign-in failure');
    $after = Db::name('user')->where('id', 10)->find();
    expectSign($before['rCoin'] === $after['rCoin'] && $before['userInfo'] === $after['userInfo'] && Db::name('finance_record')->where('userId', 10)->count() === 0, 'Failed sign-in left balance, day or ledger changes');
    expectSign(SignInService::inspectToken($rollbackToken)['code'] === 200, 'Rollback permanently consumed the credential');
    Db::execute('DROP TRIGGER fail_sign_finance');
    expectSign(SignInService::claimToken($rollbackToken)['code'] === 200, 'Retry after rolled-back failure did not recover');
    signAssertReward(10, '0.17');

    // Missing Turnstile responses fail closed when both keys are configured, without making a network call.
    $captchaToken = SignInService::issueToken(11)['token'];
    $apiinfo = $app->config->get('apiinfo');
    $configured = $apiinfo;
    $configured['cloudflareTurnstile']['noninteractive'] = ['sitekey' => 'fixture-site', 'secret' => 'fixture-secret'];
    $app->config->set($configured, 'apiinfo');
    signRequest($app, '/account/sign', ['signkey' => $captchaToken]);
    expectSign(signResult((new Account($app))->sign())['code'] === 400 && SignInService::inspectToken($captchaToken)['code'] === 200, 'Missing configured captcha response consumed a credential');
    $app->config->set($apiinfo, 'apiinfo');

    // Two token requests and two authenticated web requests race for the same daily reward.
    $raceToken = SignInService::issueToken(5)['token'];
    for ($id = 1; $id <= 4; $id++) {
        $command = [PHP_BINARY, '-d', 'display_errors=stderr'];
        if (php_ini_loaded_file()) array_push($command, '-c', php_ini_loaded_file());
        array_push($command, __FILE__, '--worker', $root, $database, (string) $id, $raceToken);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        expectSign(is_resource($process), 'Unable to create cross-channel concurrency worker');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $deadline = microtime(true) + 15;
    while (count(glob($root . '/ready-*')) < 4) {
        expectSign(microtime(true) < $deadline, 'Concurrency workers did not reach barrier');
        usleep(10000);
    }
    file_put_contents($root . '/go', 'go');
    $successes = 0;
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        expectSign(proc_close($process) === 0, 'Sign-in worker failed: ' . $errors);
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $successes += $result['code'] === 200 ? 1 : 0;
        expectSign($result['code'] === 200 || in_array($result['message'], ['今日已签到，请明天再来', '签到链接已失效'], true), 'Concurrent request failed to serialize: ' . json_encode($result));
    }
    $workers = [];
    expectSign($successes === 1, 'Concurrent web and Telegram sign-in granted multiple daily rewards');
    signAssertReward(5, '0.17');
    echo "Sign-in regressions passed\n";
} finally {
    foreach ($workers as [$process, $pipes]) {
        if (is_resource($process)) {
            proc_terminate($process);
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_close($process);
        }
    }
    Db::connect()->close();
    $remove = function (string $path) use (&$remove): void {
        if (is_dir($path)) {
            foreach (scandir($path) as $entry) if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
            rmdir($path);
        } elseif (is_file($path)) unlink($path);
    };
    $remove($root);
}
