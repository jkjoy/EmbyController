<?php
/**
 * Run: php tests/system_settings_integration.php
 * Requires installed Composer dependencies and PDO SQLite.
 * Uses a temporary SQLite database and runtime directory, never the deployment database.
 */
$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';
require_once $projectRoot . '/vendor/topthink/framework/src/helper.php';
require_once $projectRoot . '/app/common.php';
use app\service\SystemSettings;
use app\media\controller\Admin;
use think\facade\Db;
use think\facade\Session;
use think\facade\View;

function expect($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function requestFixture(think\App $app, string $method, array $data = []): void {
    $_GET = $_COOKIE = [];
    $_POST = $_REQUEST = $data;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['HTTP_HOST'] = 'settings.example.com';
    $_SERVER['SERVER_NAME'] = 'settings.example.com';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_SERVER['REQUEST_URI'] = '/media/admin/setting';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $request = app\Request::__make($app);
    $request->setMethod($method)->setController('Admin')->setAction('setting')->setHost('settings.example.com');
    $app->instance('request', $request);
    $app->instance('think\Request', $request);
    $request->withSession($app->session);
}
function postSettings(think\App $app, array $data): array {
    requestFixture($app, 'POST', $data);
    $response = (new Admin($app))->setting();
    return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
}
function settingHtml(think\App $app): string {
    requestFixture($app, 'GET');
    View::assign('user', new app\media\model\UserModel(['id' => 1, 'userName' => 'admin', 'nickName' => 'Admin', 'authority' => 0]));
    View::assign('embyUser', null);
    View::assign('enableMoviepilot', false);
    return (new Admin($app))->setting()->getContent();
}
function controls(string $html): array {
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);
    $controls = [];
    foreach ($xpath->query('//input[@name] | //select[@name] | //textarea[@name]') as $node) {
        $name = $node->getAttribute('name');
        if ($name === 'clearSecrets[]') {
            continue;
        }
        $controls[$name][] = $node;
    }
    return [$controls, $xpath];
}
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'emby-settings-integration-' . bin2hex(random_bytes(6));
mkdir($temporaryRoot);
mkdir($temporaryRoot . '/runtime');
mkdir($temporaryRoot . '/runtime/temp');
$app = new think\App($temporaryRoot);
$app->setRuntimePath($temporaryRoot . '/runtime/');
foreach (['app','cache','queue','media','mailer','payment','telegram','proxy','ai','gemini','map','apiinfo','lang','session','view','route','cookie','log','filesystem'] as $name) {
    $config = require $projectRoot . '/config/' . $name . '.php';
    if ($name === 'view') {
        $config['view_path'] = $projectRoot . '/app/media/view/';
        $config['tpl_cache'] = false;
        $config['cache_path'] = $temporaryRoot . '/runtime/temp/';
    }
    if ($name === 'cache') {
        $config['stores']['file']['path'] = $temporaryRoot . '/runtime/cache/';
    }
    if ($name === 'session') {
        $config['path'] = $temporaryRoot . '/runtime/session/';
    }
    $app->config->set($config, $name);
}
$app->config->set(['default' => 'sqlite', 'connections' => ['sqlite' => ['type' => 'sqlite', 'database' => $temporaryRoot . '/settings.sqlite', 'prefix' => 'rc_', 'trigger_sql' => false]]], 'database');
Db::execute('CREATE TABLE rc_config (id INTEGER PRIMARY KEY AUTOINCREMENT, createdAt TEXT, updatedAt TEXT, appName TEXT, "key" TEXT, value TEXT, type INTEGER, status INTEGER)');
$originalEnvironment = $_ENV;
$savedLegacyEnvironment = [];
foreach (getenv() ?: [] as $key => $value) {
    if (preg_match('/^(APP_|POWERED_BY|IS_DOCKER|CRONTAB_KEY|CACHE_TYPE|REDIS_|SOCKS5_|MAIL_|EMBY_|PAY_|AVAILABLE_PAYMENT_|TG_|XFYUNLIST_|CLOUDFLARE_TURNSTILE_|TENCENT_MAP_|GEMINI_|AI_|MOVIEPILOT_|DEFAULT_LANG)/i', $key)) {
        $savedLegacyEnvironment[$key] = $value;
        putenv($key);
    }
}
$_ENV = [];
try {
    $defaults = SystemSettings::all(true);
    expect(count($defaults) === count(SystemSettings::definitions()), 'Missing initialization defaults');
    expect($defaults['appHost'] === '' && $defaults['cacheType'] === 'file' && !$defaults['moviepilotEnabled'], 'Unsafe bootstrap defaults');
    expect(strlen($defaults['crontabKey']) === 64, 'Random crontab key was not initialized');
    expect(Db::name('config')->count() === count($defaults), 'Unexpected rows after initialization');
    $rowCount = Db::name('config')->count();
    SystemSettings::all(true);
    expect(Db::name('config')->count() === $rowCount, 'Initialization is not idempotent');
    SystemSettings::apply($app, true);
    Session::set('r_user', new app\media\model\UserModel(['id' => 1, 'userName' => 'admin', 'authority' => 0]));
    $html = settingHtml($app);
    [$formControls] = controls($html);
    foreach (SystemSettings::definitions() as $key => $field) {
        if ($key === 'telegramRules') { expect(str_contains($html, 'id="telegramRulesList"'), 'Missing legacy rule controls'); continue; }
        expect(isset($formControls[$key]), 'No form control for ' . $key);
        if ($key !== 'telegramRules') {
            expect(count($formControls[$key]) === 1, 'Duplicate form control for ' . $key);
        }
    }
    expect(str_contains($html, '重置为随机密钥'), 'Missing crontab reset explanation');
    echo "PASS: SQLite initialization/idempotence; Admin GET and full layout render; every definition has exactly one control.\n";

    $secret = 'integration-secret-marker-73129';
    $xfyunSecret = 'integration-xfyun-marker-42819';
    $response = postSettings($app, [
        'siteName' => 'Integration Site', 'siteSubtitle' => '', 'appHost' => 'https://settings.example.com',
        'embyUrlBase' => 'http://emby.example.com:8096/emby/', 'embyApiKey' => $secret,
        'embyLineList' => '[{"name":"直接访问","url":"https://emby.example.com"}]',
        'payMethods' => '["alipay"]', 'xfyunList' => json_encode(['test' => ['appid' => 'test-app', 'apikey' => $xfyunSecret, 'apisecret' => 'test-signature']]),
        'appDebug' => '0', 'redisDb' => '8', 'clientList' => '["Emby"]', 'clientBlackList' => '[]',
        'telegramRules' => '[]', 'signInMinAmount' => '0', 'signInMaxAmount' => '1.5',
    ]);
    expect($response['code'] === 200, 'Valid Admin POST failed: ' . json_encode($response));
    $values = SystemSettings::all(true);
    expect($values['siteName'] === 'Integration Site' && $values['embyApiKey'] === $secret && $values['redisDb'] === 8, 'Valid POST not stored');
    expect($values['clientList'] === ['Emby'] && $values['embyLineList'][0]['name'] === '直接访问', 'JSON settings were not typed');
    expect($app->config->get('app.app_host') === 'https://settings.example.com' && $app->config->get('media.apiKey') === $secret, 'POST did not apply runtime configuration');
    $html = settingHtml($app);
    expect(!str_contains($html, $secret) && !str_contains($html, $xfyunSecret), 'Secret leaked in complete Admin GET response');
    [$formControls, $xpath] = controls($html);
    expect($formControls['embyApiKey'][0]->getAttribute('value') === '', 'Password was echoed');
    expect($formControls['xfyunList'][0]->textContent === '', 'Secret JSON was echoed');
    expect(str_contains($formControls['embyLineList'][0]->textContent, '直接访问'), 'JSON form did not return saved value');
    expect($xpath->query('//*[@data-secret-status="embyApiKey" and contains(text(), "已配置")]')->length === 1, 'Secret state missing');
    echo "PASS: Admin POST persists typed settings and applies configuration; full HTML hides password and JSON secrets.\n";

    $response = postSettings($app, ['embyApiKey' => '', 'xfyunList' => '']);
    expect($response['code'] === 200 && SystemSettings::get('embyApiKey') === $secret && SystemSettings::get('xfyunList')['test']['apikey'] === $xfyunSecret, 'Blank secrets were not preserved');
    $before = SystemSettings::all(true);
    foreach ([
        ['siteName' => 'Must Not Save', 'embyLineList' => '{broken-json', 'embyApiKey' => 'Must Not Save Secret'],
        ['siteName' => 'Must Not Save', 'appHost' => 'javascript:alert(1)'],
        ['siteName' => 'Must Not Save', 'DB_PASS' => 'attempted-env-override'],
        ['siteName' => 'Must Not Save', 'signInMinAmount' => '3', 'signInMaxAmount' => '1'],
        ['siteName' => 'Must Not Save', 'clearSecrets' => 'bad-shape'],
    ] as $invalid) {
        $response = postSettings($app, $invalid);
        expect($response['code'] === 400, 'Invalid Admin POST was accepted');
        expect(SystemSettings::all(true) === $before, 'Validation failure changed saved settings');
        expect(!str_contains(json_encode($response), $secret), 'Validation message exposed a secret');
    }
    echo "PASS: Blank secrets preserve old values; invalid JSON/URL/unknown keys/sign-in bounds/clear parameters fail atomically.\n";

    Db::execute("CREATE TEMP TRIGGER deny_config_update BEFORE UPDATE ON rc_config BEGIN SELECT RAISE(ABORT, 'internal-secret-database-error'); END");
    $response = postSettings($app, ['siteName' => 'Must Not Save Database Failure']);
    expect($response['code'] === 400 && $response['message'] === '设置保存失败，请检查数据库连接或稍后重试', 'Database failure was not generalized');
    expect(!str_contains(json_encode($response), 'internal-secret-database-error'), 'Internal database error was exposed');
    expect(SystemSettings::get('siteName') === 'Integration Site', 'Database failure changed data');
    Db::execute('DROP TRIGGER deny_config_update');
    $response = postSettings($app, ['siteDescription' => '</textarea><script>alert("test")</script>', 'siteKeywords' => 'quotes" & <tags>']);
    expect($response['code'] === 200, 'Plain text HTML fixture could not be saved');
    $html = settingHtml($app);
    expect(!str_contains($html, '</textarea><script>alert("test")</script>'), 'HTML text was not escaped in complete page');
    expect(str_contains($html, '&lt;script&gt;'), 'Escaped HTML fixture did not render');
    echo "PASS: Database errors are generalized; complete page escapes saved HTML text.\n";

    $oldCrontabKey = SystemSettings::get('crontabKey');
    $response = postSettings($app, ['embyApiKey' => 'ignored-on-clear', 'clearSecrets' => ['embyApiKey','xfyunList','crontabKey']]);
    expect($response['code'] === 200 && SystemSettings::get('embyApiKey') === '' && SystemSettings::get('xfyunList') === [], 'Secret clearing failed');
    expect(SystemSettings::get('crontabKey') !== $oldCrontabKey && strlen(SystemSettings::get('crontabKey')) === 64, 'Crontab key clear did not reset');
    expect(SystemSettings::formData()['secretStates']['crontabKey'], 'Reset crontab key not configured');
    Session::set('r_user', ['id' => 2, 'authority' => 1]);
    requestFixture($app, 'POST', ['siteName' => 'Unauthorized']);
    try {
        new Admin($app);
        throw new RuntimeException('Non-admin was accepted');
    } catch (think\exception\HttpResponseException $error) {
        expect(json_decode($error->getResponse()->getContent(), true)['code'] === 400, 'Non-admin denial response missing');
    }
    expect(SystemSettings::get('siteName') === 'Integration Site', 'Unauthorized request changed settings');
    echo "PASS: Explicit clear/reset and administrator access enforcement.\n";
} finally {
    $_ENV = $originalEnvironment;
    foreach ($savedLegacyEnvironment as $key => $value) { putenv($key . '=' . $value); }
    $app->delete('db');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporaryRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }
    @rmdir($temporaryRoot);
}
