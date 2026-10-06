<?php
return [
    // 默认缓存驱动
    'default' => 'sync',

    // 缓存连接方式配置
    'connections' => [
        'sync' => [
            // 驱动方式
            'type'       => 'Sync',
            'queue'      => 'telegram'
        ],
        // 配置Redis
        'redis'    =>    [
            'type'     => 'redis',
            'host'     => '127.0.0.1',
            'port'     => 6379,
            'password' => '',
            'select'   => 0,
            'expire'   => 0,
            'prefix'   => '',
            'timeout'  => 3,
            'persistent' => false,
            'queue'    => 'telegram',
            'db'       => 0,
        ],
    ],
];
