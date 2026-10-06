<?php
use think\migration\Migrator;
use think\migration\db\Column;

class AddInitialAdminUser extends Migrator
{
    public function up()
    {
        $password = '$2y$10$rJff.jXkgLpFBN0qE9B.Uu/gnlH2WsUqblAMJOH4iNg7w7OjKJZG6';
        $this->table('user')->insert([
            'userName' => 'admin', 'nickName' => 'admin', 'password' => $password,
            'authority' => 0, 'email' => 'randall@randallanjie.com', 'rCoin' => 0,
        ])->saveData();
    }

    public function down()
    {
        $this->execute("DELETE FROM {$this->getTable('user')} WHERE userName = 'admin';");
    }

    /**
     * 获取带前缀的表名
     */
    private function getTable($name)
    {
        $adapter = $this->getAdapter();
        return $adapter->quoteTableName(($adapter->getOption('table_prefix') ?? '') . $name);
    }
}
