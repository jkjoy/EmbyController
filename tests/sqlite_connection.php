<?php
/** php tests/sqlite_connection.php: real SQLite storage and transaction checks in a temporary root. */
$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';
require_once $projectRoot . '/vendor/topthink/framework/src/helper.php';

function expectSqliteConnection(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function removeSqliteConnectionFixture(string $path): void
{
    foreach (scandir($path) as $name) {
        if ($name === '.' || $name === '..') continue;
        $child = $path . '/' . $name;
        if (is_dir($child) && !is_link($child)) removeSqliteConnectionFixture($child);
        else unlink($child);
    }
    rmdir($path);
}

$root = sys_get_temp_dir() . '/emby-sqlite-connection-' . bin2hex(random_bytes(8));
mkdir($root);
$app = new think\App($root);
$app->bind('think\DbManager', EmbyDatabase\DbManager::class);
$app->config->set(require $projectRoot . '/config/cache.php', 'cache');
$configuration = require $projectRoot . '/config/database.php';
$configuration['default'] = 'sqlite';
$configuration['connections']['sqlite']['database'] = $root . '/data/nested/controller.sqlite';
$configuration['connections']['sqlite']['prefix'] = 'fixture_';
$app->config->set($configuration, 'database');
$database = $configuration['connections']['sqlite']['database'];
$db = $app->db;
$adapter = null;

try {
    $connection = $db->connect();
    expectSqliteConnection($connection instanceof EmbyDatabase\Sqlite, 'SQLite must use the configured connector');
    $rejected = false;
    try { $connection->query('SELECT 1'); }
    catch (Throwable $e) { $rejected = str_contains($e->getMessage(), 'migrate:run'); }
    expectSqliteConnection($rejected && !is_dir(dirname($database)), 'Requests must not create an empty database');

    $adapter = Phinx\Db\Adapter\AdapterFactory::instance()->getAdapter('sqlite', [
        'name' => $database, 'suffix' => '', 'migration_table' => 'fixture_migrations',
    ]);
    $adapter->setOutput(new think\console\Output());
    $pdo = $adapter->getConnection();
    expectSqliteConnection(is_file($database) && !is_file($database . '.sqlite3'), 'Migrations must create the exact database file and parent directories');
    expectSqliteConnection((int) $pdo->query('PRAGMA busy_timeout')->fetchColumn() === 5000, 'Migration connection must configure busy timeout');
    expectSqliteConnection(strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()) === 'wal', 'Migration connection must configure WAL');
    expectSqliteConnection((int) $connection->query('PRAGMA busy_timeout')[0]['timeout'] === 5000, 'ORM connection must configure busy timeout');
    expectSqliteConnection($connection->query('PRAGMA journal_mode')[0]['journal_mode'] === 'wal', 'ORM connection must configure WAL');

    $db->execute('CREATE TABLE fixture_balance (id INTEGER PRIMARY KEY, balance INTEGER NOT NULL)');
    $db->name('balance')->insert(['id' => 1, 'balance' => 20]);
    $db->startTrans();
    $db->name('balance')->where('id', 1)->update(['balance' => 21]);
    $db->startTrans();
    $db->name('balance')->where('id', 1)->update(['balance' => 22]);
    $db->rollback();
    expectSqliteConnection((int) $db->name('balance')->value('balance') === 21, 'Nested rollback must preserve outer changes');
    $db->startTrans();
    $db->name('balance')->where('id', 1)->update(['balance' => 24]);
    $db->commit();
    $db->rollback();
    expectSqliteConnection((int) $db->name('balance')->value('balance') === 20, 'Outer rollback must undo committed savepoints');
    $db->transaction(function () use ($db) {
        $db->transaction(function () use ($db) { $db->name('balance')->where('id', 1)->update(['balance' => 15]); });
    });
    expectSqliteConnection((int) $pdo->query('SELECT balance FROM fixture_balance')->fetchColumn() === 15, 'Nested and outer commits must persist changes for other connections');
    $rejected = false;
    try {
        $db->transaction(function () use ($db) {
            $db->name('balance')->where('id', 1)->update(['balance' => 0]);
            throw new RuntimeException('rollback fixture');
        });
    } catch (RuntimeException $e) { $rejected = $e->getMessage() === 'rollback fixture'; }
    expectSqliteConnection($rejected && (int) $db->name('balance')->value('balance') === 15, 'Callback failure must roll back the outer transaction');

    // A second writer must fail at BEGIN, before it can read stale business state.
    $otherDb = EmbyDatabase\DbManager::__make($app->event, $app->config, $app->log, $app->cache);
    $other = $otherDb->connect();
    $other->connect()->exec('PRAGMA busy_timeout = 30');
    $db->startTrans();
    $db->name('balance')->where('id', 1)->update(['balance' => 10]);
    $blocked = false;
    try { $other->startTrans(); } catch (PDOException $e) { $blocked = str_contains($e->getMessage(), 'locked'); }
    expectSqliteConnection($blocked, 'Concurrent transaction must acquire the write lock before reading');
    expectSqliteConnection((int) $other->name('balance')->value('balance') === 15, 'WAL readers must see committed state while another writer is active');
    $db->commit();
    $other->startTrans();
    expectSqliteConnection((int) $other->name('balance')->value('balance') === 10, 'A transaction must recover after a failed BEGIN and read fresh state');
    $other->rollback();
    $other->close();

    expectSqliteConnection($db->connect('mysql') instanceof think\db\connector\Mysql, 'MySQL must retain its native connector');
    expectSqliteConnection(EmbyDatabase\Sqlite::databasePath('relative.sqlite', '/project/root/') === '/project/root' . DIRECTORY_SEPARATOR . 'relative.sqlite', 'Relative database paths must use project root');
    foreach (['/app/data/controller.sqlite', 'C:\\data\\controller.sqlite', '\\\\server\\share\\controller.sqlite', ':memory:'] as $path) {
        expectSqliteConnection(EmbyDatabase\Sqlite::databasePath($path, '/ignored') === $path, 'Absolute database path must be preserved');
    }
    echo "PASS: exact SQLite storage path, migration-only creation, WAL/timeout, nested/outer commit and rollback, concurrent writer locking and MySQL connector isolation\n";
} catch (Throwable $e) {
    file_put_contents('php://stderr', 'SQLite connection test failed at line ' . $e->getLine() . ': ' . $e->getMessage() . "\n");
    throw $e;
} finally {
    $db->connect('sqlite')->close();
    if (isset($other)) $other->close();
    if ($adapter) $adapter->disconnect();
    unset($pdo, $connection, $other, $otherDb, $adapter, $db, $app);
    removeSqliteConnectionFixture($root);
}
