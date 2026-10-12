<?php
/** php tests/payments.php: real SDK, disposable credentials/SQLite, no external HTTP or notifications. */
require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';
require_once dirname(__DIR__) . '/app/common.php';
require_once dirname(__DIR__) . '/app/media/common.php';

use app\media\model\PayRecordModel;
use app\service\PaymentGateway;
use app\service\PaymentService;
use think\facade\Config;
use think\facade\Db;
use think\facade\Session;
use think\facade\View;
use Yansongda\Pay\Pay;

function payExpect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function payReject(callable $operation, string $message): void {
    try { $operation(); } catch (Throwable $e) {
        if ($e instanceof RuntimeException && $e->getMessage() === 'Unexpected network request') throw $e;
        return;
    }
    throw new RuntimeException($message);
}
class OfflinePaymentClient implements Yansongda\Artful\Contract\HttpClientInterface {
    public static $handler;
    public function sendRequest(Psr\Http\Message\RequestInterface $request): Psr\Http\Message\ResponseInterface {
        if (!self::$handler) throw new RuntimeException('Unexpected network request');
        return (self::$handler)($request);
    }
}
class OfflinePaymentGateway extends PaymentGateway {
    protected function boot(string $method): void {
        parent::boot($method);
        Pay::set(Yansongda\Artful\Contract\HttpClientInterface::class, new OfflinePaymentClient());
    }
}
function payApp(string $root, string $database, array $credentials): think\App {
    $app = new think\App($root);
    $app->bind(require dirname(__DIR__) . '/app/provider.php');
    $app->setRuntimePath($root . '/runtime/');
    foreach (['app', 'cache', 'log', 'session', 'view', 'payment', 'telegram', 'mailer'] as $name) {
        $config = require dirname(__DIR__) . '/config/' . $name . '.php';
        if ($name === 'cache') $config['stores']['file']['path'] = $root . '/runtime/cache/';
        if ($name === 'session') $config['path'] = $root . '/runtime/session/';
        if ($name === 'view') {
            $config['view_path'] = dirname(__DIR__) . '/app/media/view/';
            $config['layout_on'] = false;
            $config['tpl_cache'] = false;
            $config['cache_path'] = $root . '/runtime/temp/';
        }
        $app->config->set($config, $name);
    }
    $config = require dirname(__DIR__) . '/config/database.php';
    $config['default'] = 'sqlite';
    $config['connections']['sqlite']['database'] = $database;
    $config['connections']['sqlite']['prefix'] = 'rc_';
    $app->config->set($config, 'database');
    think\Model::setDb($app->make('think\DbManager'));
    (new think\service\ModelService($app))->boot();
    Config::set(['app_host' => 'https://payments.example.com', 'app_name' => 'Payment Fixture'], 'app');
    Config::set(['alipay' => ['enabled' => true, 'config' => $credentials['alipay']], 'wechat' => ['enabled' => true, 'config' => $credentials['wxpay']]], 'payment');
    Config::set(['enable' => false], 'mailer');
    Config::set(['botConfig' => ['bots' => ['randallanjie_bot' => ['token' => 'notgbot']]]], 'telegram');
    $app->instance(PaymentGateway::class, new OfflinePaymentGateway());
    return $app;
}
function paySign(string $body, string $private): string {
    payExpect(openssl_sign($body, $signature, $private, OPENSSL_ALGO_SHA256), 'Cannot sign fixture');
    return base64_encode($signature);
}
function payHeaders(string $body, string $private): array {
    $time = (string) time(); $nonce = bin2hex(random_bytes(8));
    return ['Wechatpay-Serial' => 'PUB_KEY_ID_FIXTURE', 'Wechatpay-Timestamp' => $time, 'Wechatpay-Nonce' => $nonce,
        'Wechatpay-Signature' => paySign($time . "\n" . $nonce . "\n" . $body . "\n", $private)];
}
function payResponse(string $method, array $data, string $private, string $action = 'alipay.trade.query', bool $invalid = false): GuzzleHttp\Psr7\Response {
    if ($method === 'alipay') {
        $data = ['code' => '10000'] + $data;
        $signature = paySign(json_encode($data, JSON_UNESCAPED_UNICODE), $private);
        $body = json_encode([str_replace('.', '_', $action) . '_response' => $data, 'sign' => $invalid ? 'invalid' : $signature], JSON_UNESCAPED_UNICODE);
        return new GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], $body);
    }
    $body = json_encode($data, JSON_UNESCAPED_UNICODE);
    $headers = payHeaders($body, $private);
    if ($invalid) $headers['Wechatpay-Signature'] = 'invalid';
    return new GuzzleHttp\Psr7\Response(200, $headers + ['Content-Type' => 'application/json'], $body);
}
function payData(string $method, array $record, array $overrides = []): array {
    return array_replace_recursive($method === 'alipay' ? [
        'app_id' => 'fixture-app', 'seller_id' => 'fixture-seller', 'out_trade_no' => $record['tradeNo'],
        'trade_no' => 'ALI-' . $record['id'], 'trade_status' => 'TRADE_SUCCESS', 'total_amount' => $record['money'],
    ] : [
        'appid' => 'fixture-wx-app', 'mchid' => 'fixture-mch', 'out_trade_no' => $record['tradeNo'],
        'transaction_id' => 'WX-' . $record['id'], 'trade_state' => 'SUCCESS',
        'amount' => ['total' => PaymentService::cents($record['money']), 'currency' => 'CNY'],
    ], $overrides);
}
function payNotification(string $method, array $data, string $private, string $event = 'TRANSACTION.SUCCESS'): array {
    if ($method === 'alipay') {
        ksort($data);
        $pairs = [];
        foreach ($data as $key => $value) if ($value !== '') $pairs[] = $key . '=' . $value;
        $data['sign'] = paySign(implode('&', $pairs), $private);
        $data['sign_type'] = 'RSA2';
        return ['body' => http_build_query($data), 'headers' => ['Content-Type' => 'application/x-www-form-urlencoded']];
    }
    $nonce = 'fixtureNonce'; $associated = 'transaction';
    $encrypted = openssl_encrypt(json_encode($data), 'aes-256-gcm', str_repeat('k', 32), OPENSSL_RAW_DATA, $nonce, $tag, $associated);
    $body = json_encode(['id' => bin2hex(random_bytes(8)), 'event_type' => $event,
        'resource' => ['algorithm' => 'AEAD_AES_256_GCM', 'nonce' => $nonce, 'associated_data' => $associated, 'ciphertext' => base64_encode($encrypted . $tag)]]);
    return ['body' => $body, 'headers' => payHeaders($body, $private) + ['Content-Type' => 'application/json']];
}
function payRequest(array $notification): think\Request {
    return (new app\Request())->setMethod('POST')->withHeader($notification['headers'])->withInput($notification['body']);
}
function payProcess(array $arguments, string $root, ?array $environment = null): array {
    $command = [PHP_BINARY];
    if (php_ini_loaded_file()) array_push($command, '-c', php_ini_loaded_file());
    $command = array_merge($command, ['-d', 'variables_order=EGPCS', '-d', 'opcache.enable_cli=0'], $arguments);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment, ['bypass_shell' => true]);
    payExpect(is_resource($process), 'Cannot start fixture');
    fclose($pipes[0]);
    return [$process, $pipes];
}
function payResult(array $worker): string {
    [$process, $pipes] = $worker;
    $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    payExpect(proc_close($process) === 0, 'Fixture failed: ' . $output . $errors);
    return $output;
}
function payRemove(string $root): void {
    foreach (scandir($root) as $name) {
        if ($name === '.' || $name === '..') continue;
        $child = $root . '/' . $name;
        if (is_dir($child) && !is_link($child)) payRemove($child); else unlink($child);
    }
    rmdir($root);
}
if (($argv[1] ?? '') === '--worker') {
    [, , $root, $worker] = $argv;
    $fixture = json_decode(file_get_contents($root . '/parallel.json'), true);
    mkdir($root . '/worker-' . $worker);
    payApp($root . '/worker-' . $worker, $fixture['database'], $fixture['credentials']);
    file_put_contents($root . '/ready-' . $worker, 'ready');
    $deadline = microtime(true) + 20;
    while (!is_file($root . '/go')) {
        payExpect(microtime(true) < $deadline, 'Worker barrier timeout'); usleep(10000);
    }
    (new PaymentService(new OfflinePaymentGateway()))->callback($fixture['method'], payRequest($fixture['notification']));
    Db::connect()->close();
    echo "SETTLED\n";
    exit;
}

$project = dirname(__DIR__);
$root = sys_get_temp_dir() . '/emby-payments-' . bin2hex(random_bytes(8));
mkdir($root);
try {
    foreach (['app', 'config', 'database/migrations', 'runtime/temp'] as $directory) mkdir($root . '/' . $directory, 0777, true);
    foreach (glob($project . '/config/*.php') as $file) file_put_contents($root . '/config/' . basename($file), '<?php return require ' . var_export($file, true) . ';');
    foreach (glob($project . '/database/migrations/*.php') as $file) copy($file, $root . '/database/migrations/' . basename($file));
    file_put_contents($root . '/app/service.php', '<?php return [\\think\\migration\\Service::class];');
    file_put_contents($root . '/app/provider.php', '<?php return require ' . var_export($project . '/app/provider.php', true) . ';');
    file_put_contents($root . '/think', '<?php require ' . var_export($project . '/vendor/autoload.php', true) . '; (new \\think\\App(__DIR__))->console->run();');
    $database = $root . '/data/payments.sqlite';
    $environment = array_filter(getenv() ?: [], fn($key) => !preg_match('/^(DB_|APP_|EMBY_|TG_|MAIL_|REDIS_)/', $key), ARRAY_FILTER_USE_KEY);
    $environment = array_merge($environment, ['DB_DRIVER' => 'sqlite', 'DB_TYPE' => 'sqlite', 'DB_NAME' => $database, 'DB_PREFIX' => 'rc_']);
    payResult(payProcess([$root . '/think', 'migrate:run', '--target=20261007010000'], $root, $environment));
    $pdo = new PDO('sqlite:' . $database);
    $pdo->exec("INSERT INTO rc_pay_record (id,userId,payCompleteKey,type,tradeNo,money,payRecordInfo) VALUES (1,1,'old-paid',2,'old-1','1.00','{}'), (2,1,'old-unpaid',1,'old-2','1.00','{}')");
    payResult(payProcess([$root . '/think', 'migrate:run'], $root, $environment));
    payResult(payProcess([$root . '/think', 'migrate:run'], $root, $environment));
    payExpect($pdo->query('SELECT COUNT(*) FROM rc_pay_record WHERE paymentGateway = \'\' AND providerTransactionId IS NULL')->fetchColumn() === 2, 'Migration changed historical orders');
    $pdo = null;

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    payExpect($key !== false, 'Cannot generate RSA fixture');
    openssl_pkey_export($key, $private);
    $csr = openssl_csr_new(['commonName' => 'Payment fixture'], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256'], 12345);
    openssl_x509_export($cert, $public);
    file_put_contents($root . '/merchant.pem', $public);
    file_put_contents($root . '/private.pem', $private);
    $credentials = [
        'alipay' => ['app_id' => 'fixture-app', 'seller_id' => 'fixture-seller', 'app_secret_cert' => $root . '/private.pem',
            'app_public_cert_path' => $root . '/merchant.pem', 'alipay_public_cert_path' => $public, 'alipay_root_cert_path' => $root . '/merchant.pem'],
        'wxpay' => ['mch_id' => 'fixture-mch', 'mp_app_id' => 'fixture-wx-app', 'mch_secret_key' => str_repeat('k', 32),
            'mch_secret_cert' => $private, 'mch_public_cert_path' => $root . '/merchant.pem', 'wechat_public_cert_path' => ['PUB_KEY_ID_FIXTURE' => $public]],
    ];
    $app = payApp($root, $database, $credentials);
    Db::name('user')->insert(['id' => 2, 'userName' => 'other', 'password' => 'unused']);
    Db::name('config')->insert(['key' => 'chargeRate', 'value' => '1.25']);
    $gateway = new OfflinePaymentGateway(); $service = new PaymentService($gateway);
    payExpect(PaymentGateway::methods() === ['alipay' => '支付宝', 'wxpay' => '微信支付'], 'Both configured channels must be available');
    foreach ([null, [], 0.01, '0', '-1', '1e2', '1.001', '1000000.01'] as $money) payReject(fn() => $service->create(1, $money, 'alipay', '127.0.0.1'), 'Invalid amount accepted');
    payReject(fn() => $service->create(1, '1', 'usdt', '127.0.0.1'), 'Retired channel accepted');
    payExpect(Db::name('pay_record')->count() === 2, 'Invalid inputs created orders');
    payExpect($service->check(1, 1)['paid'], 'Historical paid record must remain readable');
    payReject(fn() => $service->check(1, 2, true), 'Legacy unpaid order reused new gateway');
    echo "PASS: migration preserves history; disabled/retired methods and invalid amounts cannot create orders\n";

    foreach (['alipay', 'wxpay'] as $method) {
        OfflinePaymentClient::$handler = function ($request) use ($method, $private) {
            if ($method === 'alipay') {
                parse_str((string) $request->getBody(), $form);
                $biz = json_decode($form['biz_content'], true);
                payExpect($form['method'] === 'alipay.trade.precreate' && $form['notify_url'] === 'https://payments.example.com/server/paymentNotify?method=alipay', 'Alipay request/callback URL wrong');
                payExpect($biz['total_amount'] === '1.01' && $biz['timeout_express'] === '30m', 'Alipay amount/expiry wrong');
                payExpect(Db::name('pay_record')->where('tradeNo', $biz['out_trade_no'])->count() === 1, 'Order must be saved before contacting provider');
                return payResponse($method, ['qr_code' => 'https://qr.alipay.com/fixture'], $private, 'alipay.trade.precreate');
            }
            $body = json_decode((string) $request->getBody(), true);
            payExpect(str_ends_with($request->getUri()->getPath(), '/v3/pay/transactions/native') && $request->hasHeader('Authorization'), 'WeChat Native signing/request wrong');
            payExpect($body['amount']['total'] === 101 && $body['amount']['currency'] === 'CNY' && $body['appid'] === 'fixture-wx-app', 'WeChat amount/AppID wrong');
            payExpect($body['notify_url'] === 'https://payments.example.com/server/paymentNotify?method=wxpay', 'WeChat callback URL wrong');
            return payResponse($method, ['code_url' => 'weixin://wxpay/bizpayurl?pr=fixture&x=1'], $private);
        };
        $order = $service->create(1, '1.01', $method, '127.0.0.1');
        $record = Db::name('pay_record')->find($order['id']);
        $info = json_decode($record['payRecordInfo'], true);
        payExpect($info['credit'] === '1.27', 'Recharge ratio must round up to cents');
        $data = payData($method, $record);
        $notification = payNotification($method, $data, $private);
        $balance = (float) Db::name('user')->where('id', 1)->value('rCoin');
        $count = Db::name('finance_record')->count();
        $bad = $notification;
        if ($method === 'alipay') $bad['body'] .= '&total_amount=9.00';
        else $bad['headers']['Wechatpay-Signature'] = 'invalid';
        payReject(fn() => $service->callback($method, payRequest($bad)), 'Bad signature credited');
        $mismatches = $method === 'alipay'
            ? [['total_amount' => '9.00'], ['seller_id' => 'other'], ['app_id' => 'other'], ['out_trade_no' => 'unknown']]
            : [['amount' => ['total' => 999]], ['amount' => ['currency' => 'USD']], ['amount' => ['currency' => null]], ['mchid' => 'other'], ['appid' => 'other'], ['out_trade_no' => 'unknown']];
        foreach ($mismatches as $mismatch) {
            payReject(fn() => $service->callback($method, payRequest(payNotification($method, payData($method, $record, $mismatch), $private))), 'Signed mismatch credited');
        }
        if ($method === 'wxpay') payReject(fn() => $service->callback($method, payRequest(payNotification($method, $data, $private, 'REFUND.SUCCESS'))), 'Non-payment event credited');
        else {
            $bad = $notification; $bad['body'] .= '&_config=other';
            payReject(fn() => $service->callback($method, payRequest($bad)), 'SDK controls allowed from callback');
        }
        payExpect((float) Db::name('user')->where('id', 1)->value('rCoin') === $balance && Db::name('finance_record')->count() === $count, 'Rejected notification changed balance/ledger');
        payReject(fn() => $service->check(2, $order['id']), 'Another account can query an order');
        Db::execute("CREATE TEMP TRIGGER fail_payment_ledger BEFORE INSERT ON rc_finance_record BEGIN SELECT RAISE(ABORT, 'fixture rollback'); END");
        payReject(fn() => $service->callback($method, payRequest($notification)), 'Ledger failure did not propagate');
        payExpect((int) Db::name('pay_record')->where('id', $order['id'])->value('type') === 1
            && (float) Db::name('user')->where('id', 1)->value('rCoin') === $balance, 'Settlement partially committed');
        Db::execute('DROP TRIGGER fail_payment_ledger');
        Db::name('config')->where('key', 'chargeRate')->update(['value' => '2']);
        $service->callback($method, payRequest($notification));
        $service->callback($method, payRequest($notification));
        payExpect(abs((float) Db::name('user')->where('id', 1)->value('rCoin') - $balance - 1.27) < 0.00001
            && Db::name('finance_record')->count() === $count + 1, 'Duplicate callback or ratio change altered settlement');
        $ledger = Db::name('finance_record')->order('id', 'desc')->find();
        payExpect($ledger['count'] === '1.27' && json_decode($ledger['recordInfo'], true)['paidMoney'] === '1.01', 'Ledger must record actual credited amount');
        $transactionKey = $method === 'alipay' ? 'trade_no' : 'transaction_id';
        payReject(fn() => $service->callback($method, payRequest(payNotification($method, array_replace($data, [$transactionKey => 'different']), $private))), 'Conflicting duplicate transaction accepted');
        payExpect($gateway->success($method)->getCode() === 200, 'Provider success response invalid');
        Db::name('config')->where('key', 'chargeRate')->update(['value' => '1.25']);
        echo "PASS: $method real SDK signed QR request, verified callback" . ($method === 'wxpay' ? '/AES-GCM decryption' : '') . ", mismatch rejection, transaction rollback and one-time credit\n";
    }

    // Reuse a known QR response to create additional orders; responses remain signed.
    OfflinePaymentClient::$handler = fn() => payResponse('alipay', ['qr_code' => 'https://qr.alipay.com/fixture'], $private, 'alipay.trade.precreate');
    $newOrder = fn() => $service->create(1, '1.01', 'alipay', '127.0.0.1');
    $order = $newOrder(); $record = Db::name('pay_record')->find($order['id']);
    $data = payData('alipay', $record);
    OfflinePaymentClient::$handler = fn() => payResponse('alipay', ['out_trade_no' => $record['tradeNo'], 'trade_status' => 'WAIT_BUYER_PAY'], $private);
    payExpect(!$service->check(1, $order['id'])['paid'] && $service->check(1, $order['id'], true)['payUrl'] === 'https://qr.alipay.com/fixture', 'Unpaid query/QR re-display wrong');
    $model = (new PayRecordModel())->find($order['id']); $info = $model->payRecordInfo; $info['expiresAt'] = time() - 1; $model->payRecordInfo = $info; $model->save();
    payReject(fn() => $service->check(1, $order['id'], true), 'Expired QR returned');
    OfflinePaymentClient::$handler = fn() => payResponse('alipay', $data, $private, 'alipay.trade.query', true);
    payReject(fn() => $service->check(1, $order['id']), 'Unsigned query credited');
    OfflinePaymentClient::$handler = fn() => payResponse('alipay', array_diff_key($data, ['app_id' => 1, 'seller_id' => 1]), $private);
    payExpect($service->check(1, $order['id'])['paid'], 'Authenticated Alipay query missing callback-only merchant fields failed');
    echo "PASS: signed query, invalid query signature, unpaid response, QR reuse/expiry and expired but paid reconciliation\n";

    OfflinePaymentClient::$handler = fn() => payResponse('wxpay', ['code_url' => 'weixin://wxpay/fixture'], $private);
    $wxOrder = $service->create(1, '1.01', 'wxpay', '127.0.0.1'); $wxRecord = Db::name('pay_record')->find($wxOrder['id']);
    OfflinePaymentClient::$handler = function ($request) use ($wxRecord, $private) {
        payExpect($request->getMethod() === 'GET' && str_contains((string) $request->getUri(), '/out-trade-no/' . $wxRecord['tradeNo'] . '?mchid=fixture-mch'), 'Native query path wrong');
        return payResponse('wxpay', payData('wxpay', $wxRecord), $private);
    };
    payExpect($service->check(1, $wxOrder['id'])['paid'], 'Verified WeChat query did not settle');

    OfflinePaymentClient::$handler = fn() => payResponse('alipay', ['qr_code' => 'https://qr.alipay.com/fixture'], $private, 'alipay.trade.precreate');
    $order = $newOrder(); $record = Db::name('pay_record')->find($order['id']);
    OfflinePaymentClient::$handler = function () use ($service, $record, $private) {
        $service->callback('alipay', payRequest(payNotification('alipay', payData('alipay', $record), $private)));
        return payResponse('alipay', ['out_trade_no' => $record['tradeNo'], 'trade_status' => 'WAIT_BUYER_PAY'], $private);
    };
    payExpect($service->check(1, $order['id'])['paid'], 'Callback/query race lost paid state');
    echo "PASS: verified WeChat Native query; callback during unpaid query preserves paid state\n";

    // Two real SDK callbacks start together in separate processes against the production SQLite connector.
    OfflinePaymentClient::$handler = fn() => payResponse('alipay', ['qr_code' => 'https://qr.alipay.com/fixture'], $private, 'alipay.trade.precreate');
    $order = $newOrder(); $record = Db::name('pay_record')->find($order['id']);
    $balance = (float) Db::name('user')->where('id', 1)->value('rCoin'); $count = Db::name('finance_record')->count();
    $parallel = $root . '/parallel'; mkdir($parallel);
    file_put_contents($parallel . '/parallel.json', json_encode(['database' => $database, 'credentials' => $credentials, 'method' => 'alipay', 'notification' => payNotification('alipay', payData('alipay', $record), $private)]));
    $workers = [];
    for ($i = 0; $i < 2; $i++) $workers[] = payProcess([__FILE__, '--worker', $parallel, (string) $i], $root);
    $deadline = microtime(true) + 20;
    while (count(glob($parallel . '/ready-*')) !== 2) {
        payExpect(microtime(true) < $deadline, 'Parent barrier timeout'); usleep(10000);
    }
    file_put_contents($parallel . '/go', 'go');
    foreach ($workers as $worker) payExpect(str_contains(payResult($worker), 'SETTLED'), 'Parallel callback failed');
    payExpect(abs((float) Db::name('user')->where('id', 1)->value('rCoin') - $balance - 1.27) < 0.00001 && Db::name('finance_record')->count() === $count + 1, 'Concurrent callbacks double credited');
    $another = $newOrder(); $anotherRecord = Db::name('pay_record')->find($another['id']);
    payReject(fn() => $service->callback('alipay', payRequest(payNotification('alipay', payData('alipay', $anotherRecord, ['trade_no' => 'ALI-' . $record['id']]), $private))), 'One provider transaction credited two orders');
    payExpect((int) Db::name('pay_record')->where('id', $another['id'])->value('type') === 1, 'Unique transaction failure partially committed');
    echo "PASS: parallel verified callbacks credit once; provider transaction cannot settle multiple orders\n";

    OfflinePaymentClient::$handler = function ($request) use ($service, $private) {
        parse_str((string) $request->getBody(), $form);
        $biz = json_decode($form['biz_content'], true);
        $record = Db::name('pay_record')->where('tradeNo', $biz['out_trade_no'])->find();
        $service->callback('alipay', payRequest(payNotification('alipay', payData('alipay', $record), $private)));
        return payResponse('alipay', ['qr_code' => 'https://qr.alipay.com/fixture'], $private, 'alipay.trade.precreate');
    };
    $early = $newOrder();
    payExpect((int) Db::name('pay_record')->where('id', $early['id'])->value('type') === 2
        && $service->check(1, $early['id'])['paid'], 'Callback during create overwritten by QR persistence');
    OfflinePaymentClient::$handler = fn() => throw new RuntimeException('SDK secret must not leak');
    $error = PaymentService::result($newOrder);
    payExpect($error['code'] === 400 && !str_contains(json_encode($error), 'SDK secret'), 'SDK error exposed to user');
    $timedOut = Db::name('pay_record')->order('id', 'desc')->find();
    payExpect((int) $timedOut['type'] === 1, 'Ambiguous create failure destroyed callback order');
    OfflinePaymentClient::$handler = fn() => payResponse('alipay', payData('alipay', $timedOut), $private);
    payExpect($service->check(1, $timedOut['id'])['paid'], 'Timed-out create cannot reconcile confirmed payment');
    Config::set(['enable' => true], 'mailer');
    $app->instance('queue', new class {
        public function push(...$arguments) { throw new RuntimeException('offline notification failure'); }
    });
    $service->callback('alipay', payRequest(payNotification('alipay', payData('alipay', $anotherRecord), $private)));
    payExpect($service->check(1, $another['id'])['paid'], 'Notification failure invalidated settlement');
    Config::set(['enable' => false], 'mailer');
    echo "PASS: callback during create, reconciliation after timeout, safe errors and notification failure do not lose payments\n";

    // Real controller entry points and restored detail template.
    Session::set('r_user', new app\media\model\UserModel(['id' => 1]));
    $request = payRequest(payNotification('alipay', payData('alipay', $record), $private))->withGet(['method' => 'alipay']);
    $app->instance('request', $request); $app->instance('think\Request', $request);
    $controller = new app\media\controller\Server($app);
    payExpect($controller->paymentNotify()->getContent() === 'success', 'Controller failed provider acknowledgement');
    payExpect($controller->resolvePayment()->getCode() === 410, 'Legacy callback remains active');
    OfflinePaymentClient::$handler = fn() => payResponse('alipay', ['qr_code' => 'https://qr.alipay.com/fixture'], $private, 'alipay.trade.precreate');
    $request = (new app\Request())->setMethod('POST')->withHeader(['Content-Type' => 'application/x-www-form-urlencoded'])->withInput('money=1.01&method=alipay');
    $app->instance('request', $request); $app->instance('think\Request', $request);
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $created = json_decode($controller->pay()->getContent(), true);
    payExpect($created['code'] === 200 && isset($created['id']), 'Real pay controller cannot create a configured order');
    $request = (new app\Request())->setMethod('POST')->withHeader(['Content-Type' => 'application/x-www-form-urlencoded'])->withInput('id=' . $created['id']);
    $app->instance('request', $request); $app->instance('think\Request', $request);
    $createdRecord = Db::name('pay_record')->find($created['id']);
    OfflinePaymentClient::$handler = fn() => payResponse('alipay', payData('alipay', $createdRecord), $private);
    $finance = new app\media\controller\Finance($app);
    payExpect(json_decode($finance->checkPay()->getContent(), true)['paid'], 'Real check controller cannot reconcile an order');
    payExpect(json_decode($finance->rePay()->getContent(), true)['paid'], 'Real rePay controller lost paid state');
    OfflinePaymentClient::$handler = fn() => payResponse('alipay', ['qr_code' => 'https://qr.alipay.com/fixture'], $private, 'alipay.trade.precreate');
    $unpaid = $newOrder();
    View::assign('payRecord', (new PayRecordModel())->find($unpaid['id']));
    $html = View::fetch('finance/pay_record_detail');
    foreach (['基本信息', '商品信息', '支付信息', '显示支付二维码', '/static/media/payment.js'] as $text) payExpect(str_contains($html, $text), 'Missing detail content: ' . $text);
    View::assign('payRecord', (new PayRecordModel())->find(2));
    payExpect(str_contains(View::fetch('finance/pay_record_detail'), '旧支付订单仅保留记录'), 'Legacy detail message missing');
    echo "PASS: real create/query/rePay/callback controllers; complete new and legacy bill detail templates\n";
} finally {
    OfflinePaymentClient::$handler = null;
    Pay::clear();
    if (isset($app)) Db::connect()->close();
    payRemove($root);
}
