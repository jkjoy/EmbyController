<?php

namespace app\api\controller;

use app\api\model\EmbyUserModel;
use app\api\model\LotteryModel;
use app\api\model\LotteryParticipantModel;
use app\api\model\MediaHistoryModel;
use app\api\model\TelegramModel;
use app\api\model\UserModel;
use app\media\model\RequestModel;
use app\media\model\SysConfigModel;
use think\facade\Cache;
use think\facade\Config;
use app\BaseController;
use Telegram\Bot\Api;
use think\facade\Request;
use WebSocket\Client;
use think\facade\Db;

class Telegram extends BaseController
{
    public function index()
    {
        $time = time();
        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'time' => $time
            ]
        ]);
    }

    public function ping()
    {
        $time = time();
        return json([
            'code' => 200,
            'msg' => 'pong',
            'data' => [
                'time' => $time
            ]
        ]);
    }

    private $chat_id; //群ID
    private $message_text;//群消息内容
    private $message_id; //消息ID
    private $reply_to_message_id;

    private $autoDeleteMinutes = 0;


    /**
     * 错误代码
     * @var int
     */
    protected $errorCode;

    /**
     * 错误信息
     * @var string
     */
    protected $errorMessage = '';


    /**
     * 返回错误代码
     * @return int
     */
    public function getErrorCode()
    {
        return $this->errorCode;
    }

    /**
     * 返回错误信息
     * @return string
     */
    public function getErrorMessage()
    {
        return $this->errorMessage;
    }


    public function restartWebHook()
    {
        $token = Config::get('telegram.botConfig.bots.randallanjie_bot.token');
        $weburl = Config::get('app.app_host');
        if ($token == 'notgbot') {
            return '请先配置Telegram机器人';
        } else if ($weburl == '') {
            return '请先配置APP_HOST';
        } else {
            $telegram = new Api($token);
            $telegram->removeWebhook();
            $params = ['url' => $weburl . '/api/telegram/listenWebHook'];
            $secret = Config::get('telegram.webhookSecret');
            if ($secret) {
                $params['secret_token'] = $secret;
            }
            $telegram->setWebhook($params);
            return 'success';
        }
    }

    public function listenWebHook()
    {
        $token = Config::get('telegram.botConfig.bots.randallanjie_bot.token');
        if ($token == 'notgbot') {
            return '请先配置Telegram机器人';
        }
        // 校验请求来源：Telegram 在每次回调请求头回传 setWebhook 时设置的 secret_token，比对失败则丢弃，防止伪造 update 冒充任意用户执行资金命令
        $webhookSecret = Config::get('telegram.webhookSecret');
        if ($webhookSecret && !hash_equals((string)$webhookSecret, (string)Request::header('x-telegram-bot-api-secret-token', ''))) {
            return json(['ok' => true]);
        }
        try {
            $telegram = new Api($token);
            $tgMsg = $telegram->getWebhookUpdates();
//            return json(['ok' => true]);

            // 首先检查是否是编辑消息，如果是则直接返回
            if (isset($tgMsg['edited_message'])) {
                return json(['ok' => true]);
            }

            // 检查消息是否存在
            if (!isset($tgMsg['message'])) {
                return json(['ok' => true]);
            }

            $this->chat_id = $tgMsg['message']['chat']['id'];
            $this->message_id = $tgMsg['message']['message_id'];
            $this->reply_to_message_id = $tgMsg['message']['message_id'];

            // 处理不同类型的消息
            if (isset($tgMsg['message']['text'])) {
                $sendInMsg = $this->cleanText($tgMsg['message']['text']);
                // 如果清理后的文本为空，则返回
                if (empty($sendInMsg)) {
                    return json(['ok' => true]);
                }
            } else if (isset($tgMsg['message']['sticker'])) {
                // 如果是贴纸消息，返回成功状态码
                return json(['ok' => true]);
            } else {
                // 其他类型的消息
                $sendInMsg = '暂不支持处理此类型的消息';
            }

            if (isset($tgMsg['edit_date']) && $tgMsg['edit_date'] != $tgMsg['date']) {
                return json(['ok' => true]);
            }

            if (isset($tgMsg['message']['from']['is_bot']) && $tgMsg['message']['from']['is_bot']) {
                return json(['ok' => true]);
            }

            $commonds = [];
            $replyMsg = '';
            $atFlag = false;
            $cmdFlag = false;

            if (isset($tgMsg['message']['entities'])) {
                $entities = $tgMsg['message']['entities'];
                usort($entities, function ($a, $b) {
                    return $b['offset'] - $a['offset'];
                });
                foreach ($entities as $entity) {
                    if ($entity['type'] == 'bot_command') {
                        $cmdFlag = true;
                        $command = substr($sendInMsg, $entity['offset'], $entity['length']);
                        // 处理带有@username的命令
                        $commandParts = explode('@', $command);
                        if (count($commandParts) > 1 && $commandParts[1] == 'randallanjie_bot') {
                            $atFlag = true;
                        }
                        $commonds[] = $commandParts[0];  // 只保留命令部分
                        $sendInMsg = substr($sendInMsg, 0, $entity['offset']) . substr($sendInMsg, $entity['offset'] + $entity['length']);
                    } else if ($entity['type'] == 'mention') {
                        $mention = substr($tgMsg['message']['text'], $entity['offset'], $entity['length']);
                        $sendInMsg = substr($sendInMsg, 0, $entity['offset']) . substr($sendInMsg, $entity['offset'] + $entity['length']);
                        if ($mention == '@randallanjie_bot') {  // 更新为您的机器人用户名
                            $atFlag = true;
                        }
                    }
                }
            }
            $sendInMsg = trim(preg_replace('/\s(?=\s)/', '', $sendInMsg));
            $sendInMsgList = explode(' ', $sendInMsg);
            $useAiReplyFlag = false;
            if (isset($tgMsg['message']['chat']['type']) && $tgMsg['message']['chat']['type'] == 'private') {

                if ($cmdFlag) {
                    foreach ($commonds as $cmd) {
                        if ($cmd == '/start') {
                            if ($sendInMsgList[0] && $sendInMsgList[0] != '') {
                                $key = $sendInMsgList[0];
                                $telegramId = $tgMsg['message']['from']['id'];
                                $result = $this->mediaBind($key, $telegramId);
                                if ($result['message'] == '绑定成功') {
                                    $replyMsg = '绑定成功，欢迎使用' . PHP_EOL;
                                } else {
                                    $replyMsg = $result['message'] . PHP_EOL;
                                }
                            }
                            $replyMsg .= $this->getInfo($tgMsg['message']['from']['id']);

                        } else if ($cmd == '/bind') {
                            if ($sendInMsgList[0] && $sendInMsgList[0] != '') {
                                $key = $sendInMsgList[0];
                                $result = $this->mediaBind($key, $tgMsg['message']['from']['id']);
                                $replyMsg = $result['message'];
                            } else {
                                $replyMsg = '请输入绑定密钥，如：/bind 123456';
                            }
                        } else if ($cmd == '/unbind') {
                            if ($sendInMsgList[0] && $sendInMsgList[0] == 'confirm') {
                                $result = $this->mediaUnBind($tgMsg['message']['from']['id']);
                                $replyMsg = $result['message'];
                            } else {
                                $replyMsg = '如果需要解绑请输入：<code>/unbind confirm</code>';
                            }
                        } else if ($cmd == '/coin') {
                            $replyMsg = $this->getCoin($tgMsg['message']['from']['id']);
                        } else if ($cmd == '/sign') {
                            $replyMsg = $this->getSign($tgMsg['message']['from']['id']);
                        } else if ($cmd == '/notification') {
                            if ($sendInMsgList[0] && $sendInMsgList[0] != '') {
                                if ($sendInMsgList[0] == 'on' || $sendInMsgList[0] == 'off') {
                                    $replyMsg = $this->setNotificationStatus($sendInMsgList[0]);
                                } else {
                                    $replyMsg = '未知参数'.$sendInMsgList[0];
                                }
                            } else {
                                $replyMsg = $this->setNotificationStatus('get');
                            }
                        } else if ($cmd == '/push') {
                            if (count($sendInMsgList) >= 2) {
                                $targetId = $sendInMsgList[0];
                                $amount = $sendInMsgList[1];
                                $replyMsg = $this->pushBalance(
                                    $tgMsg['message']['from']['id'],
                                    $targetId,
                                    $amount
                                );
                            } else {
                                $replyMsg = '请输入正确的转账格式：/push 用户tgID 金额';
                            }
                        } else if ($cmd == '/watchhistory') {
                            $param = trim(str_replace('/watchhistory', '', $sendInMsg));
                            $this->autoDeleteMinutes = 1;
                            $replyMsg = $this->handleWatchHistory($param);
                            $this->addMessageToDeleteQueue(
                                $this->chat_id,
                                $this->message_id,
                                1
                            );
                        } else {
                            $replyMsg = '未知命令'.$cmd;
                        }
                        if ($replyMsg) {
                            $this->message_text = $replyMsg;
                            $this->replayMessage($this->message_text);
                        }
                    }
                } else {
                    $useAiReplyFlag = true;
//                    $this->message_text = getReplyFromAI('chat', $sendInMsg);
//                    if ($this->message_text != '') {
//                        $this->replayMessage($this->message_text);
//                    }
                }
            } else if (isset($tgMsg['message']['chat']['type']) && ($tgMsg['message']['chat']['type'] == 'supergroup' || $tgMsg['message']['chat']['type'] == 'group') && ((isset($tgMsg['message']['chat']['all_members_are_administrators'])) && $tgMsg['message']['chat']['all_members_are_administrators'] == true) || !isset($tgMsg['message']['chat']['all_members_are_administrators'])) {

                // 检查是否是回复机器人的消息
                $isReplyToBot = false;
                if (isset($tgMsg['message']['reply_to_message'])
                    && isset($tgMsg['message']['reply_to_message']['from']['username'])
                    && $tgMsg['message']['reply_to_message']['from']['username'] == Config::get('telegram.botConfig.bots.randallanjie_bot.username')
//                    && isset($tgMsg['message']['reply_to_message']['text'])
                ) {
                    try {
                        $isReplyToBot = true;
                        $message_id = $tgMsg['message']['reply_to_message']['message_id'];
                        // 检查是否是指定的工单信息
                        $requestModel = new RequestModel();
                        $request = $requestModel
                            ->where('requestInfo', 'like', '%' . $message_id . '%')
                            ->select();
                        if ($request) {
                            foreach ($request as $item) {
                                $request = $requestModel->where('id', $item['id'])->find();
                                $requestInfo = $request->requestInfo;
                                if ($requestInfo) {
                                    $requestInfo = json_decode(json_encode($requestInfo), true);
                                    if ($requestInfo['messageId'] == $message_id) {

                                        $telegramModel = new TelegramModel();
                                        $user = $telegramModel
                                            ->where('telegramId', $tgMsg['message']['from']['id'])
                                            ->join('rc_user', 'rc_user.id = rc_telegram_user.userId')
                                            ->field('rc_telegram_user.*, rc_user.nickName, rc_user.userName, rc_user.rCoin, rc_user.authority, rc_user.userInfo as userInfoFromUser')
                                            ->find();
                                        if (!$user) {
                                            $replyMsg = '您还没有绑定管理站账号，请先前往网页注册，进入个人页面最下面链接Telegram账号进行绑定';
                                            $this->autoDeleteMinutes = 1;
                                            $this->message_text = $replyMsg;
                                            $this->replayMessage($this->message_text);
                                        } else {
                                            $message = json_decode($request['message'], true);

                                            $message[] = [
                                                'role' => 'groupeUser',
                                                'userId' => $user['userId'],
                                                'userName' => $user['nickName']??$user['userName'],
                                                'content' => $tgMsg['message']['text'],
                                                'time' => date('Y-m-d H:i:s'),
                                            ];

                                            $requestModel->where('id', $request['id'])->update([
                                                'message' => json_encode($message),
                                            ]);

                                            $replyMsg = '参与回复此工单成功';
                                            $this->autoDeleteMinutes = 1;
                                            $this->message_text = $replyMsg;
                                            $this->replayMessage($this->message_text);
                                        }

                                        return json(['ok' => true]);
                                    }
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        $telegram = new Api(Config::get('telegram.botConfig.bots.randallanjie_bot.token'));
                        $telegram->sendMessage([
                            'chat_id' => Config::get('telegram.adminId'),
                            'text' => '回复消息处理失败：' . $e->getMessage() . '行数：' . $e->getLine(),
                            'parse_mode' => 'HTML',
                        ]);
                    }

                }

                if ($cmdFlag) {  // 如果是命令，不需要检查@标记
                    foreach ($commonds as $cmd) {
                        if ($cmd == '/ping' || $cmd == '/Ping') {
                            $replyMsg = 'Pong';
                        } else if ($cmd == '/coin') {
                            $replyMsg = $this->getCoin($tgMsg['message']['from']['id']);
                        } else if ($cmd == '/knock') {

                            $systemConfigModel = new SysConfigModel();
                            if ($sendInMsg != '') {
                                $telegramModel = new TelegramModel();
                                $user = $telegramModel
                                    ->where('telegramId', $tgMsg['message']['from']['id'])
                                    ->join('rc_user', 'rc_user.id = rc_telegram_user.userId')
                                    ->field('rc_telegram_user.*, rc_user.authority, rc_user.nickName, rc_user.userName, rc_user.rCoin, rc_user.userInfo as userInfoFromUser')
                                    ->find();
                                if (($tgMsg['message']['from']['id'] == Config::get('telegram.adminId')) || ($user && $user['authority'] == 0)) {
                                    $sendInMsg = intval($sendInMsg);
                                    if ($sendInMsg >= -1) {
                                        $systemConfigModel->where('key', 'avableRegisterCount')->update(['value' => $sendInMsg]);
                                        if ($sendInMsg == -1) {
                                            $replyMsg = '设置成功，当前注册数量不受限制';
                                        } else {
                                            $replyMsg = '设置成功，当前可注册数量为：' . $sendInMsg;
                                        }
                                    } else {
                                        $replyMsg = '参数错误';
                                    }
                                } else {
                                    $replyMsg = '您没有权限使用此命令';
                                }

                            } else {
                                $avableRegisterCount = $systemConfigModel->where('key', 'avableRegisterCount')->value('value');
                                if ($avableRegisterCount !== null) {
                                    if ($avableRegisterCount == -1) {
                                        $replyMsg = '当前注册数量不受限制';
                                    } else {
                                        $replyMsg = '当前可注册数量为：' . $avableRegisterCount;
                                    }
                                } else {
                                    $replyMsg = '注册已关闭';
                                }
                            }
                            $this->addMessageToDeleteQueue(
                                $this->chat_id,
                                $this->message_id,
                                1
                            );

                        } else if ($cmd == '/startlottery') {
                            $telegramModel = new TelegramModel();
                            $user = $telegramModel
                                ->where('telegramId', $tgMsg['message']['from']['id'])
                                ->join('rc_user', 'rc_user.id = rc_telegram_user.userId')
                                ->field('rc_telegram_user.*, rc_user.authority, rc_user.nickName, rc_user.userName, rc_user.rCoin, rc_user.userInfo as userInfoFromUser')
                                ->find();
                            if (($tgMsg['message']['from']['id'] == Config::get('telegram.adminId')) || ($user && $user['authority'] == 0)) {
                                $lotteryModel = new LotteryModel();
                                $lottery = $lotteryModel
                                    ->where('chatId', $this->chat_id)
                                    ->where('status', 1)
                                    ->find();
                                if ($lottery) {
                                    $replyMsg = '当前已有进行中的抽奖：' . $lottery['title'] . '，请先结束当前抽奖';
                                } else {
                                    $lottery = $lotteryModel
                                        ->where('status', 0)
                                        ->find();
                                    if ($lottery) {
                                        $lottery->chatId = $this->chat_id;
                                        $lottery->status = 1;
                                        $lottery->save();
                                        $replyMsg = '抽奖已开始' . PHP_EOL;
                                        $replyMsg .= '当前抽奖：' . $lottery['title'] . PHP_EOL;
                                        $replyMsg .= '抽奖时间：' . $lottery['drawTime'] . PHP_EOL;
                                        $replyMsg .= '抽奖关键词：<code>' . $lottery['keywords'] . '</code>' . PHP_EOL;
                                        $replyMsg .= '抽奖奖品：' . PHP_EOL;

                                        $prizes = is_array($lottery['prizes']) ? $lottery['prizes'] : json_decode($lottery['prizes'], true);
                                        if ($prizes) {
                                            foreach ($prizes as $prize) {
                                                $replyMsg .= $prize['name'] . '：' . $prize['count'] . '份' . PHP_EOL;
                                            }
                                        }

                                        $replyMsg .= '抽奖详情：' . $lottery['description'] . PHP_EOL;
                                    } else {
                                        $replyMsg = '当前没有未开始的抽奖';
                                    }
                                }
                            } else {
                                $replyMsg = '您没有权限使用此命令';
                            }
                        } else if ($cmd == '/lottery') {
                            $lotteryModel = new LotteryModel();
                            $lottery = $lotteryModel
                                ->where('chatId', $this->chat_id)
                                ->where('status', 1)
                                ->find();
                            if ($lottery) {
                                $replyMsg = '当前抽奖：' . $lottery['title'] . PHP_EOL;
                                $lotteryParticipantsModel = new LotteryParticipantModel();
                                $participantsCount = $lotteryParticipantsModel
                                    ->where('lotteryId', $lottery['id'])
                                    ->count();
                                $replyMsg .= '当前抽奖人数：' . $participantsCount . PHP_EOL;
                                $replyMsg .= '抽奖时间：' . $lottery['drawTime'] . PHP_EOL;
                                $replyMsg .= '抽奖关键词：<code>' . $lottery['keywords'] . '</code>' . PHP_EOL;
                                $replyMsg .= '抽奖奖品：' . PHP_EOL;

                                $prizes = is_array($lottery['prizes']) ? $lottery['prizes'] : json_decode($lottery['prizes'], true);
                                if ($prizes) {
                                    foreach ($prizes as $prize) {
                                        $replyMsg .= $prize['name'] . '：' . $prize['count'] . '份' . PHP_EOL;
                                    }
                                }
                                $replyMsg .= '抽奖详情：' . $lottery['description'] . PHP_EOL;
                            } else {
                                $replyMsg = '当前没有进行中的抽奖';
                            }
                        } else if ($cmd == '/exitlottery') {
                            $lotteryModel = new LotteryModel();
                            $lottery = $lotteryModel
                                ->where('chatId', $this->chat_id)
                                ->where('status', 1)
                                ->find();
                            if ($lottery) {
                                $lotteryParticipantsModel = new LotteryParticipantModel();
                                $participant = $lotteryParticipantsModel
                                    ->where('lotteryId', $lottery['id'])
                                    ->where('telegramId', $tgMsg['message']['from']['id'])
                                    ->where('status', 0)  // 只能退出未开奖的参与记录
                                    ->find();

                                if ($participant) {
                                    // 删除参与记录
                                    $lotteryParticipantsModel
                                        ->where('id', $participant['id'])
                                        ->delete();

                                    $this->addMessageToDeleteQueue(
                                        $this->chat_id,
                                        $this->message_id,
                                        1
                                    );
                                    $replyMsg = '您已成功退出抽奖「' . $lottery['title'] . '」';
                                } else {
                                    $this->addMessageToDeleteQueue(
                                        $this->chat_id,
                                        $this->message_id,
                                        1
                                    );
                                    $replyMsg = '您未参与当前进行中的抽奖';
                                }
                            } else {
                                $replyMsg = '当前没有进行中的抽奖';
                            }
                        } else if ($cmd == '/startbet') {
                            $telegramModel = new TelegramModel();
                            $user = $telegramModel
                                ->where('telegramId', $tgMsg['message']['from']['id'])
                                ->join('rc_user', 'rc_user.id = rc_telegram_user.userId')
                                ->field('rc_telegram_user.*, rc_user.authority, rc_user.nickName, rc_user.userName, rc_user.rCoin, rc_user.userInfo as userInfoFromUser')
                                ->find();
                            if (($tgMsg['message']['from']['id'] == Config::get('telegram.adminId')) || ($user && $user['authority'] == 0)) {
                                $replyMsg = $this->startBet(
                                    $tgMsg['message']['chat']['id'],
                                    $tgMsg['message']['from']['id'],
                                    $sendInMsg
                                );
                            } else {
                                $replyMsg = '您没有权限使用此命令';
                            }
                        } else if ($cmd == '/bet') {
                            if (count($sendInMsgList) >= 2) {
                                $type = $sendInMsgList[0];
                                $amount = $sendInMsgList[1];
                                $replyMsg = $this->placeBet(
                                    $tgMsg['message']['chat']['id'],
                                    $tgMsg['message']['from']['id'],
                                    $type,
                                    $amount
                                );

                            } else {
                                $replyMsg = '请输入正确的投注格式：/bet 大/小 金额';
                            }
                        } else if ($cmd == '/push') {

                            // 检查有几个参数
                            if (count($sendInMsgList) >= 2) {
                                $targetId = $sendInMsgList[0];
                                $amount = $sendInMsgList[1];
                                $replyMsg = $this->pushBalance(
                                    $tgMsg['message']['from']['id'],
                                    $targetId,
                                    $amount
                                );
                            } else if (count($sendInMsgList) == 1) {
                                // 检查是不是回复了某个人的消息
                                if (isset($tgMsg['message']['reply_to_message']) &&
                                    isset($tgMsg['message']['reply_to_message']['from']['id'])) {
                                    $amount = $sendInMsgList[0];

                                    $replyMsg = $this->pushBalance(
                                        $tgMsg['message']['from']['id'],
                                        $tgMsg['message']['reply_to_message']['from']['id'],
                                        $amount
                                    );

                                } else {
                                    $replyMsg = '请输入正确的转账格式：/push 用户tgID 金额，或者回复某人的消息并输入：/push 金额';
                                }
                            } else {
                                $replyMsg = '请输入正确的转账格式：/push 用户tgID 金额，或者回复某人的消息并输入：/push 金额';
                            }
                        } else if ($cmd == '/watchhistory') {
                            $param = trim(str_replace('/watchhistory', '', $sendInMsg));
                            $this->autoDeleteMinutes = 1;
                            $replyMsg = $this->handleWatchHistory($param);
                            $this->addMessageToDeleteQueue(
                                $this->chat_id,
                                $this->message_id,
                                1
                            );
//                        } else if ($cmd == '/detail') {
//                            $telegramModel = new TelegramModel();
//                            $adminUser = $telegramModel
//                                ->where('telegramId', $tgMsg['message']['from']['id'])
//                                ->join('rc_user', 'rc_user.id = rc_telegram_user.userId')
//                                ->field('rc_telegram_user.*, rc_user.authority')
//                                ->find();
//
//                            // 检查是否是管理员
//                            if (!($tgMsg['message']['from']['id'] == Config::get('telegram.adminId') ||
//                                ($adminUser && $adminUser['authority'] == 0))) {
//                                $replyMsg = '您没有权限使用此命令';
//                            } else {
//                                $targetTelegramId = null;
//
//                                // 检查是否是回复某人的消息
//                                if (isset($tgMsg['message']['reply_to_message']) &&
//                                    isset($tgMsg['message']['reply_to_message']['from']['id'])) {
//                                    $targetTelegramId = $tgMsg['message']['reply_to_message']['from']['id'];
//                                } else if (!empty($sendInMsgList[0])) {
//                                    // 如果提供了用户ID参数
//                                    $targetTelegramId = trim($sendInMsgList[0]);
//                                }
//
//                                if (!$targetTelegramId) {
//                                    $replyMsg = '请指定要查询的用户ID或回复用户的消息';
//                                } else {
//                                    // 获取用户信息
//                                    $targetUser = $telegramModel
//                                        ->where('telegramId', $targetTelegramId)
//                                        ->join('rc_user', 'rc_user.id = rc_telegram_user.userId')
//                                        ->field('rc_telegram_user.*, rc_user.nickName, rc_user.userName, rc_user.rCoin, rc_user.authority, rc_user.email, rc_user.createdAt')
//                                        ->find();
//
//                                    if (!$targetUser) {
//                                        $replyMsg = '未找到该用户信息';
//                                    } else {
//                                        // 获取Emby账号信息
//                                        $embyUserModel = new EmbyUserModel();
//                                        $embyUser = $embyUserModel->where('userId', $targetUser['userId'])->find();
//
//                                        // 构建详细信息消息
//                                        $detailMsg = "👤 用户详细信息\n\n";
//                                        $detailMsg .= "📝 基本信息：\n";
//                                        $detailMsg .= "用户ID：{$targetUser['userId']}\n";
//                                        $detailMsg .= "TG ID：{$targetUser['telegramId']}\n";
//                                        $detailMsg .= "用户名：{$targetUser['userName']}\n";
//                                        $detailMsg .= "昵称：" . ($targetUser['nickName'] ?: '未设置') . "\n";
//                                        $detailMsg .= "邮箱：{$targetUser['email']}\n";
//                                        $detailMsg .= "注册时间：{$targetUser['createdAt']}\n";
//                                        $detailMsg .= "用户等级：" . ($targetUser['authority'] == 0 ? '管理员' : 'Exp' . $targetUser['authority']) . "\n";
//                                        $detailMsg .= "账户余额：" . number_format($targetUser['rCoin'], 2) . " R\n\n";
//
//                                        if ($embyUser) {
//                                            $detailMsg .= "📺 Emby账号信息：\n";
//                                            $detailMsg .= "Emby ID：{$embyUser['embyId']}\n";
//                                            $detailMsg .= "到期时间：" . ($embyUser['activateTo'] ?: '未激活') . "\n";
//
//                                            // 解析userInfo中的额外信息
//                                            if ($embyUser['userInfo']) {
//                                                $userInfo = json_decode($embyUser['userInfo'], true);
//                                                if ($userInfo) {
//                                                    if (isset($userInfo['autoRenew'])) {
//                                                        $detailMsg .= "自动续期：" . ($userInfo['autoRenew'] ? '开启' : '关闭') . "\n";
//                                                    }
//                                                }
//                                            }
//                                        } else {
//                                            $detailMsg .= "📺 Emby账号：未绑定\n";
//                                        }
//
//                                        // 发送私信给管理员
//                                        $token = Config::get('telegram.botConfig.bots.randallanjie_bot.token');
//                                        if ($token) {
//                                            $telegram = new Api($token);
//                                            $telegram->sendMessage([
//                                                'chat_id' => $tgMsg['message']['from']['id'],
//                                                'text' => $detailMsg,
//                                                'parse_mode' => 'HTML'
//                                            ]);
//
//                                            // 在群组中回复一个简短的消息
//                                            if (isset($tgMsg['message']['chat']['type']) &&
//                                                ($tgMsg['message']['chat']['type'] == 'group' ||
//                                                    $tgMsg['message']['chat']['type'] == 'supergroup')) {
//                                                $replyMsg = '已通过私信发送用户详细信息';
//                                            }
//                                        }
//                                    }
//                                }
//
//                            }

                        } else {
                            if ($atFlag || $isReplyToBot) {
                                $replyMsg = '未知命令或该命令不支持在群组中使用';
                            }
                        }
                        if ($replyMsg) {
                            $this->autoDeleteMinutes = 1;
                            $this->message_text = $replyMsg;
                            $this->replayMessage($this->message_text);
                        }
                    }
                } else {
                    if ($atFlag || $isReplyToBot) {
                        $useAiReplyFlag = true;
                    } else {
                        $lotteryModel = new LotteryModel();
                        $lottery = $lotteryModel
                            ->where('chatId', $this->chat_id)
                            ->where('status', 1)
                            ->find();
                        if ($lottery && $lottery['keywords'] == $sendInMsg) {
                            // 判断是否绑定了账号
                            $telegramModel = new TelegramModel();
                            $user = $telegramModel
                                ->where('telegramId', $tgMsg['message']['from']['id'])
                                ->join('rc_user', 'rc_user.id = rc_telegram_user.userId')
                                ->field('rc_telegram_user.*, rc_user.nickName, rc_user.userName, rc_user.rCoin, rc_user.authority, rc_user.userInfo as userInfoFromUser')
                                ->find();
                            if (!$user) {
                                $replyMsg = '您还没有绑定管理站账号，请先前往网页注册，进入个人页面最下面链接Telegram账号进行绑定';
                            } else {
                                $lotteryParticipantsModel = new LotteryParticipantModel();
                                $participants = $lotteryParticipantsModel
                                    ->where('lotteryId', $lottery['id'])
                                    ->where('telegramId', $tgMsg['message']['from']['id'])
                                    ->find();
                                if ($participants) {
                                    $replyMsg = '您已经参与过此次抽奖';
                                } else {
                                    $canParticipate = true;
                                    $description = $lottery['description'];
                                    $lockTime = 0;
                                    $lockCount = 0;
                                    $lockTimePattern = '/「LockTime-(\d+)h-(\d+)」/';
                                    if (preg_match($lockTimePattern, $description, $matches)) {
                                        $lockTime = intval($matches[1]);
                                        $lockCount = intval($matches[2]);
                                    }
                                    if ($lockTime > 0 && $lockCount > 0) {
                                        $lockTime = $lockTime * 3600;
                                        $lockTime = time() - $lockTime;
                                        $mediaHistoryModel = new MediaHistoryModel();
                                        $historyList = $mediaHistoryModel
                                            ->where('userId', $user['userId'])
                                            // 根据时间从旧到新排序
                                            ->order('createdAt', 'asc')
                                            // 选出$lockCount条记录
                                            ->limit($lockCount)
                                            ->select();
                                        $historyCount = 0;
                                        foreach ($historyList as $history) {
                                            if ($history['createdAt'] !== null && strtotime($history['createdAt']) < $lockTime) {
                                                $historyCount++;
                                            }
                                            if ($historyCount >= $lockCount) {
                                                break;
                                            }
                                        }
                                        if ($historyCount < $lockCount) {
                                            $replyMsg = '您在的规定时间内的观影次数为' . $historyCount . '次，未达到要求，无法参与抽奖';
                                            $canParticipate = false;
                                        }
                                    }

                                    $lockExp = 0;
                                    $lockExpPattern = '/「LockExp-(\d+)」/';
                                    if (preg_match($lockExpPattern, $description, $matches)) {
                                        $lockExp = intval($matches[1]);
                                    }

                                    if ($lockExp > 0) {
                                        if ($user['authority'] < $lockExp && $user['authority'] != 0) {
                                            $replyMsg = '您的Exp为' . $user['authority'] . '，未达到要求，无法参与抽奖';
                                            $canParticipate = false;
                                        }
                                    }

                                    if ($canParticipate) {
                                        $lotteryParticipantsModel->save([
                                            'lotteryId' => $lottery['id'],
                                            'telegramId' => $tgMsg['message']['from']['id'],
                                            'status' => 0,
                                        ]);
                                        $replyMsg = '参与成功';
                                    }
                                }
                            }
                            $this->autoDeleteMinutes = 1;
                            $this->message_text = $replyMsg;
                            $this->replayMessage($this->message_text);
                        } else {
                            if (strpos($sendInMsg, '机器人') !== false) {
                                $useAiReplyFlag = true;
                            }
                        }
                    }

                }
            } else {
                return json(['ok' => true]);
            }

            $isGroup = isset($tgMsg['message']['chat']['type']) &&
                ($tgMsg['message']['chat']['type'] == 'group' ||
                    $tgMsg['message']['chat']['type'] == 'supergroup');

            $alreadyInsertHistory = false;
            if (Cache::has('tg_last_msg_' . $this->chat_id)) {
                $lastMsg = json_decode(Cache::get('tg_last_msg_' . $this->chat_id), true);
                if (isset($lastMsg['message']['from']['id']) && isset($lastMsg['message']['text']) &&
                    isset($tgMsg['message']['from']['id']) && isset($tgMsg['message']['text']) &&
                    isset($lastMsg['message']['chat']['id']) && isset($tgMsg['message']['chat']['id']) &&
                    $lastMsg['message']['chat']['id'] == $tgMsg['message']['chat']['id'] &&
                    $lastMsg['message']['from']['id'] == $tgMsg['message']['from']['id'] &&
                    $lastMsg['message']['text'] == $tgMsg['message']['text']) {
                    $alreadyInsertHistory = true;
                }
            }

            if (!$alreadyInsertHistory) {
                Cache::set('tg_last_msg_' . $this->chat_id, json_encode($tgMsg));
                if (isset($tgMsg['message']['text'])) {
                    $this->addChatHistory(
                        $tgMsg['message']['chat']['id'],
                        $tgMsg['message']['from']['id'],
                        $tgMsg['message']['text'],
                        $isGroup
                    );
                }
            }

            if ($useAiReplyFlag){
                // 在调用AI时加入历史记录
                if (isset($tgMsg['message']['chat']['type']) && $tgMsg['message']['chat']['type'] == 'private') {
                    if (!$cmdFlag) {
                        $chatHistory = $this->getChatHistory($tgMsg['message']['chat']['id']);
                        $this->message_text =
//                            "对话历史" . $chatHistory .
                            getReplyFromAI('chat',
                                "这是之前的对话记录：\n" . $chatHistory .
                                "\n现在用户说：" . $sendInMsg
                            );
                        if ($this->message_text != '') {
                            $this->addChatHistory(
                                $tgMsg['message']['chat']['id'],
                                0,
                                $this->message_text,
                                $isGroup
                            );
                            $this->replayMessage($this->message_text);
                        }
                    }
                } else if ($atFlag && !$cmdFlag) {
                    if ($tgMsg['message']['chat']['id'] != Config::get('telegram.groupSetting.chat_id')) {
                        return json(['ok' => true]);
                    }
                    $chatHistory = $this->getChatHistory($tgMsg['message']['chat']['id']);
                    $this->message_text =
//                        "对话历史" . $chatHistory .
                        getReplyFromAI('chat',
                            "这是群里最近的对话记录：\n" . $chatHistory .
                            "\n现在用户说：" . $sendInMsg
                        );
                    if ($this->message_text != '') {
                        $this->addChatHistory(
                            $tgMsg['message']['chat']['id'],
                            0,
                            $this->message_text,
                            $isGroup
                        );
                        $this->replayMessage($this->message_text);
                    }
                } else if (strpos($sendInMsg, '机器人') !== false) {
                    // 如果不是指定群组，直接return
                    if ($tgMsg['message']['chat']['id'] != Config::get('telegram.groupSetting.chat_id')) {
                        return json(['ok' => true]);
                    }
                    $chatHistory = $this->getChatHistory($tgMsg['message']['chat']['id']);
                    $this->message_text =
//                        "对话历史" . $chatHistory .
                        getReplyFromAI('chat',
                            "这是群里最近的对话记录：\n" . $chatHistory .
                            "\n现在用户说：\"" . $sendInMsg . "\"，如果他是在找你（机器人）或者向你进行询问，请用简短的一句话回应他。如果不是在找你，你就要尽全力回答他的问题，如果你实在无法回答，你就说一声"
                        );
                    if ($this->message_text != '') {
                        $this->addChatHistory(
                            $tgMsg['message']['chat']['id'],
                            0,
                            $this->message_text,
                            $isGroup
                        );
                        $this->replayMessage($this->message_text);
                    }
                }
            }

            if (isset($tgMsg['message']['reply_to_message']) &&
                isset($tgMsg['message']['reply_to_message']['message_id']) &&
                isset($tgMsg['message']['reply_to_message']['from']['id'])) {
                $this->reply_to_message_id = $tgMsg['message']['reply_to_message']['message_id'];
            }

            if (isset($tgMsg['message']['text'])) {
                $autoReply = $this->handleAutoReply(
                    $tgMsg['message']['text'],
                    $tgMsg['message']['from']['id']
                );
                
                if ($autoReply) {
                    $this->message_text = $autoReply['reply'];
                    $this->autoDeleteMinutes = $autoReply['deleteTime'];
                    $this->replayMessage($this->message_text);
                    return json(['ok' => true]);
                }
            }
        } catch (\Exception $exception) {
            $message = '第' . $exception->getLine() . '行发生错误：' . $exception->getMessage();
            // 错误内容
            $telegram = new Api(Config::get('telegram.botConfig.bots.randallanjie_bot.token'));
            $telegram->sendMessage([
                'chat_id' => Config::get('telegram.adminId'),
                'text' => $message . PHP_EOL . 'get: ' . json_encode(Request::get()) . PHP_EOL . 'post: ' . json_encode(Request::post()),
                'parse_mode' => 'HTML',
            ]);
            $telegram->sendMessage([
                'chat_id' => Config::get('telegram.adminId'),
                'text' => $exception->getTraceAsString(),
                'parse_mode' => 'HTML',
            ]);
            return json(['ok' => true]);
        }
    }


    private function replayMessage($result)
    {
        $telegram = new Api(Config::get('telegram.botConfig.bots.randallanjie_bot.token'));
        try {
            $response = $telegram->sendMessage([
                'chat_id' => $this->chat_id,
                'text' => $result??$this->message_text,
                'parse_mode' => 'HTML',
                'reply_to_message_id' => $this->reply_to_message_id,
            ]);

            // 如果设置了自动删除时间（分钟），则添加到删除队列
            if (isset($this->autoDeleteMinutes) && $this->autoDeleteMinutes > 0) {
                $this->addMessageToDeleteQueue(
                    $this->chat_id, 
                    $response->getMessageId(), 
                    $this->autoDeleteMinutes
                );
            }

            return $response;
        } catch (\Exception $exception) {
            $this->errorCode = -1;
            $this->errorMessage = $exception->getMessage();
            return false;
        }
    }

    /**
     * 添加消息到删除队列
     * @param int $chatId 聊天ID
     * @param int $messageId 消息ID
     * @param int $minutes 延迟删除的分钟数
     */
    private function addMessageToDeleteQueue($chatId, $messageId, $minutes)
    {
        $data = [
            'chat_id' => $chatId,
            'message_id' => $messageId
        ];
        
        // 将任务添加到队列，设置延迟时间
        $delay = $minutes * 60; // 转换为秒
        \think\facade\Queue::later($delay, 'app\api\job\DeleteTelegramMessage', $data, 'main');
    }

    private function getInfo($telegramId)
    {
        $telegramModel = new TelegramModel();
        $user = $telegramModel
            ->where('telegramId', $telegramId)
            ->join('rc_user', 'rc_user.id = rc_telegram_user.userId')
            ->field('rc_telegram_user.*, rc_user.nickName, rc_user.userName, rc_user.userInfo as userInfoFromUser')
            ->find();
        $message = '';
//        $message.=json_encode($user).PHP_EOL;
        if ($user) {
            $message .= '尊敬的用户 <strong>' . ($user['nickName']??$user['userName']) . '</strong> ';
        }
        $message .= '您好，欢迎使用 @randallanjie_bot' . PHP_EOL;
        if ($telegramId != $this->chat_id) {
//            $message .= '当前群组ID是：<code>' . $this->chat_id . '</code>' . PHP_EOL;
        } else {
            $message .= '您的TelegramID是：<code>' . $this->chat_id . '</code>' . PHP_EOL;
        }

        if ($user) {
            $userInfoArray = json_decode($user['userInfoFromUser'], true);
            if (isset($userInfoArray['lastLoginIp'])) {
                $message .= '您上次登录IP是：' . ($telegramId == $this->chat_id?$userInfoArray['lastLoginIp']:'此项已隐藏') . PHP_EOL;
            }
            if (isset($userInfoArray['lastLoginTime'])) {
                $message .= '您上次登录时间是：' . $userInfoArray['lastLoginTime'] . PHP_EOL;
            }
            if (isset($userInfoArray['lastSignTime']) && $userInfoArray['lastSignTime'] == date('Y-m-d')) {
                $message .= '您今天已签到～' . PHP_EOL;
            } else {
                $message .= '您今天还未签到，请前往站点进行签到～' . PHP_EOL;
            }
        } else {
            $message .= '您还未绑定账号';
        }

        return $message;

    }

    private function mediaBind($key, $telegramId)
    {
        $cacheKey = 'tgBindKey_' . $key;
        if (Cache::has($cacheKey)) {
            $userId = Cache::get($cacheKey);
            $telegramModel = new TelegramModel();
            if ($telegramModel->where('userId', $userId)->find()) {
                return ['code' => 400, 'message' => '该用户已绑定过'];
            }
            if ($telegramModel->where('telegramId', $telegramId)->find()) {
                return ['code' => 400, 'message' => '该Telegram账号已绑定过'];
            }
            $data = [
                'userId' => $userId,
                'telegramId' => $telegramId,
                'type' => 1,
            ];
            $telegramModel->save($data);
            return ['code' => 200, 'message' => '绑定成功'];
        } else {
            return ['code' => 400, 'message' => '绑定密钥无效'];
        }
    }

    private function mediaUnBind($telegramId)
    {
        $telegramModel = new TelegramModel();
        $user = $telegramModel->where('telegramId', $telegramId)->find();
        if ($user) {
            $telegramModel->where('telegramId', $telegramId)->delete();
            return ['code' => 200, 'message' => '解绑成功'];
        } else {
            return ['code' => 400, 'message' => '未绑定账号'];
        }
    }

    private function getCoin($telegramId)
    {
        $telegramModel = new TelegramModel();
        $user = $telegramModel
            ->where('telegramId', $telegramId)
            ->join('rc_user', 'rc_user.id = rc_telegram_user.userId')
            ->field('rc_telegram_user.*, rc_user.nickName, rc_user.userName, rc_user.rCoin, rc_user.authority, rc_user.userInfo as userInfoFromUser')
            ->find();
        if ($user) {
            return '您的余额是： <strong>' . number_format($user['rCoin'], 2) . '</strong> R币';
        } else {
            return '请先绑定账号';
        }
    }

    private function setNotificationStatus(string $int)
    {
        $telegramModel = new TelegramModel();
        $telegramId = $this->chat_id;
        $user = $telegramModel->where('telegramId', $telegramId)->find();
        if ($user) {
            $userInfoArray = json_decode(json_encode($user['userInfo']), true);
            if ($int == 'get') {
                if (isset($userInfoArray['notification']) && ($userInfoArray['notification'] == 1 || $userInfoArray['notification'] == "1")) {
                    return '您的TG通知状态是： <strong>开启</strong>' . PHP_EOL . '如果需要关闭通知请使用命令：<code>/notification off</code>';
                } else {
                    return '您的TG通知状态是： <strong>关闭</strong>' . PHP_EOL . '如果需要开启通知请使用命令：<code>/notification on</code>';
                }
            } else if ($int == 'on') {
                $userInfoArray['notification'] = 1;
                $telegramModel->where('telegramId', $telegramId)->update(['userInfo' => json_encode($userInfoArray)]);
                return '通知已开启';
            } else if ($int == 'off') {
                $userInfoArray['notification'] = 0;
                $telegramModel->where('telegramId', $telegramId)->update(['userInfo' => json_encode($userInfoArray)]);
                return '通知已关闭';
            }
        } else {
            return '请先绑定账号';
        }
    }

    private function getSign($id)
    {
        $telegramModel = new TelegramModel();
        $telegramId = $id;
        $tgUser = $telegramModel->where('telegramId', $telegramId)->find();
        if ($tgUser) {

            $sysConfigModel = new SysConfigModel();
            $signInMaxAmount = $sysConfigModel->where('key', 'signInMaxAmount')->find();
            if ($signInMaxAmount) {
                $signInMaxAmount = $signInMaxAmount['value'];
            } else {
                $signInMaxAmount = 0;
            }
            $signInMinAmount = $sysConfigModel->where('key', 'signInMinAmount')->find();
            if ($signInMinAmount) {
                $signInMinAmount = $signInMinAmount['value'];
            } else {
                $signInMinAmount = 0;
            }

            if (!($signInMaxAmount > 0 && $signInMinAmount > 0 && $signInMaxAmount >= $signInMinAmount)) {
                return '签到已关闭';
            } else {
                $userModel = new UserModel();
                $user = $userModel->where('id', $tgUser['userId'])->find();
                $userInfoArray = json_decode(json_encode($user['userInfo']), true);
                if ((isset($userInfoArray['lastSignTime']) && $userInfoArray['lastSignTime'] != date('Y-m-d')) || !isset($userInfoArray['lastSignTime'])) {
                    // 生成两个随机字符串
                    $randStr = substr(md5(time()), 0, 8);
                    $signKey = substr(md5(time()), 8, 8);
                    Cache::set('get_sign_' . $signKey, $randStr, 300);
                    Cache::set('post_signkey_' . $randStr, $user['id'], 300);
                    return '请点击链接签到：<a href="https://randallanjie.com/index/account/sign?signkey=' . $signKey . '">点击签到</a>';
                } else {
                    return '您今天已签到～';
                }
            }
        } else {
            return '请先绑定账号';
        }
    }

    public function sendMsgToGroup()
    {
        // 获取get参数
        $data = Request::get();
        $token = Config::get('telegram.botConfig.bots.randallanjie_bot.token');
        // 判断是否有参数
        if (isset($data['key']) && isset($data['message']) && $data['key'] == Config::get('media.crontabKey') && $token != 'notgbot') {
            $groupSetting = Config::get('telegram.groupSetting');
            if (isset($groupSetting['allow_notify']) && $groupSetting['allow_notify']) {
                $telegram = new Api($token);
                $telegram->sendMessage([
                    'chat_id' => $groupSetting['chat_id'],
                    'text' => $data['message'],
                    'parse_mode' => 'HTML',
                ]);
            }
        }
    }

    private function addChatHistory($chatId, $fromId, $message, $isGroup = false) {
        $key = 'chat_history_' . $chatId;
        $maxMessages = $isGroup ? 50 : 10;

        $history = Cache::get($key, []);

        // 添加新消息
        $history[] = [
            'from_id' => $fromId,
            'message' => $message,
            'time' => time()
        ];

        // 保持最近的消息数量
        if (count($history) > $maxMessages) {
            $history = array_slice($history, -$maxMessages);
        }

        // 设置24小时过期
        Cache::set($key, $history, 24 * 3600);

        // 发送调试信息
//        $telegram = new Api(Config::get('telegram.botConfig.bots.randallanjie_bot.token'));
//        $telegram->sendMessage([
//            'chat_id' => Config::get('telegram.adminId'),
//            'text' => "添加新消息到历史记录：\n聊天ID: {$chatId}\n发送者ID: {$fromId}\n消息内容: {$message}\n当前历史记录数量: " . count($history),
//            'parse_mode' => 'HTML',
//        ]);

        return $history;
    }

    private function getChatHistory($chatId) {
        $history = Cache::get('chat_history_' . $chatId, []);

        // 将历史记录格式化为字符串
        $formattedHistory = '';
        foreach ($history as $msg) {
            if ($msg['from_id'] != 0 || $msg['message'] != '0') {
                $formattedHistory .= "用户" . $msg['from_id'] . "说：" . $msg['message'] . "\n";
            } else {
                $formattedHistory .= "你(鸽子)回复：" . $msg['message'] . "\n";
            }
        }

        // 发送调试信息
//        $telegram = new Api(Config::get('telegram.botConfig.bots.randallanjie_bot.token'));
//        $telegram->sendMessage([
//            'chat_id' => Config::get('telegram.adminId'),
//            'text' => "获取历史记录：\n聊天ID: {$chatId}\n历史记录数量: " . count($history) . "\n完整历史记录：\n" . $formattedHistory,
//            'parse_mode' => 'HTML',
//        ]);

        return $formattedHistory;
    }

    // 清理文本
    private function cleanText($text) {
        // 移除表情符号和其他特殊Unicode字符
        $text = preg_replace('/[\x{1F600}-\x{1F64F}]/u', '', $text); // 表情符号
        $text = preg_replace('/[\x{1F300}-\x{1F5FF}]/u', '', $text); // 其他符号和象形文字
        $text = preg_replace('/[\x{1F680}-\x{1F6FF}]/u', '', $text); // 交通和地图符号
        $text = preg_replace('/[\x{2600}-\x{26FF}]/u', '', $text);   // 杂项符号
        $text = preg_replace('/[\x{2700}-\x{27BF}]/u', '', $text);   // 装饰符号

        // 移除零宽字符
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text);

        // 转换为普通空格并清理多余空格
        $text = str_replace("\xc2\xa0", ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    
    // 添加新的赌博相关方法
    private function startBet($chatId, $telegramId, $message) {
        $betModel = new \app\api\model\BetModel();

        // 检查是否已有进行中的赌博
        $activeBet = $betModel->where('chatId', $chatId)
            ->where('status', 1)
            ->find();

        if ($activeBet) {
            return '当前已有进行中的赌局，请等待结束后再开始新的赌局';
        }

        // 解析随机方式
        $randomType = 'mt_rand'; // 默认使用mt_rand
        if (trim($message) === 'dice') {
            $randomType = 'dice';
        }

        // 创建新赌博
        $betModel->save([
            'chatId' => $chatId,
            'creatorId' => $telegramId,
            'status' => 1,
            'randomType' => $randomType,
            'createTime' => date('Y-m-d H:i:s'),
            'endTime' => date('Y-m-d H:i:s', time() + 300), // 5分钟后结束
        ]);

        return "🎲 新的赌局已开始！\n\n" .
            "随机方式：" . ($randomType == 'dice' ? 'Telegram骰子' : '系统随机') . "\n\n" .
            "规则说明：\n" .
            "1️⃣2️⃣3️⃣ 为小\n" .
            "4️⃣5️⃣6️⃣ 为大\n\n" .
            "参与方式：\n" .
            "发送 /bet 大/小 金额\n" .
            "例如：/bet 小 10\n\n" .
            "赔率说明：奖池为总投注额的95%，按赢家投注比例分配\n" .
            "本局将在5分钟后自动开奖";
    }

    private function placeBet($chatId, $telegramId, $type, $amount) {
        if (!is_numeric($amount) || $amount <= 0) {
            return '请输入有效的投注金额';
        }

        $amount = floatval($amount);
        if ($amount < 1) {
            return '最低投注金额为1R';
        }

        if ($type !== '大' && $type !== '小') {
            return '请选择正确的投注类型（大/小）';
        }

        $betModel = new \app\api\model\BetModel();
        $activeBet = $betModel->where('chatId', $chatId)
            ->where('status', 1)
            ->find();

        if (!$activeBet) {
            return '当前没有进行中的赌局';
        }

        // 检查用户余额
        $telegramModel = new \app\api\model\TelegramModel();
        $user = $telegramModel
            ->where('telegramId', $telegramId)
            ->join('rc_user', 'rc_user.id = rc_telegram_user.userId')
            ->field('rc_telegram_user.*, rc_user.id as userId, rc_user.rCoin')
            ->find();

        if (!$user) {
            return '请先绑定账号后再参与赌局';
        }

        if ($user['rCoin'] < $amount) {
            return '余额不足';
        }

        // 检查是否已经参与
        $betParticipantModel = new \app\api\model\BetParticipantModel();
        $participant = $betParticipantModel
            ->where('betId', $activeBet['id'])
            ->where('telegramId', $telegramId)
            ->find();

        // 如果已经参与，检查是否可以追加投注
        if ($participant) {
            if ($participant['type'] !== $type) {
                return '您已经投注了' . $participant['type'] . '，不能追加投注' . $type;
            }
            
            // 更新投注金额
            Db::startTrans();
            try {
                // 原子扣除余额（带余额校验），防止并发透支
                $deducted = Db::name('user')->where('id', $user['userId'])->where('rCoin', '>=', $amount)->dec('rCoin', $amount)->update();
                if (!$deducted) {
                    Db::rollback();
                    return '余额不足';
                }

                // 新增操作记录
                Db::name('finance_record')->save([
                    'userId' => $user['userId'],
                    'action' => 3,
                    'count' => $amount,
                    'recordInfo' => json_encode([
                        'message' => '参与赌局#' . $activeBet['id'] . '(追加投注)',
                    ]),
                ]);

                // 更新参与记录
                $betParticipantModel->where('id', $participant['id'])->update([
                    'amount' => $participant['amount'] + $amount
                ]);

                // 重新计算当前赔率情况
                $participants = $betParticipantModel
                    ->where('betId', $activeBet['id'])
                    ->select();

                $totalBetAmount = 0;
                $bigBetAmount = 0;
                $smallBetAmount = 0;

                foreach ($participants as $p) {
                    $totalBetAmount += $p['amount'];
                    if ($p['type'] == '大') {
                        $bigBetAmount += $p['amount'];
                    } else {
                        $smallBetAmount += $p['amount'];
                    }
                }

                // 计算当前赔率
                $prizePool = $totalBetAmount * 0.95;
                $bigOdds = $bigBetAmount > 0 ? $prizePool / $bigBetAmount : 0;
                $smallOdds = $smallBetAmount > 0 ? $prizePool / $smallBetAmount : 0;

                Db::commit();

                $message = "✅ 追加投注成功！\n\n" .
                    "投注类型：" . $type . "\n" .
                    "追加金额：" . number_format($amount, 2) . "R\n" .
                    "总投注额：" . number_format($participant['amount'] + $amount, 2) . "R\n" .
                    "开奖时间：" . $activeBet['endTime'] . "\n\n" .
                    "当前赔率：\n" .
                    "大：" . ($bigBetAmount > 0 ? number_format($bigOdds, 2) : "∞") . "倍\n" .
                    "小：" . ($smallBetAmount > 0 ? number_format($smallOdds, 2) : "∞") . "倍\n" .
                    "总投注：" . number_format($totalBetAmount, 2) . "R";

                return $message;

            } catch (\Exception $e) {
                Db::rollback();
                return '追加投注失败，请稍后重试';
            }
        }

        // 首次投注的逻辑保持不变
        Db::startTrans();
        try {
            // 原子扣除余额（带余额校验），防止并发透支
            $deducted = Db::name('user')->where('id', $user['userId'])->where('rCoin', '>=', $amount)->dec('rCoin', $amount)->update();
            if (!$deducted) {
                Db::rollback();
                return '余额不足';
            }

            // 新增操作记录
            Db::name('finance_record')->save([
                'userId' => $user['userId'],
                'action' => 3,
                'count' => $amount,
                'recordInfo' => json_encode([
                    'message' => '参与赌局#' . $activeBet['id'],
                ]),
            ]);

            // 记录参与信息
            $betParticipantModel->save([
                'betId' => $activeBet['id'],
                'telegramId' => $telegramId,
                'userId' => $user['userId'],
                'type' => $type,
                'amount' => $amount,
                'status' => 0
            ]);

            // 计算当前赔率情况
            $participants = $betParticipantModel
                ->where('betId', $activeBet['id'])
                ->where('id', '<>', $betParticipantModel->id)
                ->select();

            $totalBetAmount = 0;
            $bigBetAmount = 0;
            $smallBetAmount = 0;

            foreach ($participants as $p) {
                $totalBetAmount += $p['amount'];
                if ($p['type'] == '大') {
                    $bigBetAmount += $p['amount'];
                } else {
                    $smallBetAmount += $p['amount'];
                }
            }

            // 加入当前投注金额
            $totalBetAmount += $amount;
            if ($type == '大') {
                $bigBetAmount += $amount;
            } else {
                $smallBetAmount += $amount;
            }

            // 计算当前赔率
            $prizePool = $totalBetAmount * 0.95;
            $bigOdds = $bigBetAmount > 0 ? $prizePool / $bigBetAmount : 0;
            $smallOdds = $smallBetAmount > 0 ? $prizePool / $smallBetAmount : 0;

            Db::commit();

            $message = "✅ 投注成功！\n\n" .
                "投注类型：" . $type . "\n" .
                "投注金额：" . number_format($amount, 2) . "R\n" .
                "开奖时间：" . $activeBet['endTime'] . "\n\n" .
                "当前赔率：\n" .
                "大：" . ($bigBetAmount > 0 ? number_format($bigOdds, 2) : "∞") . "倍\n" .
                "小：" . ($smallBetAmount > 0 ? number_format($smallOdds, 2) : "∞") . "倍\n" .
                "总投注：" . number_format($totalBetAmount, 2) . "R";

            return $message;

        } catch (\Exception $e) {
            Db::rollback();
            return '投注失败，请稍后重试';
        }
    }

    private function pushBalance($fromTelegramId, $targetId, $amount) {
        if (!is_numeric($amount) || $amount <= 0) {
            return '请输入有效的转账金额';
        }

        $amount = floatval($amount);
        if ($amount < 1) {
            return '最低转账金额为1R';
        }

        // 计算手续费(2%)
        $fee = $amount * 0.02;
        $totalDeduct = $amount + $fee;

        // 检查发送方用户
        $telegramModel = new TelegramModel();
        $fromUser = $telegramModel
            ->where('telegramId', $fromTelegramId)
            ->join('rc_user', 'rc_user.id = rc_telegram_user.userId')
            ->field('rc_telegram_user.*, rc_user.id as userId, rc_user.rCoin')
            ->find();

        if (!$fromUser) {
            return '请先绑定账号后再使用转账功能';
        }

        if ($fromUser['rCoin'] < $totalDeduct) {
            return '余额不足（需要包含2%手续费）';
        }

        // 处理目标用户ID
        $targetId = trim($targetId, '@');
        if (is_numeric($targetId)) {
            // 如果是数字ID直接查询
            $toUser = $telegramModel
                ->where('telegramId', $targetId)
                ->join('rc_user', 'rc_user.id = rc_telegram_user.userId')
                ->field('rc_telegram_user.*, rc_user.id as userId, rc_user.rCoin')
                ->find();
        } else {
            // 如果是用户名，需要先通过API获取用户ID
            try {
                $token = Config::get('telegram.botConfig.bots.randallanjie_bot.token');
                if (!$token) {
                    throw new \Exception("Telegram bot token not found");
                }
                $telegram = new \Telegram\Bot\Api($token);
                $chat = $telegram->getChat(['chat_id' => '@'.$targetId]);
                if ($chat && isset($chat['id'])) {
                    $toUser = $telegramModel
                        ->where('telegramId', $chat['id'])
                        ->join('rc_user', 'rc_user.id = rc_telegram_user.userId')
                        ->field('rc_telegram_user.*, rc_user.id as userId, rc_user.rCoin')
                        ->find();
                } else {
                    return '找不到目标用户';
                }
            } catch (\Exception $e) {
                return '无法获取目标用户信息';
            }
        }

        if (!$toUser) {
            return '目标用户未绑定账号';
        }

        if ($fromUser['userId'] == $toUser['userId']) {
            return '不能转账给自己';
        }

        // 执行转账
        Db::startTrans();
        try {
            // 原子扣除发送方余额（含手续费，带余额校验），防止并发透支/重复转账
            $deducted = Db::name('user')->where('id', $fromUser['userId'])->where('rCoin', '>=', $totalDeduct)->dec('rCoin', $totalDeduct)->update();
            if (!$deducted) {
                Db::rollback();
                return '余额不足（需要包含2%手续费）';
            }

            // 原子增加接收方余额（避免快照覆盖导致丢币）
            Db::name('user')->where('id', $toUser['userId'])->inc('rCoin', $amount)->update();

            // 记录发送方财务记录
            Db::name('finance_record')->insert([
                'userId' => $fromUser['userId'],
                'action' => 3,
                'count' => $totalDeduct,
                'recordInfo' => json_encode([
                    'message' => '转账给用户#'.$toUser['userId'].'，金额：'.$amount.'R，手续费：'.$fee.'R',
                ]),
            ]);

            // 记录接收方财务记录
            Db::name('finance_record')->insert([
                'userId' => $toUser['userId'],
                'action' => 8,
                'count' => $amount,
                'recordInfo' => json_encode([
                    'message' => '收到来自用户#'.$fromUser['userId'].'的转账',
                ]),
            ]);

            Db::commit();

            // 发送通知给接收方
            try {
                $token = Config::get('telegram.botConfig.bots.randallanjie_bot.token');
                if ($token) {
                    $telegram = new Api($token);
                    $msg = "您收到一笔转账：\n\n" .
                        "转账金额：" . number_format($amount, 2) . "R\n" .
                        "来自：<a href=\"tg://user?id={$fromUser['telegramId']}\">{$fromUser['telegramId']}</a>";
                    $telegram->sendMessage([
                        'chat_id' => $toUser['telegramId'],
                        'text' => $msg,
                        'parse_mode' => 'HTML'
                    ]);
                }
            }  catch (\Exception $exception) {
                $message = '第' . $exception->getLine() . '行发生错误：' . $exception->getMessage();
                // 错误内容
                $telegram = new Api(Config::get('telegram.botConfig.bots.randallanjie_bot.token'));
                $telegram->sendMessage([
                    'chat_id' => Config::get('telegram.adminId'),
                    'text' => $message . PHP_EOL . 'get: ' . json_encode(Request::get()) . PHP_EOL . 'post: ' . json_encode(Request::post()),
                    'parse_mode' => 'HTML',
                ]);
                $telegram->sendMessage([
                    'chat_id' => Config::get('telegram.adminId'),
                    'text' => $exception->getTraceAsString(),
                    'parse_mode' => 'HTML',
                ]);
                return false;
            }

            return "✅ 转账成功！\n\n" .
                "转账金额：" . number_format($amount, 2) . "R\n" .
                "手续费(2%)：" . number_format($fee, 2) . "R\n" .
                "总支出：" . number_format($totalDeduct, 2) . "R\n" .
                "接收方：<a href=\"tg://user?id={$toUser['telegramId']}\">{$toUser['telegramId']}</a>";

        } catch (\Exception $e) {
            Db::rollback();
            return '转账失败，请稍后重试';
        }
    }

    private function handleAutoReply($message, $fromId) {
        // 获取所有规则
        $sysConfigModel = new SysConfigModel();
        $rulesConfig = $sysConfigModel->where('key', 'telegramRules')->find();
        if (!$rulesConfig) {
            return null;
        }

        $rules = json_decode($rulesConfig['value'], true);
        if (!$rules) {
            return null;
        }

        // 获取用户信息（如果已绑定）
        $telegramModel = new TelegramModel();
        $user = $telegramModel
            ->where('telegramId', $fromId)
            ->join('rc_user', 'rc_user.id = rc_telegram_user.userId')
            ->field('rc_telegram_user.*, rc_user.authority')
            ->find();

        // 遍历规则
        foreach ($rules as $rule) {
            $matched = false;
            
            // 检查匹配条件
            switch ($rule['matchType']) {
                case 'contains':
                    $matched = strpos($message, $rule['keyword']) !== false;
                    break;
                case 'equals':
                    $matched = $message === $rule['keyword'];
                    break;
                case 'regex':
                    $matched = @preg_match($rule['keyword'], $message);
                    break;
            }

            if ($matched) {
                // 如果用户已绑定，检查等级规则
                if ($user && isset($rule['levelRules']) && is_array($rule['levelRules'])) {
                    foreach ($rule['levelRules'] as $levelRule) {
                        if ($user['authority'] >= $levelRule['minLevel'] && $user['authority'] <= $levelRule['maxLevel']) {
                            return [
                                'reply' => $levelRule['reply'],
                                'deleteTime' => $rule['deleteTime']
                            ];
                        }
                    }
                }
                
                // 返回默认回复
                return [
                    'reply' => $rule['defaultReply'],
                    'deleteTime' => $rule['deleteTime']
                ];
            }
        }

        return null;
    }

    private function handleWatchHistory($type = '') {
        try {
            // 处理参数
            $type = strtolower(trim($type));
            $validTypes = [
                'movie' => ['movie', '电影'],
                'series' => ['series', 'tv', '剧集', '电视剧']
            ];

            $filterType = null;
            foreach ($validTypes as $key => $aliases) {
                if (in_array($type, $aliases)) {
                    $filterType = $key;
                    break;
                }
            }

            // 如果输入了无效的类型参数
            if ($type && !$filterType) {
                return "⚠️ 无效的参数！\n\n" .
                       "使用方法：\n" .
                       "/watchhistory - 显示所有影片播放统计\n" .
                       "/watchhistory 电影 - 只显示电影播放统计\n" .
                       "/watchhistory 剧集 - 只显示剧集播放统计";
            }

            // 获取24小时内的播放记录
            $mediaHistoryModel = new MediaHistoryModel();
            $startTime = date('Y-m-d H:i:s', strtotime('-24 hours'));

            $records = $mediaHistoryModel
                ->where('updatedAt', '>=', $startTime)
                ->select();

            if ($records->isEmpty()) {
                return '过去24小时没有播放记录';
            }

            // 用于存储每个影片/剧集的播放次数
            $mediaStats = [];

            foreach ($records as $record) {
                $historyInfo = json_decode(json_encode($record['historyInfo']), true);

                // 确定媒体标识和名称
                $isSeries = false;
                if (isset($historyInfo['item'])) {
                    if (isset($historyInfo['item']['SeriesName']) && isset($historyInfo['item']['SeriesId'])) {
                        // 这是一个剧集
                        $isSeries = true;
                        $mediaId = 'series_' . $historyInfo['item']['SeriesId'];
                        $mediaName = $historyInfo['item']['SeriesName'];
                        $mediaYear = isset($historyInfo['item']['ProductionYear']) ? $historyInfo['item']['ProductionYear'] : '';
                    } else {
                        // 这是一个电影
                        $mediaId = $record['mediaId'];
                        $mediaName = $record['mediaName'];
                        $mediaYear = $record['mediaYear'];
                    }
                } else {
                    // 兼容旧数据
                    $mediaId = $record['mediaId'];
                    $mediaName = $record['mediaName'];
                    $mediaYear = $record['mediaYear'];
                }

                // 根据过滤类型跳过不需要的记录
                if ($filterType === 'movie' && $isSeries) continue;
                if ($filterType === 'series' && !$isSeries) continue;

                // 统计播放次数
                if (!isset($mediaStats[$mediaId])) {
                    $mediaStats[$mediaId] = [
                        'name' => $mediaName,
                        'year' => $mediaYear,
                        'count' => 0
                    ];
                }
                $mediaStats[$mediaId]['count']++;
            }

            if (empty($mediaStats)) {
                $typeText = $filterType === 'movie' ? '电影' : ($filterType === 'series' ? '剧集' : '影片');
                return "过去24小时内没有{$typeText}的播放记录";
            }

            // 按播放次数排序
            uasort($mediaStats, function($a, $b) {
                return $b['count'] - $a['count'];
            });

            // 只取前10个
            $mediaStats = array_slice($mediaStats, 0, 10);

            // 构建回复消息
            $typeText = $filterType === 'movie' ? '电影' : ($filterType === 'series' ? '剧集' : '影片');
            $message = "📊 过去24小时最热门{$typeText}TOP10：\n\n";
            $rank = 1;
            foreach ($mediaStats as $media) {
                $title = $media['name'];
                $year = $media['year'] ? "（{$media['year']}）" : '';
                $count = $media['count'];

                $message .= "<strong>{$rank}</strong>. {$title}{$year}\n";
                $message .= " 👥 {$count} 人播放\n\n";
                $rank++;
            }

            return $message;

        } catch (\Exception $e) {
            return '获取播放记录失败' . PHP_EOL.$e->getMessage();
        }
    }
}
