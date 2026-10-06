<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'websocket' => 'app\command\WebSocket',
        'settings:queue-worker' => 'app\command\SettingsQueueWorker',
        'settings:import-env' => 'app\command\ImportSettings',
    ],
];
