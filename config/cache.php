<?php

// +----------------------------------------------------------------------
// | 缓存设置
// +----------------------------------------------------------------------

return [
    // 默认缓存驱动
    'default' => 'file',

    // 缓存连接方式配置
    'stores'  => [
        'file' => [
            // 驱动方式
            'type'       => 'File',
            // 多应用共享凭据、验证码与任务缓存，不随 media/api 的 runtime 改变。
            'path'       => root_path() . 'runtime/cache/',
            // 缓存前缀
            'prefix'     => '',
            // 缓存有效期 0表示永久缓存
            'expire'     => 0,
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 序列化机制 例如 ['serialize', 'unserialize']
            'serialize'  => [],
        ],
        // 配置Reids
        'redis'    =>    [
            'type'     => 'redis',
            'host'     => '127.0.0.1',
            'port'     => 6379,
            'password' => '',
            'select'   => 0,
            // 全局缓存有效期（0为永久有效）
            'expire'   => 0,
            // 缓存前缀
            'prefix'   => '',
            'timeout'  => 3,
            'db'       => 0,
        ],
    ],
];
