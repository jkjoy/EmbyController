<?php
/** php tests/database_environment.php: real subprocesses, no .env or database connection. */
function databaseEnvironmentFixture(string $scenario, string $root): array
{
    if ($scenario === 'default') return [];
    if ($scenario === 'sqlite' || $scenario === 'sqlite-relative') return [
        'DB_DRIVER' => 'sqlite', 'DB_TYPE' => 'sqlite',
        'DB_NAME' => $scenario === 'sqlite' ? $root . '/compose-data/settings.sqlite' : 'relative-data/settings.sqlite',
        'DB_PREFIX' => 'fixture_',
    ];
    $fixture = [
        'DB_DRIVER' => 'mysql', 'DB_TYPE' => 'mysql', 'DB_HOST' => 'compose-db.invalid',
        'DB_NAME' => 'environment_fixture', 'DB_USER' => 'fixture_user',
        'DB_PASS' => 'fixture "quoted" password $with=spaces', 'DB_PORT' => '3307',
        'DB_CHARSET' => 'utf8mb4', 'DB_PREFIX' => 'fixture_',
    ];
    if ($scenario === 'mysql-type-only') unset($fixture['DB_DRIVER']);
    return $fixture;
}
function expectDatabaseEnvironment(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$projectRoot = dirname(__DIR__);
if (defined('DATABASE_ENV_CHILD')) {
    try {
        require $projectRoot . '/vendor/autoload.php';
        expectDatabaseEnvironment(!is_file(DATABASE_ENV_ROOT . '/.env'), 'Fixture must have no .env');
        expectDatabaseEnvironment(ini_get('variables_order') === DATABASE_ENV_ORDER, 'INI variables_order mismatch');
        expectDatabaseEnvironment(Dotenv\Dotenv::createImmutable(DATABASE_ENV_ROOT)->safeLoad() === [], 'No dotenv fallback allowed');
        $app = new think\App(DATABASE_ENV_ROOT);
        $app->initialize();
        $database = require $projectRoot . '/config/database.php';
        $migration = require $projectRoot . '/config/migration.php';
        $fixture = databaseEnvironmentFixture(DATABASE_ENV_SCENARIO, DATABASE_ENV_ROOT);
        foreach ($fixture as $key => $value) {
            expectDatabaseEnvironment(getenv($key) === $value, 'Process environment mismatch: ' . $key);
            if (DATABASE_ENV_ORDER === 'EGPCS') {
                expectDatabaseEnvironment(($_ENV[$key] ?? null) === $value, 'PHP environment mismatch: ' . $key);
                expectDatabaseEnvironment($app->env->get($key) === $value, 'Native Think environment mismatch: ' . $key);
            }
        }
        $mysql = DATABASE_ENV_SCENARIO === 'mysql' || DATABASE_ENV_SCENARIO === 'mysql-type-only';
        $connection = $database['connections'][$mysql ? 'mysql' : 'sqlite'];
        if ($mysql && DATABASE_ENV_ORDER === 'EGPCS') {
            expectDatabaseEnvironment($database['default'] === 'mysql' && $connection['type'] === 'mysql', 'Database driver mismatch');
            expectDatabaseEnvironment($migration['default'] === 'mysql' && $migration['environments']['default_database'] === 'mysql', 'Migration driver mismatch');
            $paths = ['hostname' => 'DB_HOST', 'database' => 'DB_NAME', 'username' => 'DB_USER', 'password' => 'DB_PASS', 'hostport' => 'DB_PORT', 'charset' => 'DB_CHARSET', 'prefix' => 'DB_PREFIX'];
            foreach ($paths as $path => $key) {
                expectDatabaseEnvironment($connection[$path] === $fixture[$key], 'Database config mismatch: ' . $path);
            }
            foreach (['host' => 'DB_HOST', 'name' => 'DB_NAME', 'user' => 'DB_USER', 'pass' => 'DB_PASS', 'port' => 'DB_PORT', 'charset' => 'DB_CHARSET', 'table_prefix' => 'DB_PREFIX'] as $path => $key) {
                expectDatabaseEnvironment($migration['environments']['mysql'][$path] === $fixture[$key], 'Migration config mismatch: ' . $path);
            }
            expectDatabaseEnvironment($migration['environments']['default_migration_table'] === 'fixture_migrations', 'Migration table prefix mismatch');
        } elseif (!$mysql) {
            $name = DATABASE_ENV_SCENARIO === 'default' ? DATABASE_ENV_ROOT . '/data/emby-controller.sqlite' : (DATABASE_ENV_SCENARIO === 'sqlite-relative' ? DATABASE_ENV_ROOT . '/relative-data/settings.sqlite' : $fixture['DB_NAME']);
            expectDatabaseEnvironment($database['default'] === 'sqlite' && $connection['type'] === 'sqlite', 'SQLite default driver mismatch');
            expectDatabaseEnvironment(str_replace('\\', '/', $connection['database']) === str_replace('\\', '/', $name) && $migration['environments']['sqlite']['name'] === $connection['database'], 'SQLite database path mismatch');
            expectDatabaseEnvironment($migration['default'] === 'sqlite' && $migration['environments']['default_database'] === 'sqlite', 'SQLite migration default mismatch');
            expectDatabaseEnvironment($connection['prefix'] === ($fixture['DB_PREFIX'] ?? 'rc_') && $migration['environments']['sqlite']['table_prefix'] === $connection['prefix'], 'SQLite prefix mismatch');
            expectDatabaseEnvironment($connection['suffix'] === '' && $migration['environments']['sqlite']['suffix'] === '', 'SQLite file suffix mismatch');
            expectDatabaseEnvironment(!is_dir(dirname($name)) && !is_file($name), 'Config loading must not initialize SQLite storage');
        } else {
            // Composer currently loads Illuminate's env() first; verify native Think Env
            // independently so the deployment never depends on that helper's fallback.
            expectDatabaseEnvironment(!isset($_ENV['DB_HOST']) && $app->env->get('DB_HOST', 'native-default') === 'native-default', 'GPCS control must reproduce native Think Env ignoring process variables');
        }
        $connections = new ReflectionProperty(think\DbManager::class, 'instance');
        expectDatabaseEnvironment($connections->getValue($app->db) === [], 'Test must never create database connections');
        echo 'DATABASE_ENV_PROBE_OK';
    } catch (Throwable $e) {
        // Assertions name fields only; never print environment or configuration values.
        file_put_contents('php://stderr', $e->getMessage() . "\n");
        exit(1);
    }
    exit;
}

function runDatabaseEnvironmentProbe(string $binary, string $root, string $order, bool $cgi, string $scenario = 'mysql'): void
{
    $probe = $root . '/probe-' . $scenario . '-' . strtolower($order) . '.php';
    file_put_contents($probe, '<?php define("DATABASE_ENV_CHILD", true); define("DATABASE_ENV_ROOT", ' . var_export($root, true) . '); define("DATABASE_ENV_ORDER", ' . var_export($order, true) . '); define("DATABASE_ENV_SCENARIO", ' . var_export($scenario, true) . '); require ' . var_export(__FILE__, true) . ';');
    $command = [$binary];
    if (php_ini_loaded_file()) array_push($command, '-c', php_ini_loaded_file());
    array_push($command, '-d', 'variables_order=' . $order);
    $environment = databaseEnvironmentFixture($scenario, $root);
    if ($cgi) {
        array_push($command, '-d', 'cgi.force_redirect=0');
        $environment += ['REQUEST_METHOD' => 'GET', 'SCRIPT_FILENAME' => $probe, 'SCRIPT_NAME' => '/database-env-probe', 'GATEWAY_INTERFACE' => 'CGI/1.1'];
    } else {
        $command[] = $probe;
    }
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment, ['bypass_shell' => true]);
    expectDatabaseEnvironment(is_resource($process), 'Cannot start PHP subprocess');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    if (isset($environment['DB_PASS'])) expectDatabaseEnvironment(!str_contains($output . $error, $environment['DB_PASS']), 'Subprocess exposed credentials');
    expectDatabaseEnvironment($status === 0 && str_contains($output, 'DATABASE_ENV_PROBE_OK'), ($cgi ? 'CGI' : 'CLI') . ' ' . $scenario . ' ' . $order . ' subprocess failed (exit ' . $status . '): ' . trim($error));
}
function removeDatabaseEnvironmentFixture(string $path): void
{
    foreach (scandir($path) as $name) {
        if ($name === '.' || $name === '..') continue;
        $child = $path . '/' . $name;
        if (is_dir($child) && !is_link($child)) removeDatabaseEnvironmentFixture($child);
        else unlink($child);
    }
    rmdir($path);
}

$testRoot = sys_get_temp_dir() . '/emby-database-environment-' . bin2hex(random_bytes(8));
mkdir($testRoot);
mkdir($testRoot . '/config');
foreach (['app', 'cache', 'database', 'lang', 'migration'] as $module) {
    file_put_contents($testRoot . '/config/' . $module . '.php', '<?php return require ' . var_export($projectRoot . '/config/' . $module . '.php', true) . ';');
}
try {
    foreach (['default', 'sqlite', 'sqlite-relative', 'mysql-type-only'] as $scenario) runDatabaseEnvironmentProbe(PHP_BINARY, $testRoot, 'EGPCS', false, $scenario);
    echo "PASS: default SQLite, absolute/relative SQLite paths and DB_TYPE-only MySQL configs load without creating storage\n";
    runDatabaseEnvironmentProbe(PHP_BINARY, $testRoot, 'EGPCS', false);
    runDatabaseEnvironmentProbe(PHP_BINARY, $testRoot, 'GPCS', false);
    echo "PASS: CLI and native Think Env use nine DB variables without .env; GPCS control requires E\n";
    $cgiBinary = dirname(PHP_BINARY) . '/php-cgi' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    if (is_file($cgiBinary)) {
        runDatabaseEnvironmentProbe($cgiBinary, $testRoot, 'EGPCS', true);
        echo "PASS: CGI HTTP request inherits DB variables through EGPCS without .env\n";
    } else {
        echo "SKIP: CGI binary unavailable; CLI process environment verified\n";
    }
    $pool = parse_ini_file($projectRoot . '/docker/www.conf', true, INI_SCANNER_RAW);
    expectDatabaseEnvironment(($pool['www']['clear_env'] ?? '') === 'no', 'FPM www pool must retain process environment');
    expectDatabaseEnvironment(str_contains(file_get_contents($projectRoot . '/Dockerfile'), 'variables_order = "EGPCS"'), 'Docker PHP ini must include E');
    echo "PASS: Docker PHP ini and www pool preserve Compose database environment\n";
} finally {
    removeDatabaseEnvironmentFixture($testRoot);
}
