<?php
namespace app\websocket;

use app\media\model\UserModel;
use GatewayWorker\Lib\Gateway;
use app\media\model\NotificationModel;
use think\facade\Db;
use app\service\SystemSettings;
use Workerman\Timer;

class Events
{
    public static function onWorkerStart($businessWorker)
    {
        echo "Events Worker Started\n";
        // 初始化数据库连接
        try {
            $app = new \think\App(dirname(__DIR__, 2));
            $app->initialize();
            SystemSettings::apply($app, true);
            Db::connect();

            Timer::add(5, function() use ($app) {
                try {
                    SystemSettings::apply($app, true);
                } catch (\Throwable $e) {
                    // 保留现有连接，等待下一轮配置刷新。
                }
            });
            
            echo "Database connected successfully\n";
        } catch (\Exception $e) {
            echo "Database connection error: " . $e->getMessage() . "\n";
        }
    }

    public static function onConnect($client_id)
    {
        echo "New client connected: {$client_id}\n";
        Gateway::sendToClient($client_id, json_encode([
            'type' => 'connected',
            'client_id' => $client_id
        ]));
    }

    public static function onWebSocketConnect($client_id, $data)
    {
        // WebSocket 连接建立时触发
        echo "Client {$client_id} connected\n";
    }

    public static function onMessage($client_id, $message)
    {
        echo "Received message from {$client_id}: {$message}\n";
        $data = json_decode($message, true);
        if ($data['type'] === 'auth') {
            $userId = $data['userId'];

            $userModel = new UserModel();
            $user = $userModel->where('id', $userId)->find();
            if (!$user) {
                echo "User not found: {$userId}\n";
                return;
            } else {
                $key = $data['key'];
                $clientKey = md5($data['userId'] . $user->password);
                if ($key !== $clientKey) {
                    echo "Invalid key for user: {$userId}\n";
                    return;
                } else {
                    Gateway::bindUid($client_id, $userId);
                    Gateway::setSession($client_id, ['userId' => $userId]);

                    // 立即发送一次未读消息数
                    static::sendUnreadCountToUser($userId);
                }
            }
        }
    }

    public static function onClose($client_id)
    {
        echo "Client {$client_id} closed\n";
    }

    public static function sendUnreadCountToUser($userId)
    {
        try {
            $notificationModel = new NotificationModel();
            $unreadCount = $notificationModel
                ->where('toUserId', $userId)
                ->where('readStatus', 0)
                ->count();

            Gateway::sendToUid($userId, json_encode([
                'type' => 'unread_count',
                'count' => $unreadCount
            ]));
        } catch (\Exception $e) {
            echo "Error sending unread count: " . $e->getMessage() . "\n";
        }
    }
}
