<?php

namespace EmbyDatabase;

/** 保留配置中的标准驱动名，迁移仍使用 Phinx 的 sqlite 适配器。 */
class DbManager extends \think\Db
{
    protected function getConnectionConfig(string $name): array
    {
        $config = parent::getConnectionConfig($name);
        if (($config['type'] ?? '') === 'sqlite') {
            $config['type'] = Sqlite::class;
            $config['builder'] = \think\db\builder\Sqlite::class;
        }
        return $config;
    }
}
