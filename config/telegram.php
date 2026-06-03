<?php

return [
    // 机器人设置
    'botConfig' => [
        'bots' => [
            'randallanjie_bot' => [
                'token' => env('TG_BOT_TOKEN', 'notgbot'),
                'username' => env('TG_BOT_USERNAME', 'randallanjie_bot'),
            ],
        ]
    ],
    // 管理员设置
    'adminId' => env('TG_BOT_ADMIN_ID', ''),
    // Webhook 来源校验密钥：setWebhook 时作为 secret_token 下发，并校验每次回调请求头 X-Telegram-Bot-Api-Secret-Token。
    // 强烈建议设置为一段随机字符串（仅 A-Z a-z 0-9 _ - ，最长 256 位）；留空则不校验来源（不安全）。
    'webhookSecret' => env('TG_BOT_WEBHOOK_SECRET', ''),
    // 群组设置
    'groupSetting' => [
        // 群组ID
        'chat_id' => env('TG_BOT_GROUP_ID', ''),
        // 是否允许通知
        'allow_notify' => env('TG_BOT_GROUP_NOTIFY', false),
    ]
];