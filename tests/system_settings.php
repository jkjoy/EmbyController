<?php

// php tests/system_settings.php；仅使用临时 SQLite，不读取部署数据库。
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';

use app\service\SystemSettings;
use think\facade\Db;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejects(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException $e) {
        return;
    }
    throw new RuntimeException($message);
}

$testRoot = sys_get_temp_dir() . '/emby-settings-' . bin2hex(random_bytes(8));
mkdir($testRoot);
$originalEnvironment = $_ENV;
$legacyNames = array_column(SystemSettings::definitions(), 'legacy');
$savedEnvironment = [];
foreach (getenv() ?: [] as $name => $value) {
    if (in_array($name, $legacyNames, true) || preg_match('/^(EMBY_LINE_LIST_|AVAILABLE_PAYMENT_|XFYUNLIST_)/', $name)) {
        $savedEnvironment[$name] = $value;
        putenv($name);
    }
}
$_ENV = [];
$database = $testRoot . '/settings.sqlite';
$app = new think\App($testRoot);
foreach (glob(dirname(__DIR__) . '/config/*.php') as $file) {
    $app->config->set(require $file, pathinfo($file, PATHINFO_FILENAME));
}
$db = new think\DbManager();
$db->setConfig(['default' => 'test', 'connections' => ['test' => [
    'type' => 'sqlite', 'database' => $database, 'prefix' => 'rc_',
    'fields_strict' => true, 'trigger_sql' => false,
]]]);
$app->instance('think\DbManager', $db);
Db::execute('CREATE TABLE rc_config (id INTEGER PRIMARY KEY AUTOINCREMENT, createdAt TEXT, updatedAt TEXT, appName TEXT, key TEXT, value TEXT, type INTEGER, status INTEGER)');

try {
    file_put_contents($testRoot . '/.env', <<<'ENV'
APP_NAME="Legacy Title"
APP_SUBTITLE=""
APP_HOST="legacy.example.com/media/"
APP_DEBUG=false
CACHE_TYPE=file
REDIS_DB=0
EMBY_APIKEY="legacy secret"
EMBY_LINE_LIST_2_NAME="Second"
EMBY_LINE_LIST_2_URL="https://second.example.com"
EMBY_LINE_LIST_1_NAME="First"
EMBY_LINE_LIST_1_URL="https://first.example.com"
AVAILABLE_PAYMENT_2=wxpay
AVAILABLE_PAYMENT_1=alipay
XFYUNLIST_PRIMARY_APPID=id
XFYUNLIST_PRIMARY_APIKEY=key
XFYUNLIST_PRIMARY_APISECRET=secret
ENV
    );
    Db::name('config')->insert(['key' => 'siteName', 'value' => 'Database Title', 'type' => 1, 'status' => 1]);
    $settings = SystemSettings::all(true);
    check($settings['siteName'] === 'Database Title', 'Database must win over legacy env');
    check($settings['siteSubtitle'] === '' && $settings['redisDb'] === 0 && $settings['appDebug'] === false, 'Empty/zero/false must survive import');
    check($settings['appHost'] === 'http://legacy.example.com', 'Legacy host must be normalized');
    Db::name('config')->where('key', 'appHost')->update(['value' => 'https://stored.example.com:8090/media/']);
    check(SystemSettings::all(true)['appHost'] === 'https://stored.example.com:8090', 'Stored legacy host must also generate root routes');
    SystemSettings::save(['appHost' => 'https://stored.example.com:8090/media/']);
    check(Db::name('config')->where('key', 'appHost')->value('value') === 'https://stored.example.com:8090', 'Saving a legacy host must remove its media prefix');
    check($settings['embyLineList'][0]['name'] === 'First' && $settings['payMethods'] === ['alipay', 'wxpay'], 'Indexed legacy lists must be ordered');
    check($settings['xfyunList']['primary']['apisecret'] === 'secret', 'Legacy nested credentials must import');
    check(strlen($settings['crontabKey']) === 64, 'Missing cron secret must be generated');
    $count = Db::name('config')->count();
    check($count === count(SystemSettings::definitions()), 'Defaults must include all settings, even empty values');
    SystemSettings::initialize();
    check(Db::name('config')->count() === $count, 'Initialization must be idempotent');
    file_put_contents($testRoot . '/.env', "APP_NAME=Changed\nEMBY_APIKEY=changed\n");
    check(SystemSettings::importLegacy() === [], 'Repeated migration must preserve database values');
    check(SystemSettings::all(true)['embyApiKey'] === 'legacy secret', 'Env must never override a saved key');

    $form = SystemSettings::formData();
    $serializedForm = json_encode($form, JSON_UNESCAPED_UNICODE);
    check(!str_contains($serializedForm, 'legacy secret') && !str_contains($serializedForm, '"primary"'), 'Form must never contain credentials');
    check($form['settings']['embyApiKey'] === '' && $form['secretStates']['embyApiKey'], 'Form must report only credential state');
    $dynamicKeys = array_merge(...array_values(array_map(fn($fields) => array_column($fields, 'key'), $form['settingSections'])));
    check(!in_array('siteName', $dynamicKeys, true) && !in_array('telegramRules', $dynamicKeys, true) && count($dynamicKeys) === count(array_unique($dynamicKeys)), 'Existing form controls must not be duplicated');

    SystemSettings::save(['embyApiKey' => '', 'xfyunList' => '', 'siteSubtitle' => '', 'chargeRate' => '0', 'avableRegisterCount' => '0']);
    $settings = SystemSettings::all(true);
    check($settings['embyApiKey'] === 'legacy secret' && $settings['xfyunList']['primary']['apikey'] === 'key', 'Blank secrets must preserve stored values');
    check($settings['chargeRate'] === 0.0 && $settings['avableRegisterCount'] === 0, 'Explicit zero values must be saved');
    SystemSettings::save(['signInMinAmount' => '1', 'signInMaxAmount' => '2']);
    SystemSettings::save(['signInMaxAmount' => '0']);
    check(SystemSettings::all(true)['signInMaxAmount'] === 0.0, 'Setting only the maximum to zero must disable sign-in with a positive existing minimum');
    SystemSettings::save(['signInMinAmount' => '0']);
    SystemSettings::save(['embyApiKey' => 'replacement'], ['embyApiKey', 'xfyunList', 'crontabKey']);
    $reset = SystemSettings::all(true);
    check($reset['embyApiKey'] === '' && $reset['xfyunList'] === [], 'Explicit clear must take precedence');
    check($reset['crontabKey'] !== $settings['crontabKey'] && strlen($reset['crontabKey']) === 64, 'Cron clear must rotate secret');

    $originalTitle = $reset['siteName'];
    foreach ([
        ['siteName' => 'Should Roll Back', 'DB_HOST' => 'untrusted'],
        ['siteName' => 'Should Roll Back', 'redisPort' => '65536'],
        ['siteName' => 'Should Roll Back', 'redisDb' => '-1'],
        ['siteName' => ''],
        ['siteLogo' => 'javascript:alert(1)'],
        ['siteFavicon' => '//external.example/icon.png'],
        ['siteLogo' => '/\\external.example/icon.png'],
        ['embyUrlBase' => 'ftp://example.com'],
        ['mailFromEmail' => 'invalid'],
        ['payMethods' => '{"not":"a list"}'],
        ['embyLineList' => '[{"name":"bad","url":"javascript:alert(1)"}]'],
        ['tgWebhookSecret' => 'spaces invalid'],
        ['signInMinAmount' => '5', 'signInMaxAmount' => '2'],
        ['signInMinAmount' => '0.001', 'signInMaxAmount' => '1'],
        ['signInMaxAmount' => '1e0'],
        ['appHost' => 'https://example.com/emby'],
        ['appHost' => 'https://example.com/?next=/media'],
        ['appHost' => 'https://example.com/#media'],
        ['cacheType' => 'unsupported'],
        ['mailUseSocks5' => true],
    ] as $invalid) {
        rejects(fn() => SystemSettings::save($invalid), 'Invalid settings must be rejected');
        check(SystemSettings::all(true)['siteName'] === $originalTitle, 'Validation failure must not partly save settings');
    }
    rejects(fn() => SystemSettings::save([], ['siteName']), 'Only secrets may be cleared');

    SystemSettings::apply($app, true);
    check(!$app->isDebug() && !$app->config->get('mailer.enable') && !$app->config->get('payment.epay.enable') && !$app->config->get('map.enable'), 'Unconfigured optional integrations must be disabled');
    check($app->config->get('queue.default') === 'sync' && $app->config->get('telegram.botConfig.bots.randallanjie_bot.token') === 'notgbot', 'New installation must work without Redis/Telegram');
    // 仅创建文件/同步驱动，随后验证切换配置会丢弃已创建驱动；不会连接 Redis。
    $cacheDriver = $app->cache->store('file');
    $queue = new think\Queue($app);
    $app->instance('queue', $queue);
    $queueDriver = $queue->connection('sync');
    SystemSettings::save([
        'siteName' => 'Personalized', 'siteLogo' => '/assets/logo.png', 'siteFavicon' => 'https://example.com/icon.png',
        'cacheType' => 'redis', 'redisHost' => 'cache.example.com', 'redisPort' => 6380, 'redisDb' => 7, 'redisPass' => 'redis secret',
        'mailHost' => 'smtp.example.com', 'mailUser' => 'sender', 'mailPass' => 'mail secret', 'mailFromEmail' => 'sender@example.com',
        'payUrl' => 'https://pay.example.com', 'payMerchantId' => 'merchant', 'payKey' => 'pay secret', 'payMethods' => '["alipay"]',
        'tencentMapKey' => 'map key', 'tencentMapSk' => 'map secret', 'defaultLang' => 'en-us', 'appDebug' => '1',
        'embyLineList' => '[{"name":"Only","url":"https://only.example.com"}]', 'embyUrlBase' => 'http://emby:8096/emby',
        'moviepilotEnabled' => '1', 'moviepilotUrl' => 'http://moviepilot:3000',
    ]);
    SystemSettings::apply($app, true);
    check($app->config->get('app.app_name') === 'Personalized' && $app->config->get('app.site_logo') === '/assets/logo.png', 'Personalization must map to live app config');
    check($app->isDebug() && $app->lang->defaultLangSet() === 'en-us', 'Application debug and language instances must update');
    check($app->config->get('cache.stores.redis.select') === 7 && $app->config->get('queue.connections.redis.select') === 7, 'Both Redis connectors must use configured database');
    check($app->config->get('queue.default') === 'redis' && $app->config->get('queue.connections.redis.password') === 'redis secret', 'Queue must use DB Redis config');
    check($app->cache->store('file') !== $cacheDriver && $queue->connection('sync') !== $queueDriver, 'Existing drivers must be discarded when configuration changes');
    check($app->config->get('mailer.enable') && $app->config->get('payment.epay.enable') && $app->config->get('map.enable'), 'Completed integration config must enable services');
    check($app->config->get('media.urlBase') === 'http://emby:8096/emby/' && $app->config->get('payment.epay.urlBase') === 'https://pay.example.com/', 'API base addresses must support existing concatenation');
    check(count($app->config->get('media.lineList')) === 1 && $app->config->get('media.lineList')[0]['name'] === 'Only', 'JSON lists must replace old lists');
    SystemSettings::save(['cacheType' => 'file', 'defaultLang' => 'zh-cn', 'appDebug' => false], ['mailPass', 'payKey', 'tencentMapSk']);
    SystemSettings::apply($app, true);
    check(!$app->isDebug() && $app->lang->defaultLangSet() === 'zh-cn' && $app->config->get('queue.default') === 'sync', 'Live config must revert when disabled');
    check(!$app->config->get('mailer.enable') && !$app->config->get('payment.epay.enable') && !$app->config->get('map.enable'), 'Clearing required secrets must disable integrations');
    // 兼容历史重复键，所有重复行同步为新值。
    Db::name('config')->insert(['key' => 'siteName', 'value' => 'Duplicate']);
    SystemSettings::save(['siteName' => 'Final Title']);
    check(array_unique(Db::name('config')->where('key', 'siteName')->column('value')) === ['Final Title'], 'Saving must synchronize historical duplicate rows');
    check(Db::name('config')->where('key', 'redisPass')->value('type') === 0, 'Operational credentials must be private');
    Db::name('config')->where('key', 'embyLineList')->update(['value' => 'invalid historical json']);
    check(SystemSettings::all(true)['embyLineList'] === [], 'Invalid historical data must allow admin to repair configuration');
    fwrite(STDOUT, "System settings integration checks passed.\n");
} finally {
    $_ENV = $originalEnvironment;
    foreach ($savedEnvironment as $name => $value) {
        putenv($name . '=' . $value);
    }
    $db->connect()->close();
    foreach (glob($testRoot . '/*') as $file) {
        unlink($file);
    }
    unlink($testRoot . '/.env');
    rmdir($testRoot);
}
