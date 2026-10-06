<?php
/** php tests/sqlite_migrations.php: full real CLI migrations in disposable SQLite databases. */
function expectSqliteMigration(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$projectRoot = dirname(__DIR__);
if (defined('SQLITE_MIGRATION_PROBE_ROOT')) {
    require $projectRoot . '/vendor/autoload.php';
    require_once $projectRoot . '/vendor/topthink/framework/src/helper.php';
    $app = new think\App(SQLITE_MIGRATION_PROBE_ROOT);
    $app->initialize();
    expectSqliteMigration($app->db instanceof EmbyDatabase\DbManager && think\facade\Db::connect() instanceof EmbyDatabase\Sqlite, 'Business probe must use the production SQLite connector');
    app\service\SystemSettings::apply($app, true);
    app\service\SystemSettings::save(['siteName' => 'SQLite migration smoke', 'cacheType' => 'file']);
    app\service\SystemSettings::apply($app, true);
    expectSqliteMigration($app->config->get('app.app_name') === 'SQLite migration smoke', 'Migrated settings must save and apply');
    $user = new app\media\model\UserModel();
    $user->save(['userName' => 'smoke_user', 'password' => password_hash('fixture-password', PASSWORD_DEFAULT), 'rCoin' => 10]);
    $stored = think\facade\Db::name('user')->where('id', $user->id)->find();
    expectSqliteMigration($user->id > 1 && (int) $stored['authority'] === 1, 'ORM user must receive generated ID and defaults');
    expectSqliteMigration(strtotime($stored['createdAt']) !== false && strtotime($stored['updatedAt']) !== false, 'ORM timestamp fields must store readable dates');
    think\facade\Db::transaction(function () use ($user) {
        think\facade\Db::name('user')->where('id', $user->id)->dec('rCoin', 2)->update();
        think\facade\Db::name('finance_record')->insert(['userId' => $user->id, 'action' => 3, 'count' => '2']);
    });
    expectSqliteMigration((float) think\facade\Db::name('user')->where('id', $user->id)->value('rCoin') === 8.0, 'Transactional balance update must persist');
    $seekId = think\facade\Db::name('media_seek')->insertGetId(['userId' => $user->id, 'title' => 'SQLite fixture']);
    think\facade\Db::name('media_seek_log')->insert(['seekId' => $seekId, 'type' => 1, 'content' => 'Fixture created']);
    think\facade\Db::name('media_seek_user')->insert(['seekId' => $seekId, 'userId' => $user->id]);
    think\facade\Db::name('notification')->insert(['toUserId' => $user->id, 'message' => 'SQLite fixture notification']);
    think\facade\Db::name('emby_device')->insert(['lastUsedIp' => '127.0.0.1', 'embyId' => 'fixture-emby', 'deviceId' => 'fixture-device', 'client' => 'fixture']);
    expectSqliteMigration(think\facade\Db::name('emby_device')->value('deactivate') === 0, 'Last migration must add device default');
    expectSqliteMigration(think\facade\Db::name('notification')->where('readStatus', 0)->count() === 1, 'Notification default must work');
    echo "SQLITE_BUSINESS_PROBE_OK\n";
    exit;
}

function sqliteMigrationCommand(string $root, array $environment, array $arguments, string $entry = 'think'): string
{
    $command = [PHP_BINARY];
    if (php_ini_loaded_file()) array_push($command, '-c', php_ini_loaded_file());
    array_push($command, '-d', 'variables_order=EGPCS', $root . '/' . $entry);
    $command = array_merge($command, $arguments);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment, ['bypass_shell' => true]);
    expectSqliteMigration(is_resource($process), 'Cannot start migration subprocess');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $status = proc_close($process);
    expectSqliteMigration($status === 0, implode(' ', $arguments) . ' failed: ' . $output . $error);
    return $output;
}
function removeSqliteMigrationFixture(string $path): void
{
    foreach (scandir($path) as $name) {
        if ($name === '.' || $name === '..') continue;
        $child = $path . '/' . $name;
        if (is_dir($child) && !is_link($child)) removeSqliteMigrationFixture($child);
        else unlink($child);
    }
    rmdir($path);
}

$testRoot = sys_get_temp_dir() . '/emby-sqlite-migrations-' . bin2hex(random_bytes(8));
mkdir($testRoot);
try {
    foreach (['rc_', 'fixture_'] as $prefix) {
        $root = $testRoot . '/' . $prefix;
        foreach (['app', 'config', 'database/migrations'] as $directory) mkdir($root . '/' . $directory, 0777, true);
        foreach (glob($projectRoot . '/config/*.php') as $file) {
            file_put_contents($root . '/config/' . basename($file), '<?php return require ' . var_export($file, true) . ';');
        }
        foreach (glob($projectRoot . '/database/migrations/*.php') as $file) copy($file, $root . '/database/migrations/' . basename($file));
        file_put_contents($root . '/app/service.php', '<?php return [\\think\\migration\\Service::class];');
        file_put_contents($root . '/app/event.php', '<?php return require ' . var_export($projectRoot . '/app/event.php', true) . ';');
        file_put_contents($root . '/app/provider.php', '<?php return require ' . var_export($projectRoot . '/app/provider.php', true) . ';');
        // Use the real framework CLI with an explicit fixture root, so production .env is never read.
        file_put_contents($root . '/think', '<?php require ' . var_export($projectRoot . '/vendor/autoload.php', true) . '; (new \\think\\App(__DIR__))->console->run();');
        file_put_contents($root . '/business.php', '<?php define("SQLITE_MIGRATION_PROBE_ROOT", __DIR__); require ' . var_export(__FILE__, true) . ';');
        $database = $root . '/data/fixture.sqlite';
        $environment = ['DB_DRIVER' => 'sqlite', 'DB_TYPE' => 'sqlite', 'DB_NAME' => $database, 'DB_PREFIX' => $prefix];
        sqliteMigrationCommand($root, $environment, ['migrate:run']);
        expectSqliteMigration(is_file($database) && !is_file($database . '.sqlite3'), 'Migrations and ORM must use exactly DB_NAME without suffix');
        $pdo = new PDO('sqlite:' . $database);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $migrationCount = count(glob($projectRoot . '/database/migrations/[0-9]*.php'));
        expectSqliteMigration((int) $pdo->query('SELECT COUNT(*) FROM "' . $prefix . 'migrations"')->fetchColumn() === $migrationCount, 'Every migration must be recorded');
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) expectSqliteMigration(strpos($table, $prefix) === 0, 'All migrated tables must honor configured prefix');
        foreach (['user', 'config', 'finance_record', 'notification', 'media_seek', 'media_seek_log', 'media_seek_user', 'emby_device', 'lottery', 'bet'] as $table) {
            expectSqliteMigration(in_array($prefix . $table, $tables, true), 'Missing required migrated table: ' . $table);
        }
        $admin = $pdo->query('SELECT * FROM "' . $prefix . 'user" WHERE userName = \'admin\'')->fetch(PDO::FETCH_ASSOC);
        expectSqliteMigration($admin && (int) $admin['authority'] === 0 && password_get_info($admin['password'])['algoName'] === 'bcrypt', 'Initial administrator must exist with valid password hash');
        expectSqliteMigration(strtotime($admin['createdAt']) !== false, 'CURRENT_TIMESTAMP must initialize admin dates');
        sqliteMigrationCommand($root, $environment, ['migrate:run']);
        expectSqliteMigration((int) $pdo->query('SELECT COUNT(*) FROM "' . $prefix . 'user"')->fetchColumn() === 1, 'Migration rerun must not duplicate administrator');
        sqliteMigrationCommand($root, $environment, ['settings:import-env']);
        expectSqliteMigration(str_contains(sqliteMigrationCommand($root, $environment, [], 'business.php'), 'SQLITE_BUSINESS_PROBE_OK'), 'Business probe did not complete');
        $licenseSql = 'INSERT INTO "' . $prefix . 'auth_licenses" (license_key) VALUES (\'fixture-license\')';
        $pdo->exec($licenseSql);
        try { $pdo->exec($licenseSql); throw new RuntimeException('License unique index must reject duplicates'); }
        catch (PDOException $e) { expectSqliteMigration($e->getCode() === '23000', 'Expected unique constraint failure'); }
        $pdo = null;
        sqliteMigrationCommand($root, $environment, ['migrate:rollback', '--target=0']);
        $pdo = new PDO('sqlite:' . $database);
        expectSqliteMigration((int) $pdo->query('SELECT COUNT(*) FROM "' . $prefix . 'migrations"')->fetchColumn() === 0, 'Full rollback must clear migration history');
        $pdo = null;
        sqliteMigrationCommand($root, $environment, ['migrate:run']);
        $pdo = new PDO('sqlite:' . $database);
        expectSqliteMigration((int) $pdo->query('SELECT COUNT(*) FROM "' . $prefix . 'user"')->fetchColumn() === 1, 'Reapplying after rollback must restore administrator');
        $pdo = null;
        echo 'PASS: ' . $prefix . " full SQLite CLI migration/re-run/rollback, administrator, settings, ORM and business inserts\n";
    }
} finally {
    removeSqliteMigrationFixture($testRoot);
}
