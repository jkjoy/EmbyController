<?php
/** php tests/user_profile.php: real profile controllers/middleware/cache and disposable migrated SQLite. */
$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';
require_once $projectRoot . '/vendor/topthink/framework/src/helper.php';
require_once $projectRoot . '/app/common.php';
require_once $projectRoot . '/app/media/common.php';

use app\media\controller\User;
use app\media\middleware\MediaAuth;
use app\media\model\UserModel;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Session;
use think\facade\View;

function expectProfile(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// Exercise the real Queue facade call without sending mail outside the fixture.
class ProfileQueueRecorder
{
    public array $jobs = [];
    public string $mode = 'ok';
    public function push($job, $data = '', $queue = null)
    {
        $this->jobs[] = [$job, $data, $queue];
        if ($this->mode === 'throw') throw new RuntimeException('private SMTP queue failure');
        return $this->mode === 'false' ? false : 0; // The real sync connector also returns zero.
    }
}

function profileApp(string $root, string $database): think\App
{
    $app = new think\App($root);
    $app->bind(require dirname(__DIR__) . '/app/provider.php');
    $app->setRuntimePath($root . '/runtime/');
    foreach (['app', 'cache', 'session', 'cookie', 'log', 'telegram', 'map', 'apiinfo', 'database', 'media', 'view', 'route', 'mailer'] as $name) {
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
    return $app;
}

function profileRequest(think\App $app, string $action, array $data = [], string $method = 'POST'): void
{
    $_GET = $_COOKIE = [];
    $_POST = $method === 'POST' ? $data : [];
    $_GET = $method === 'GET' ? $data : [];
    $_REQUEST = $data;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'profile.test';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['HTTP_USER_AGENT'] = 'Profile regression';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_SERVER['REQUEST_URI'] = '/media/user/' . $action;
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $request = app\Request::__make($app);
    $request->setMethod($method)->setController('User')->setAction($action)->setHost('profile.test');
    $app->instance('request', $request);
    $app->instance('think\Request', $request);
    $request->withSession($app->session);
}

function profileAuthenticate(?int $id = 2): void
{
    Session::clear();
    if ($id !== null) {
        $user = (new UserModel())->find($id);
        Session::set('r_user', $user ?: ['id' => $id, 'authority' => 10]);
        if ($user) Session::set('wskey', md5($user->id . $user->password));
    }
}

function profileResponse(think\App $app, string $action, bool $middleware = false): think\Response
{
    $next = fn() => (new User($app))->$action();
    try {
        $response = $middleware ? (new MediaAuth())->handle($app->request, $next) : $next();
    } catch (think\exception\HttpResponseException $error) {
        $response = $error->getResponse();
    }
    expectProfile($response instanceof think\Response, 'No response from ' . $action);
    return $response;
}

function profileCall(think\App $app, string $action, array $data, bool $middleware = false, string $method = 'POST'): array
{
    profileRequest($app, $action, $data, $method);
    return json_decode(profileResponse($app, $action, $middleware)->getContent(), true, 512, JSON_THROW_ON_ERROR);
}

function profilePayload(array $overrides = []): array
{
    $user = Db::name('user')->where('id', 2)->find();
    return array_replace(['username' => $user['userName'], 'nickname' => $user['nickName'], 'email' => $user['email'], 'password' => '', 'currentPassword' => '', 'confirmPassword' => '', 'verify' => '', 'profileToken' => Session::get('profileToken')], $overrides);
}

function profileRows(): array
{
    return Db::name('user')->order('id')->select()->toArray();
}

function profileDocument(string $html): DOMXPath
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    return new DOMXPath($document);
}

function profilePage(think\App $app): string
{
    profileRequest($app, 'userconfig', [], 'GET');
    View::assign('enableMoviepilot', false);
    $html = profileResponse($app, 'userconfig', true)->getContent();
    $token = Session::get('profileToken');
    expectProfile(is_string($token) && preg_match('/^[0-9a-f]{64}$/D', $token) === 1, 'Profile page did not issue secure session token');
    $inputs = profileDocument($html)->query('//input[@name="profileToken"]');
    expectProfile($inputs->length === 1 && $inputs[0]->getAttribute('value') === $token, 'Rendered profile token differs from session');
    expectProfile(!str_contains($html, Db::name('user')->where('id', 2)->value('password')), 'Profile page leaked password hash');
    return $html;
}

function profileLogin(think\App $app, string $identity, string $password, bool $success): void
{
    Session::clear();
    profileRequest($app, 'login', ['username' => $identity, 'password' => $password]);
    View::assign('user', null);
    View::assign('embyUser', null);
    View::assign('enableMoviepilot', false);
    $response = profileResponse($app, 'login');
    expectProfile(Session::has('r_user') === $success, 'Actual login credential mismatch for ' . $identity);
    if ($success) {
        expectProfile($response->getCode() === 302 && (int) Session::get('r_user')['id'] === 2, 'Login did not redirect/authenticate fixture account');
        $user = Db::name('user')->where('id', 2)->find();
        expectProfile(Session::get('wskey') === md5($user['id'] . $user['password']), 'Login websocket credential uses old password');
    }
}

$root = sys_get_temp_dir() . '/emby-user-profile-' . bin2hex(random_bytes(8));
$app = null;
mkdir($root);
try {
    foreach (['app', 'config', 'database/migrations', 'runtime/temp'] as $directory) mkdir($root . '/' . $directory, 0777, true);
    foreach (glob($projectRoot . '/config/*.php') as $file) file_put_contents($root . '/config/' . basename($file), '<?php return require ' . var_export($file, true) . ';');
    foreach (glob($projectRoot . '/database/migrations/*.php') as $file) copy($file, $root . '/database/migrations/' . basename($file));
    file_put_contents($root . '/app/service.php', '<?php return [\\think\\migration\\Service::class];');
    file_put_contents($root . '/app/provider.php', '<?php return require ' . var_export($projectRoot . '/app/provider.php', true) . ';');
    file_put_contents($root . '/think', '<?php require ' . var_export($projectRoot . '/vendor/autoload.php', true) . '; (new \\think\\App(__DIR__))->console->run();');
    $database = $root . '/data/profile.sqlite';
    $command = [PHP_BINARY];
    if (php_ini_loaded_file()) array_push($command, '-c', php_ini_loaded_file());
    array_push($command, '-d', 'variables_order=EGPCS', $root . '/think', 'migrate:run');
    $environment = array_filter(getenv() ?: [], fn($key) => !preg_match('/^(DB_|APP_|EMBY_|TG_|MAIL_|REDIS_)/', $key), ARRAY_FILTER_USE_KEY);
    $environment = array_merge($environment, ['DB_DRIVER' => 'sqlite', 'DB_TYPE' => 'sqlite', 'DB_NAME' => $database, 'DB_PREFIX' => 'rc_']);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment, ['bypass_shell' => true]);
    expectProfile(is_resource($process), 'Cannot start real migrations');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    expectProfile(proc_close($process) === 0, 'Real migrations failed: ' . $output);
    $app = profileApp($root, $database);
    $queue = new ProfileQueueRecorder();
    $app->instance('queue', $queue);
    foreach ([2 => ['profileuser', 'old@example.test', 10], 3 => ['otheruser', 'CaseTaken@Example.test', 11], 4 => ['banneduser', 'banned@example.test', -1]] as $id => [$username, $email, $authority]) {
        Db::name('user')->insert(['id' => $id, 'userName' => $username, 'nickName' => 'Original Nick', 'password' => password_hash('OldPass_123', PASSWORD_DEFAULT), 'email' => $email, 'authority' => $authority, 'rCoin' => 52.25, 'userInfo' => json_encode(['loginIps' => ['127.0.0.1']])]);
    }
    profileAuthenticate();
    $html = profilePage($app);
    $token = Session::get('profileToken');
    $xpath = profileDocument($html);
    expectProfile(!$xpath->query('//input[@name="email"]')[0]->hasAttribute('disabled'), 'No-SMTP page disabled email editing');
    profilePage($app);
    expectProfile(Session::get('profileToken') === $token, 'Refreshing page unexpectedly invalidated token');
    Session::save();
    Session::clear();
    Session::init();
    expectProfile(Session::get('profileToken') === $token && (int) Session::get('r_user')['id'] === 2, 'File session lost token/authentication after save/load');
    $before = profileRows();
    foreach ([['profileToken' => ''], ['profileToken' => str_repeat('f', 64)], ['email' => 'new@example.test'], ['email' => 'new@example.test', 'currentPassword' => 'wrong'], ['email' => 'broken-email'], ['email' => ['bad']], ['email' => 'casetaken@example.TEST', 'currentPassword' => 'OldPass_123'], ['username' => 'otheruser'], ['username' => 'bad name'], ['nickname' => 'x'], ['password' => 'short', 'confirmPassword' => 'short', 'currentPassword' => 'OldPass_123'], ['password' => str_repeat('a', 41), 'confirmPassword' => str_repeat('a', 41), 'currentPassword' => 'OldPass_123'], ['password' => 'New!Pass', 'confirmPassword' => 'New!Pass', 'currentPassword' => 'OldPass_123'], ['password' => 'NewPass_123', 'confirmPassword' => 'different', 'currentPassword' => 'OldPass_123'], ['password' => 'NewPass_123', 'confirmPassword' => 'NewPass_123'], ['password' => ['bad']], ['id' => 3, 'rCoin' => 999, 'authority' => 0], ['unknown' => 'value']] as $invalid) {
        $result = profileCall($app, 'update', profilePayload($invalid));
        expectProfile($result['code'] !== 200 && profileRows() === $before, 'Rejected profile input modified accounts: ' . json_encode($invalid) . ' ' . json_encode($result));
    }
    $missingToken = profilePayload();
    unset($missingToken['profileToken']);
    expectProfile(profileCall($app, 'update', $missingToken)['code'] === 403 && profileRows() === $before, 'Missing profile token was accepted');
    expectProfile(profileCall($app, 'update', profilePayload(), false, 'GET')['code'] === 405 && profileRows() === $before, 'GET profile update changed account');
    foreach ([[null, 401], [4, 403], [999, 401]] as [$actor, $status]) {
        profileAuthenticate($actor);
        $data = profilePayload(['profileToken' => $token]);
        expectProfile(profileCall($app, 'update', $data)['code'] === $status, 'Controller allowed missing/banned/unknown actor');
        profileAuthenticate($actor);
        profileRequest($app, 'update', $data);
        $response = profileResponse($app, 'update', true);
        expectProfile($response->getCode() === $status && json_decode($response->getContent(), true)['code'] === $status, 'Middleware profile status is not JSON ' . $status);
        expectProfile(profileRows() === $before, 'Unauthorized profile operation changed data');
    }
    echo "PASS: session token, input/confirmation/current-password validation, case duplicate and real middleware access control.\n";

    profileAuthenticate();
    profilePage($app);
    $hash = Db::name('user')->where('id', 2)->value('password');
    $result = profileCall($app, 'update', profilePayload(['nickname' => 'Updated Nick', 'email' => ' OLD@example.test ']));
    expectProfile($result['code'] === 200 && !$result['requireLogin'] && Db::name('user')->where('id', 2)->value('email') === 'old@example.test' && Db::name('user')->where('id', 2)->value('password') === $hash, 'Unchanged security fields required current password or changed hash/email: ' . json_encode($result));
    $result = profileCall($app, 'update', profilePayload(['email' => ' ', 'password' => 'OldPass_123', 'confirmPassword' => 'OldPass_123']));
    expectProfile($result['code'] === 200 && !$result['requireLogin'] && Db::name('user')->where('id', 2)->value('email') === 'old@example.test' && Db::name('user')->where('id', 2)->value('password') === $hash, 'Blank email or same password changed security fields');
    $longEmail = str_repeat('a', 45) . '@example.com';
    $result = profileCall($app, 'update', profilePayload(['email' => $longEmail, 'currentPassword' => 'OldPass_123']));
    expectProfile($result['code'] === 200 && !$result['requireLogin'] && Db::name('user')->where('id', 2)->value('email') === $longEmail && Db::name('user')->where('id', 2)->value('password') === $hash && count($queue->jobs) === 0, 'No-SMTP verified-current-password email change failed');
    profileLogin($app, 'old@example.test', 'OldPass_123', false);
    profileLogin($app, $longEmail, 'OldPass_123', true);
    profilePage($app);
    $oldUser = Session::get('r_user');
    $oldWskey = Session::get('wskey');
    Session::set('m_embyId', 'fixture-emby');
    $result = profileCall($app, 'update', profilePayload(['password' => 'NewPass.123_-', 'confirmPassword' => 'NewPass.123_-', 'currentPassword' => 'OldPass_123']));
    $stored = Db::name('user')->where('id', 2)->find();
    expectProfile($result['code'] === 200 && $result['requireLogin'] === true && $result['redirectUrl'] === '/media/user/login', 'Password update did not require fresh login');
    expectProfile(password_verify('NewPass.123_-', $stored['password']) && !password_verify('OldPass_123', $stored['password']), 'Password update failed to hash or revoke old password');
    foreach (['r_user', 'wskey', 'm_embyId', 'profileToken'] as $key) expectProfile(!Session::has($key), 'Password change retained credential ' . $key);
    expectProfile((float) $stored['rCoin'] === 52.25 && (int) $stored['authority'] === 10 && Db::name('user')->where('id', 3)->find() === $before[2], 'Profile change modified financial/privilege/other-user fields');
    profileLogin($app, $longEmail, 'OldPass_123', false);
    profileLogin($app, 'old@example.test', 'NewPass.123_-', false);
    profileLogin($app, $longEmail, 'NewPass.123_-', true);
    Session::clear();
    Session::set('r_user', $oldUser);
    Session::set('wskey', $oldWskey);
    profileRequest($app, 'update', ['profileToken' => $token]);
    $response = profileResponse($app, 'update', true);
    expectProfile($response->getCode() === 401 && !Session::has('r_user') && !Session::has('wskey'), 'Other session survived password rotation');
    echo "PASS: no-SMTP email update, real old/new credential login and password rotation revoking session/websocket keys.\n";

    profileAuthenticate();
    $app->config->set(['enable' => true, 'host' => 'smtp.invalid', 'username' => 'fixture', 'password' => 'fixture', 'port' => 587, 'from' => ['address' => 'sender@example.test', 'name' => 'Fixture']], 'mailer');
    $html = profilePage($app);
    $xpath = profileDocument($html);
    expectProfile($xpath->query('//*[@id="sendVerifyCode"]')->length === 1 && !$xpath->query('//input[@name="email"]')[0]->hasAttribute('disabled'), 'SMTP profile page lacks email verification controls');
    $target = 'verified@example.test';
    $key = 'verifyCode_update_2_' . $target;
    $send = ['action' => 'update', 'email' => ' Verified@Example.test ', 'currentPassword' => 'NewPass.123_-', 'profileToken' => Session::get('profileToken')];
    $before = profileRows();
    $attempts = count($queue->jobs);
    expectProfile(profileCall($app, 'sendVerifyCode', $send, false, 'GET')['code'] === 405, 'GET verification sent mail');
    $app->config->set(['enable' => false], 'mailer');
    expectProfile(profileCall($app, 'sendVerifyCode', $send)['code'] === 400 && count($queue->jobs) === $attempts && !Cache::has($key), 'Disabled SMTP issued code');
    $app->config->set(['enable' => true], 'mailer');
    $senderToken = Session::get('profileToken');
    foreach ([[null, 401], [4, 403]] as [$actor, $status]) {
        profileAuthenticate($actor);
        expectProfile(profileCall($app, 'sendVerifyCode', $send, true)['code'] === $status && count($queue->jobs) === $attempts && !Cache::has($key), 'Unauthenticated/banned verifier sent code');
    }
    profileAuthenticate();
    Session::set('profileToken', $senderToken);
    foreach ([['action' => 'other'], ['currentPassword' => 'wrong'], ['profileToken' => 'wrong'], ['email' => 'broken'], ['email' => 'CaseTaken@Example.TEST'], ['email' => $longEmail]] as $invalid) {
        $attempts = count($queue->jobs);
        $result = profileCall($app, 'sendVerifyCode', array_replace($send, $invalid));
        expectProfile($result['code'] !== 200 && count($queue->jobs) === $attempts && profileRows() === $before && !Cache::has($key), 'Invalid verification sender queued/cached data');
    }
    foreach (['false', 'throw'] as $mode) {
        $queue->mode = $mode;
        $result = profileCall($app, 'sendVerifyCode', $send);
        expectProfile($result['code'] === 400 && !Cache::has($key) && !str_contains(json_encode($result), 'private SMTP'), 'Failed queue left usable verification code or leaked error');
    }
    $queue->mode = 'ok';
    $result = profileCall($app, 'sendVerifyCode', $send, true);
    $verification = Cache::get($key);
    expectProfile($result['code'] === 200 && is_string($verification) && preg_match('/^[0-9]{6}$/D', $verification) === 1, 'Verification was not six-digit user-bound cached string');
    [$job, $data, $channel] = $queue->jobs[count($queue->jobs) - 1];
    expectProfile($job === 'app\\api\\job\\SendMailMessage' && $channel === 'main' && $data['to'] === $target && str_contains($data['content'], $verification), 'Actual Queue facade got wrong mail job/target/code');
    expectProfile(!Cache::has('verifyCode_update_' . $target), 'Update verification retained unbound legacy cache key');
    $attempts = count($queue->jobs);
    expectProfile(profileCall($app, 'sendVerifyCode', $send)['code'] === 400 && count($queue->jobs) === $attempts, 'Active verification could be sent repeatedly');
    foreach (['', '12345', '1234567', ['123456'], $verification === '123456' ? '654321' : '123456'] as $invalid) {
        $result = profileCall($app, 'update', profilePayload(['email' => $target, 'currentPassword' => 'NewPass.123_-', 'verify' => $invalid]));
        expectProfile($result['code'] === 400 && profileRows() === $before && Cache::get($key) === $verification, 'Invalid SMTP code changed account/consumed correct code');
    }
    Cache::delete($key);
    Cache::set('verifyCode_update_3_' . $target, $verification, 300);
    Cache::set('verifyCode_update_2_wrong@example.test', $verification, 300);
    Cache::set('verifyCode_update_' . $target, $verification, 300);
    expectProfile(profileCall($app, 'update', profilePayload(['email' => $target, 'currentPassword' => 'NewPass.123_-', 'verify' => $verification]))['code'] === 400 && profileRows() === $before, 'Wrong actor/email/legacy cached code was accepted');
    Cache::set($key, '', 300);
    expectProfile(profileCall($app, 'update', profilePayload(['email' => $target, 'currentPassword' => 'NewPass.123_-', 'verify' => '']))['code'] === 400 && profileRows() === $before, 'Empty cached and provided codes bypassed verification');
    Cache::set($key, $verification, -1);
    expectProfile(profileCall($app, 'update', profilePayload(['email' => $target, 'currentPassword' => 'NewPass.123_-', 'verify' => $verification]))['code'] === 400 && profileRows() === $before, 'Expired real file-cache code was accepted');
    Cache::set($key, $verification, 300);
    Db::execute("CREATE TEMP TRIGGER fail_profile_update BEFORE UPDATE ON rc_user BEGIN SELECT RAISE(ABORT, 'private profile database failure'); END");
    $result = profileCall($app, 'update', profilePayload(['email' => $target, 'nickname' => 'Should Rollback', 'password' => 'RollbackPass_123', 'confirmPassword' => 'RollbackPass_123', 'currentPassword' => 'NewPass.123_-', 'verify' => $verification]));
    expectProfile($result['code'] !== 200 && profileRows() === $before && Cache::get($key) === $verification && !str_contains(json_encode($result), 'private profile'), 'Database failure left partial profile/consumed code/exposed SQL');
    Db::execute('DROP TRIGGER fail_profile_update');
    $result = profileCall($app, 'update', profilePayload(['email' => $target, 'currentPassword' => 'NewPass.123_-', 'verify' => $verification]));
    expectProfile($result['code'] === 200 && !Cache::has($key) && Db::name('user')->where('id', 2)->value('email') === $target, 'Successful verified email update did not consume code');
    profileLogin($app, $longEmail, 'NewPass.123_-', false);
    profileLogin($app, $target, 'NewPass.123_-', true);
    echo "PASS: real file-cache OTP ownership/strictness/one-time use, Queue dispatch and failure cleanup, SMTP email/SQL rollback.\n";

    // The shared profile validator must still allow the existing password-reset flow.
    Session::clear();
    Cache::set('verifyCode_forgot_' . $target, 654321, 300);
    profileRequest($app, 'forgot', ['email' => $target, 'password' => 'ResetPass_456', 'code' => '654321']);
    View::assign('user', null);
    View::assign('embyUser', null);
    $response = profileResponse($app, 'forgot');
    expectProfile(str_contains($response->getContent(), '密码重置成功') && password_verify('ResetPass_456', Db::name('user')->where('id', 2)->value('password')), 'Shared validator broke the existing password-reset flow');
    profileLogin($app, $target, 'NewPass.123_-', false);
    profileLogin($app, $target, 'ResetPass_456', true);
    echo "PASS: existing email password reset and subsequent real login remain compatible.\n";
} finally {
    if ($app !== null) Db::connect()->close();
    $remove = function (string $path) use (&$remove): void {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $entry) if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
            rmdir($path);
        } elseif (file_exists($path)) unlink($path);
    };
    $remove($root);
}
