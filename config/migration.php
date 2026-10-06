<?php

return [
    'default' => env('DB_DRIVER', env('DB_TYPE', 'sqlite')),
    
    'paths' => [
        'migrations' => 'database/migrations',
        'seeds' => 'database/seeds'
    ],
    
    'environments' => [
        'default_migration_table' => env('DB_PREFIX', 'rc_') . 'migrations', // 添加表前缀
        'default_database' => env('DB_DRIVER', env('DB_TYPE', 'sqlite')),
        'sqlite' => [
            'adapter' => 'sqlite',
            'name' => \EmbyDatabase\Sqlite::databasePath(env('DB_NAME', 'data/emby-controller.sqlite'), root_path()),
            'suffix' => '',
            'table_prefix' => env('DB_PREFIX', 'rc_'),
        ],
        'mysql' => [
            'adapter' => 'mysql',
            'host' => env('DB_HOST', 'localhost'),
            'name' => env('DB_NAME', 'forge'),
            'user' => env('DB_USER', 'forge'),
            'pass' => env('DB_PASS', ''),
            'port' => env('DB_PORT', '3306'),
            'charset' => env('DB_CHARSET', 'utf8'),
            'table_prefix' => env('DB_PREFIX', 'rc_'), // 添加表前缀配置
        ],
    ],
];
