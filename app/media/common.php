<?php
// 应用公共文件

use app\media\model\NotificationModel;
use app\media\model\TelegramModel;
use mailer\Mailer;
use think\facade\Cache;
use think\facade\Config;
use app\BaseController;
use Telegram\Bot\Api;
use WebSocket\Client;

function sendTGMessage($id, $message)
{
    $token = Config::get('telegram.botConfig.bots.randallanjie_bot.token');
    if (!$token || $token == 'notgbot') {
        return;
    }
    $telegram = new Api($token);
    $telegramUserModel = new TelegramModel();
    $telegramUser = $telegramUserModel->where('userId', $id)->find();
    if ($telegramUser) {
        $userInfoArray = json_decode(json_encode($telegramUser['userInfo']), true);
        if (isset($userInfoArray['notification']) && ($userInfoArray['notification'] == 1 || $userInfoArray['notification'] == "1")) {
            $telegram->sendMessage([
                'chat_id' => $telegramUser['telegramId'],
                'text' => $message,
                'parse_mode' => 'HTML',
            ]);
        }
    }
}

function sendTGMessageToGroup($message)
{
    $token = Config::get('telegram.botConfig.bots.randallanjie_bot.token');
    if (!$token || $token == 'notgbot') {
        return null;
    }
    $groupSetting = Config::get('telegram.groupSetting');
    $messageId = null;
    if (isset($groupSetting['allow_notify']) && $groupSetting['allow_notify']) {
        $telegram = new Api($token);
        $messageId = $telegram->sendMessage([
            'chat_id' => $groupSetting['chat_id'],
            'text' => $message,
            'parse_mode' => 'HTML',
        ])->getMessageId();
    }
    return $messageId;
}



