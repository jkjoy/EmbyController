<?php

namespace EmbyDatabase;

use PDO;
use RuntimeException;

/** 只有迁移可以创建数据库；普通请求不会悄悄创建空库。 */
class SqliteMigrationAdapter extends \Phinx\Db\Adapter\SQLiteAdapter
{
    protected $suffix = '';

    public function connect(): void
    {
        $options = $this->getOptions();
        if (empty($options['memory']) && ($options['name'] ?? '') !== ':memory:') {
            $directory = dirname($options['name']);
            if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new RuntimeException('Cannot create the SQLite database directory.');
            }
        }
        parent::connect();
    }

    protected function createPdoConnection(string $dsn, ?string $username = null, ?string $password = null, array $options = []): PDO
    {
        $pdo = parent::createPdoConnection($dsn, $username, $password, $options + [PDO::ATTR_TIMEOUT => 5]);
        Sqlite::configure($pdo);
        return $pdo;
    }
}
