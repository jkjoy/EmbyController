<?php

namespace EmbyDatabase;

use PDO;
use PDOException;

/** SQLite 连接与事务：多个 worker 共享 WAL，写事务在读取前取得写锁。 */
class Sqlite extends \think\db\connector\Sqlite
{
    public static function databasePath(string $file, string $root): string
    {
        if ($file === ':memory:' || str_starts_with($file, '/') || str_starts_with($file, '\\') || preg_match('~^[A-Za-z]:[\\\\/]~', $file)) {
            return $file;
        }
        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $file;
    }

    public static function configure(PDO $pdo): void
    {
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $mode = strtolower((string) $pdo->query('PRAGMA journal_mode = WAL')->fetchColumn());
        if ($mode !== 'wal' && $mode !== 'memory') {
            throw new PDOException('SQLite storage must support WAL journal mode.');
        }
    }

    protected function createPdo($dsn, $username, $password, $params)
    {
        $file = substr($dsn, strlen('sqlite:'));
        if ($file !== ':memory:' && !is_file($file)) {
            throw new PDOException('SQLite database is not initialized; run php think migrate:run first.');
        }
        $pdo = parent::createPdo($dsn, $username, $password, $params);
        self::configure($pdo);
        return $pdo;
    }

    public function startTrans(): void
    {
        $this->initConnect(true);
        if ($this->transTimes === 0) {
            // 结束上次 value()/find() 留下的读游标，避免从旧 WAL 快照升级写事务。
            $this->free();
            $this->linkID->exec('BEGIN IMMEDIATE');
        } else {
            $this->linkID->exec($this->parseSavepoint('trans' . ($this->transTimes + 1)));
        }
        $this->transTimes++;
    }

    public function commit(): void
    {
        if ($this->transTimes === 0) {
            return;
        }
        $this->initConnect(true);
        $this->free();
        $this->linkID->exec($this->transTimes === 1 ? 'COMMIT' : 'RELEASE SAVEPOINT trans' . $this->transTimes);
        $this->transTimes--;
    }

    public function rollback(): void
    {
        if ($this->transTimes === 0) {
            return;
        }
        $this->initConnect(true);
        $this->free();
        if ($this->transTimes === 1) {
            $this->linkID->exec('ROLLBACK');
        } else {
            $this->linkID->exec($this->parseSavepointRollBack('trans' . $this->transTimes));
            $this->linkID->exec('RELEASE SAVEPOINT trans' . $this->transTimes);
        }
        $this->transTimes--;
    }
}
