<?php
use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Timer;
use think\facade\Db;
use think\facade\Cache;
use Channel\Server as ChannelServer;
use think\facade\Config;
use mailer\Mailer;
use app\api\model\LotteryModel;
use app\api\model\LotteryParticipantModel;

require_once __DIR__ . '/vendor/autoload.php';

// 加载 .env 配置
function loadEnv() {
    $envFile = __DIR__ . '/.env';
    if (!file_exists($envFile)) {
        die("未找到 .env 文件\n");
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $config = [];

    foreach ($lines as $line) {
        // 跳过注释
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // 解析配置项
        if (strpos($line, '=') !== false) {
            list($key, $value) = array_map('trim', explode('=', $line, 2));
            // 移除引号
            $value = trim($value, '"\'');
            $config[$key] = $value;
        }
    }

    return $config;
}

try {
    $dotenv = loadEnv();

    // 输出调试信息
    echo "数据库信息：\n";
    echo "DB_HOST: " . ($dotenv['DB_HOST'] ?? 'not set') . "\n";
    echo "DB_NAME: " . ($dotenv['DB_NAME'] ?? 'not set') . "\n";
    echo "DB_USER: " . ($dotenv['DB_USER'] ?? 'not set') . "\n";

    $runInDocker = false;
    if (isset($dotenv['IS_DOCKER'])) {
        $runInDocker = $dotenv['IS_DOCKER'] === 'true';
    } else if (file_exists('/.dockerenv')) {
        $runInDocker = true;
    } else if (getenv('container') === 'docker') {
        $runInDocker = true;
    } else if (file_exists('/proc/1/cgroup')) {
        $cgroup = file_get_contents('/proc/1/cgroup');
        if (strpos($cgroup, 'docker') !== false || strpos($cgroup, 'containerd') !== false) {
            $runInDocker = true;
        }
    } else {
        $runInDocker = false;
    }

    // 定义是否在 Docker 中运行
    define('RUN_IN_DOCKER', $runInDocker);

    define('APP_HOST', $dotenv['APP_HOST'] ?? 'http://127.0.0.1');
    define('CRONTAB_KEY', $dotenv['CRONTAB_KEY'] ?? '');

    // 定义数据库配置
    define('DB_CONFIG', [
        'type'          => $dotenv['DB_TYPE'] ?? 'mysql',
        'hostname'      => $dotenv['DB_HOST'] ?? '127.0.0.1',
        'database'      => $dotenv['DB_NAME'] ?? '',
        'username'      => $dotenv['DB_USER'] ?? '',
        'password'      => $dotenv['DB_PASS'] ?? '',
        'hostport'      => $dotenv['DB_PORT'] ?? '3306',
        'charset'       => $dotenv['DB_CHARSET'] ?? 'utf8',
        'prefix'        => 'rc_',
    ]);

    // 定义 media 配置
    define('MEDIA_CONFIG', [
        'apiKey'    => $dotenv['EMBY_APIKEY'] ?? '',
        'urlBase'   => $dotenv['EMBY_URLBASE'] ?? '',
    ]);

    if ($dotenv['TG_BOT_TOKEN'] == 'notgbot') {
        // 未配置 Telegram 机器人
        define('TG_CONFIG', [
            'tgBotToken'    => '',
            'tgBotAdminId'      => '',
            'tgBotGroupId'      => '',
        ]);
    } else {
        // 定义 TG 配置
        define('TG_CONFIG', [
            'tgBotToken'    => $dotenv['TG_BOT_TOKEN'] ?? '',
            'tgBotAdminId'      => $dotenv['TG_BOT_ADMIN_ID'] ?? '',
            'tgBotGroupId'      => $dotenv['TG_BOT_GROUP_ID'] ?? '',
        ]);
    }

} catch (\Exception $e) {
    die("加载配置错误: " . $e->getMessage() . "\n");
}

// 设置为东八区
date_default_timezone_set('Asia/Shanghai');

// 初始化 Channel 服务器（必须在最前面）
$channel_server = new ChannelServer('127.0.0.1', 2206);

// 修改 Channel 服务器的启动回调
$channel_server->onWorkerStart = function($worker) {
    // 确保 Channel 服务器完全启动
    sleep(1);
    echo "成功启动 Channel 服务器\n";
};

// WebSocket 服务器（内部服务，只监听本地）
$ws = new Worker("websocket://127.0.0.1:2346");
$ws->count = 4;

// 修改 Worker 启动时的初始化
$ws->onWorkerStart = function($worker) {
    // 等待 Channel 服务器启动
    sleep(2); // 给 Channel 服务器足够的启动时间

    $retries = 0;
    $maxRetries = 5;
    $connected = false;

    while ($retries < $maxRetries && !$connected) {
        try {
            // 初始化 Channel 客户端
            \Channel\Client::connect('127.0.0.1', 2206);
            echo "成功连接到 Channel 服务器\n";
            $connected = true;
        } catch (\Exception $e) {
            $retries++;
            echo "尝试 $retries: 连接到 Channel 服务器失败: " . $e->getMessage() . "\n";
            if ($retries < $maxRetries) {
                $sleepTime = pow(2, $retries);
                $sleepTime = min($sleepTime, 30);
                echo "将在 $sleepTime 秒后重试...\n";
                sleep($sleepTime);
            }
        }
    }

    if (!$connected) {
        echo "尝试连接 Channel 服务器失败\n";
        Worker::stopAll();
        return;
    }

    try {
        // 初始化数据库连接
        $config = DB_CONFIG;
        $dbConfig = [
            'default' => 'mysql',
            'connections' => [
                'mysql' => $config
            ]
        ];

        Db::setConfig($dbConfig);

        // 测试数据库连接
        Db::query("SELECT 1");
        echo "数据库连接成功\n";

        // 初始化 WebSocketServer
        global $webSocketServer;
        $webSocketServer = \app\websocket\WebSocketServer::getInstance();

        // 首次启动时执行全量检查
        $workerId = $worker->id;
        if($workerId === 0) { // 只在其中一个进程中执行
            try {
                checkAllExpiredUsers();
            } catch (\Exception $e) {
                $logFile = __DIR__ . '/runtime/log/timer_error.log';
                $time = date('Y-m-d H:i:s');
                $message = "[$time] 全量检查错误: " . $e->getMessage() . "\n";
                file_put_contents($logFile, $message, FILE_APPEND);
            }

            try {
                checkConfigDatabase();
            } catch (\Exception $e) {
                $logFile = __DIR__ . '/runtime/log/timer_error.log';
                $time = date('Y-m-d H:i:s');
                $message = "[$time] 检查数据库系统配置错误: " . $e->getMessage() . "\n";
                file_put_contents($logFile, $message, FILE_APPEND);
            }
        }

        // 检查是否启用了Telegram机器人
        $token = TG_CONFIG['tgBotToken'];
        if (!empty($token)) {
            // 初始化机器人菜单
            $telegram = new \Telegram\Bot\Api($token);

            // 定义命令
            $commands = [
                // 私聊命令
                [
                    'command' => 'start',
                    'description' => '开始使用机器人 - 私聊使用'
                ],
                [
                    'command' => 'bind',
                    'description' => '绑定账号 - 私聊使用'
                ],
                [
                    'command' => 'unbind',
                    'description' => '解绑账号 - 私聊使用'
                ],
                [
                    'command' => 'sign',
                    'description' => '每日签到 - 私聊使用'
                ],
                [
                    'command' => 'notification',
                    'description' => '通知设置 - 私聊使用'
                ],
                [
                    'command' => 'push',
                    'description' => '转账 - 私聊/群组使用'
                ],
                [
                    'command' => 'coin',
                    'description' => '查询余额 - 私聊/群组使用'
                ],
                [
                    'command' => 'ping',
                    'description' => '测试机器人 - 群组使用'
                ],
                [
                    'command' => 'lottery',
                    'description' => '查看抽奖 - 群组使用'
                ],
                [
                    'command' => 'exitlottery',
                    'description' => '退出抽奖 - 群组使用'
                ],
                [
                    'command' => 'bet',
                    'description' => '参与赌局 - 群组使用'
                ],
                [
                    'command' => 'watchhistory',
                    'description' => '查看24小时内服务器播放记录 - 群组使用'
                ],
                [
                    'command' => 'startlottery',
                    'description' => '开始抽奖 - 群组使用(管理员)'
                ],
                [
                    'command' => 'startbet',
                    'description' => '开始赌局 - 群组使用(管理员)'
                ],
                [
                    'command' => 'detail',
                    'description' => '查看用户详细信息 - 群组使用(管理员)'
                ],
            ];

            // 设置命令
            $telegram->setMyCommands([
                'commands' => $commands,
                'scope' => [
                    'type' => 'default'
                ]
            ]);

            echo "成功初始化Telegram机器人命令菜单\n";
        } else {
            echo "未配置Telegram机器人Token,跳过初始化\n";
        }

        // 添加定时任务
        Timer::add(10, function() use ($worker) {
            $workerId = $worker->id;
            if($workerId === 0) {
                try {
                    checkExpiredUsers();
                } catch (\Exception $e) {
                    $logFile = __DIR__ . '/runtime/log/timer_error.log';
                    $time = date('Y-m-d H:i:s');
                    $message = "[$time] 定时检查用户错误: " . $e->getMessage() . "\n";
                    file_put_contents($logFile, $message, FILE_APPEND);
                }
            } else if ($workerId === 1) {
                try {
                    checkLotteryDraw();
                } catch (\Exception $e) {
                    $logFile = __DIR__ . '/runtime/log/lottery_error.log';
                    $time = date('Y-m-d H:i:s');
                    $message = "[$time] 定时检查抽奖错误: " . $e->getMessage() . "\n";
                    file_put_contents($logFile, $message, FILE_APPEND);
                }
            } else if ($workerId === 2) { // 使用另一个worker处理赌博开奖
                try {
                    checkBetResult();
                } catch (\Exception $e) {
                    $logFile = __DIR__ . '/runtime/log/bet_error.log';
                    $time = date('Y-m-d H:i:s');
                    $message = "[$time] 定时检查赌博错误: " . $e->getMessage() . "\n";
                    file_put_contents($logFile, $message, FILE_APPEND);
                }
            }
        });

        Timer::add(600, function() use ($worker) {
            $workerId = $worker->id;
            if($workerId === 0) {
                runCrontab();
            } else if ($workerId === 1) {

            } else if ($workerId === 2) {

            }
        });

        // 定时清理过大的日志文件，防止 runtime/log 下的 *.log 无限增长（每小时检查一次，仅 workerId 0 执行）
        Timer::add(3600, function() use ($worker) {
            if ($worker->id === 0) {
                cleanupLogFiles();
            }
        });


    } catch (\Exception $e) {
        echo "数据库连接错误: " . $e->getMessage() . "\n";
        // 记录错误日志
        $logFile = __DIR__ . '/runtime/log/db_error.log';
        $time = date('Y-m-d H:i:s');
        $message = "[$time] 数据库连接错误: " . $e->getMessage() . "\n";
        file_put_contents($logFile, $message, FILE_APPEND);

        // 终止进程
        Worker::stopAll();
    }
};

$ws->onMessage = function($connection, $data) {
    global $webSocketServer;
    $webSocketServer->onMessage($connection, $data);
};

$ws->onClose = function($connection) {
    global $webSocketServer;
    $webSocketServer->onClose($connection);
};

// WebSocket 代理服务器（对外服务）
$wsProxy = new Worker('websocket://0.0.0.0:2347');
$wsProxy->count = 4;

// 修改 Worker 启动时的初始化
$wsProxy->onWorkerStart = function($worker) {
    // 等待 Channel 服务器启动
    sleep(2);

    $retries = 0;
    $maxRetries = 5;
    $connected = false;

    while ($retries < $maxRetries && !$connected) {
        try {
            // 初始化 Channel 客户端
            \Channel\Client::connect('127.0.0.1', 2206);
            echo "成功连接到 Channel 服务器\n";
            $connected = true;
        } catch (\Exception $e) {
            $retries++;
            echo "代理服务器尝试 $retries: 连接到 Channel 服务器失败: " . $e->getMessage() . "\n";
            if ($retries < $maxRetries) {
                echo "Retrying in 2 seconds...\n";
                sleep(2);
            }
        }
    }

    if (!$connected) {
        echo "代理服务器无法连接到 Channel 服务器\n";
        Worker::stopAll();
        return;
    }

    try {
        // 初始化数据库连接
        $config = DB_CONFIG;
        $dbConfig = [
            'default' => 'mysql',
            'connections' => [
                'mysql' => $config
            ]
        ];

        Db::setConfig($dbConfig);

        // 测试数据库连接
        Db::query("SELECT 1");
        echo "代理服务器已启动\n";
    } catch (\Exception $e) {
        echo "代理服务器数据库连接错误: " . $e->getMessage() . "\n";
        // 记录错误日志
        $logFile = __DIR__ . '/runtime/log/db_error.log';
        $time = date('Y-m-d H:i:s');
        $message = "[$time] 代理服务器数据库连接错误: " . $e->getMessage() . "\n";
        file_put_contents($logFile, $message, FILE_APPEND);

        // 终止进程
        Worker::stopAll();
    }
};

$wsProxy->onConnect = function($connection) {
//    echo "Income新的连接\n";
};

$wsProxy->onWebSocketConnect = function($connection, $httpBuffer) {
//    echo "WebSocket 连接被建立\n";

    // 创建到内部服务器的连接
    $innerConnection = new AsyncTcpConnection('ws://127.0.0.1:2346');
    $connection->innerConnection = $innerConnection;

    // 转发消息
    $innerConnection->onMessage = function($innerConnection, $data) use ($connection) {
        try {
            // 记录转发的消息
            $logFile = __DIR__ . '/runtime/log/proxy.log';
            $time = date('Y-m-d H:i:s');
            $message = "[$time] 转发到客户端: $data\n";
            file_put_contents($logFile, $message, FILE_APPEND);

            $connection->send($data);
        } catch (\Exception $e) {
            // 记录错误
            $logFile = __DIR__ . '/runtime/log/proxy_error.log';
            $time = date('Y-m-d H:i:s');
            $message = "[$time] 转发到客户端错误: " . $e->getMessage() . "\n";
            file_put_contents($logFile, $message, FILE_APPEND);
        }
    };

    $connection->onMessage = function($connection, $data) use ($innerConnection) {
        try {
            // 记录接收到的消息
            $logFile = __DIR__ . '/runtime/log/proxy.log';
            $time = date('Y-m-d H:i:s');
            $message = "[$time] 从客户端接收到消息: $data\n";
            file_put_contents($logFile, $message, FILE_APPEND);

            $innerConnection->send($data);
        } catch (\Exception $e) {
            // 记录错误
            $logFile = __DIR__ . '/runtime/log/proxy_error.log';
            $time = date('Y-m-d H:i:s');
            $message = "[$time] 发送到内部服务器错误: " . $e->getMessage() . "\n";
            file_put_contents($logFile, $message, FILE_APPEND);
        }
    };

    // 处理连接关闭
    $innerConnection->onClose = function($innerConnection) use ($connection) {
//        echo "连接关闭\n";
        $connection->close();
    };

    $connection->onClose = function($connection) {
//        echo "客户端连接关闭\n";
        if (isset($connection->innerConnection)) {
            $connection->innerConnection->close();
        }
    };

    // 处理错误
    $innerConnection->onError = function($connection, $code, $msg) {
        $logFile = __DIR__ . '/runtime/log/proxy_error.log';
        $time = date('Y-m-d H:i:s');
        $message = "[$time] Inner 连接错误: $code - $msg\n";
        file_put_contents($logFile, $message, FILE_APPEND);
    };

    // 连接到内部服务器
    $innerConnection->connect();
};

$wsProxy->onMessage = function($connection, $data) {
    // 记录代理服务器收到的消息
    $logFile = __DIR__ . '/runtime/log/proxy.log';
    $time = date('Y-m-d H:i:s');
    $message = "[$time] 代理服务器收到消息: $data\n";
    file_put_contents($logFile, $message, FILE_APPEND);
};

$wsProxy->onClose = function($connection) {
//    echo "连接关闭\n";
};

$wsProxy->onError = function($connection, $code, $msg) {
    $logFile = __DIR__ . '/runtime/log/proxy_error.log';
    $time = date('Y-m-d H:i:s');
    $message = "[$time] 代理服务器错误: $code - $msg\n";
    file_put_contents($logFile, $message, FILE_APPEND);
};

// 修改 checkExpiredUsers 函数
function checkExpiredUsers() {
    $now = time();
    $startTime = date('Y-m-d H:i:s', $now - 60);
    $endTime = date('Y-m-d H:i:s', $now + 86400);

    // 记录开始检查的时间
    $logFile = __DIR__ . '/runtime/log/check_accounts.log';
    $time = date('Y-m-d\TH:i:s.v\Z', $now);
    $message = "\n定时检测管理站用户: $time\n";
    $message .= "查询周期时间: $startTime to $endTime\n";
    file_put_contents($logFile, $message, FILE_APPEND);

    // 只查询时间段内需要处理的用户
//    $embyUserList = Db::name('emby_user')
//        ->where('activateTo', 'not null')
//        ->where(function ($query) use ($startTime, $endTime, $now) {
//            $query->whereTime('activateTo', 'between', [$startTime, $endTime])
//                ->whereOr(function ($q) use ($now) {
//                    $fiveMinBefore = date('Y-m-d H:i:s', $now + 86400 - 300);
//                    $fiveMinAfter = date('Y-m-d H:i:s', $now + 86400 + 300);
//                    $q->whereTime('activateTo', 'between', [$fiveMinBefore, $fiveMinAfter]);
//                });
//        })
//        ->select();
    $embyUserList = Db::name('emby_user')
        ->where('activateTo', 'not null')
        ->whereTime('activateTo', 'between', [$startTime, $endTime])
        ->select();

    if (empty($embyUserList)) {
        $message = "未找到需要检查的用户\n";
        $message .= "----------------------------------------\n";
        file_put_contents($logFile, $message, FILE_APPEND);
        return;
    }

    $expiredCount = 0;
    $processedCount = 0;
    $autoRenewedCount = 0;

    foreach ($embyUserList as $embyUser) {
        try {
            if ($embyUser['activateTo']) {
                $expireTime = strtotime($embyUser['activateTo']);

                $autoRenew = 0;
                if (!empty($embyUser['userInfo'])) {
                    $userInfo = json_decode($embyUser['userInfo'], true);
                    if ($userInfo !== null && isset($userInfo['autoRenew']) && ($userInfo['autoRenew'] == 1 || $userInfo['autoRenew'] == "1")) {
                        $autoRenew = 1;
                    }
                }

                if ($autoRenew == 1) {
                    $user = Db::name('user')->where('id', $embyUser['userId'])->find();
                    if ($user && $user['rCoin'] >= 10) {
                        // 执行自动续期
                        processAutoRenewal($embyUser, $user);
                        $autoRenewedCount++;
                        sendNotification($user['id'], '您的Emby账号已自动续期');
                        continue;
                    }
                } else {
                    if ($expireTime < $now) {
                        $expiredCount++;
                        // 禁用账号
                        disableEmbyAccount($embyUser['embyId']);
                        sendNotification($embyUser['userId'], '您的Emby账号已过期');
                        $processedCount++;
                    }
                }
            }

        } catch (\Exception $e) {
            // 记录错误日志
            $logFile = __DIR__ . '/runtime/log/user_process_error.log';
            $time = date('Y-m-d H:i:s');
            $message = "[$time] 处理用户 {$embyUser['userId']} 错误: " . $e->getMessage() . "\n";
            // 增加详细信息，显示错误
            $message .= "用户 Info: " . json_encode($embyUser) . "\n";
            $message .= "错误: " . $e->getMessage() . "\n";
            $message .= "行数: " . $e->getTraceAsString() . "\n";
            file_put_contents($logFile, $message, FILE_APPEND);
        }
    }

    // 修改处理结果记录
    $message = "处理小结:\n";
    $message .= "- 找到 " . count($embyUserList) . " 个账号\n";
    $message .= "- 找到 $expiredCount 个过期账号\n";
    $message .= "- 处理 $processedCount 个过期账号\n";
    $message .= "- 自动续期 $autoRenewedCount 个账号\n";
    $message .= "----------------------------------------\n";
    file_put_contents($logFile, $message, FILE_APPEND);
}

// 修改 checkAllExpiredUsers 函数
function checkAllExpiredUsers() {
    $now = time();
    $time = date('Y-m-d\TH:i:s.v\Z', $now);
    $endTime = date('Y-m-d H:i:s', $now + 86400);

    // 记录开始全量检查
    $logFile = __DIR__ . '/runtime/log/check_accounts.log';
    $message = "\n========================================\n";
    $message .= "全量检测管理站用户: $time\n";
    file_put_contents($logFile, $message, FILE_APPEND);

    // 查询所有非永久的用户
    $embyUserList = Db::name('emby_user')
        ->where('activateTo', 'not null')
        ->where('activateTo', '<', $endTime)
        ->select();

    if (empty($embyUserList)) {
        $message = "未找到需要检查的用户\n";
        $message .= "========================================\n";
        file_put_contents($logFile, $message, FILE_APPEND);
        return;
    }

    $totalCount = count($embyUserList);
    $expiredCount = 0;
    $processedCount = 0;
    $autoRenewedCount = 0;

    foreach ($embyUserList as $embyUser) {
        try {
            if ($embyUser['activateTo']) {
                $expireTime = strtotime($embyUser['activateTo']);
                // 如果已过期
                if ($expireTime < $now) {
                    // 禁用账号
                    disableEmbyAccount($embyUser['embyId']);
                    $processedCount++;
                } else if ($expireTime < $now + 86400) {
                    // 自动续期
                    $autoRenew = 0;
                    if (!empty($embyUser['userInfo'])) {
                        $userInfo = json_decode($embyUser['userInfo'], true);
                        if ($userInfo !== null && isset($userInfo['autoRenew']) && ($userInfo['autoRenew'] == 1 || $userInfo['autoRenew'] == "1")) {
                            $autoRenew = 1;
                        }
                    }
                    if ($autoRenew == 1) {
                        $user = Db::name('user')->where('id', $embyUser['userId'])->find();
                        if ($user && $user['rCoin'] >= 10) {
                            // 执行自动续期
                            processAutoRenewal($embyUser, $user);
                            $autoRenewedCount++;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // 记录错误日志
            $logFile = __DIR__ . '/runtime/log/user_process_error.log';
            $time = date('Y-m-d H:i:s');
            $message = "[$time] 处理用户 {$embyUser['userId']} 错误: " . $e->getMessage() . "\n";
            // 增加详细信息，显示错误
            $message .= "用户 Info: " . json_encode($embyUser) . "\n";
            $message .= "错误: " . $e->getMessage() . "\n";
            $message .= "行数: " . $e->getTraceAsString() . "\n";
            file_put_contents($logFile, $message, FILE_APPEND);
        }
    }

    // 修改全量检查结果记录
    $message = "全量检查报告:\n";
    $message .= "- 找到 $totalCount 个账号\n";
    $message .= "- 找到 $expiredCount 个过期账号\n";
    $message .= "- 处理 $processedCount 个过期账号\n";
    $message .= "- 自动续期 $autoRenewedCount 个账号\n";
    $message .= "========================================\n";
    file_put_contents($logFile, $message, FILE_APPEND);
}

// 处理自动续期
function processAutoRenewal($embyUser, $user) {
    Db::startTrans();
    try {
        // 原子扣费，避免与用户其他余额变动（充值/消费）并发时丢失更新
        $deducted = Db::name('user')->where('id', $user['id'])->where('rCoin', '>=', 10)->dec('rCoin', 10)->update();
        if (!$deducted) {
            Db::rollback();
            return;
        }

        // 更新到期时间
        $newExpireTime = strtotime($embyUser['activateTo']) + 2592000; // 30天
        Db::name('emby_user')->where('id', $embyUser['id'])->update([
            'activateTo' => date('Y-m-d H:i:s', $newExpireTime)
        ]);

        // 记录财务记录
        Db::name('finance_record')->insert([
            'userId' => $user['id'],
            'action' => 3,
            'count' => 10,
            'recordInfo' => json_encode([
                'message' => '使用余额自动续期Emby账号'
            ]),
        ]);

        // 发送通知
        sendNotification($user['id'], '您的Emby账号已自动续期至 ' . date('Y-m-d H:i:s', $newExpireTime));

        Db::commit();
    } catch (\Exception $e) {
        echo "处理自动续期错误: " . $e->getMessage() . "\n";
        echo "Rolling back...\n";
        Db::rollback();
        throw $e;
    }
}

// 禁用Emby账号
function disableEmbyAccount($embyId) {
    $apiKey = MEDIA_CONFIG['apiKey'];
    $urlBase = MEDIA_CONFIG['urlBase'];

    $url = $urlBase . 'Users/' . $embyId . '/Policy?api_key=' . $apiKey;
    $data = ['IsDisabled' => true];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'accept: */*',
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (!($httpCode == 200 || $httpCode == 204)) {
        echo "处理emby账号 ". $embyId . "过期失败，响应: $response\n";
        throw new \Exception("Failed to disable Emby account: $response");
    }
}

// 发送通知
function sendNotification($userId, $message) {

    // 检查用户是否有tgId
    $user = Db::name('telegram_user')->where('userId', $userId)->find();
    if ($user && $user['telegramId'] && TG_CONFIG['tgBotToken']) {
        // 发送TG消息
        sendPrivateMessage($user['telegramId'], $message);
    }
}

// 检查抽奖开奖
function checkLotteryDraw() {
    $lotteryModel = new \app\api\model\LotteryModel();
    $participantModel = new \app\api\model\LotteryParticipantModel();

    // Log the start of the lottery check process
    $logFile = __DIR__ . '/runtime/log/lottery_draw.log';
    $now = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$now] 开始检查抽奖\n", FILE_APPEND);

    // 获取所有到期未开奖的抽奖
    $lotteries = $lotteryModel
        ->where('status', 1)
        ->where('drawTime', '<=', date('Y-m-d H:i:s'))
        ->select();

    if ($lotteries->isEmpty()) {
        file_put_contents($logFile, "[$now] 没有需要开奖的抽奖\n", FILE_APPEND);
        return;
    }

    foreach ($lotteries as $lottery) {
        try {
            // Assuming you have a custom DB transaction handler or use native PHP PDO transactions here
            // For example:
            // $db->beginTransaction();

            $lotteryTime = date('Y-m-d H:i:s');

            // 等待随机时间
            $waitTime = mt_rand(1, 5);
            sleep($waitTime);

            file_put_contents($logFile, "[$lotteryTime] 锁定抽奖 #{$lottery['id']} 以进行开奖\n", FILE_APPEND);
            // 原子锁定抽奖（仅当仍为进行中），防止并发/重入重复开奖
            $claimed = $lotteryModel->where('id', $lottery['id'])->where('status', 1)->update(['status' => 3]);
            if (!$claimed) {
                file_put_contents($logFile, "[$lotteryTime] 抽奖 #{$lottery['id']} 已被锁定或处理，跳过\n", FILE_APPEND);
                continue;
            }

            file_put_contents($logFile, "[$lotteryTime] 抽奖 #{$lottery['id']} 已锁定\n", FILE_APPEND);

            // 获取所有参与者
            $participants = $participantModel
                ->where('lotteryId', $lottery['id'])
                ->where('status', 0)
                ->select()
                ->toArray();

            if (empty($participants)) {
                file_put_contents($logFile, "[$lotteryTime] 抽奖 #{$lottery['id']} 没有参与者，标记为已完成\n", FILE_APPEND);
                $lotteryModel->where('id', $lottery['id'])->update(['status' => 2]);
                file_put_contents($logFile, "[$lotteryTime] 更新抽奖 #{$lottery['id']} 状态为已完成，因为没有参与者\n", FILE_APPEND);
                // $db->commit();
                continue;
            }

            file_put_contents($logFile, "[$lotteryTime] 参与者数量：" . count($participants) . "\n", FILE_APPEND);

            // 打乱参与者顺序
            shuffle($participants);

            $winnersList = [];  // 用于存储所有获奖者信息
            $prizes = is_array($lottery['prizes']) ? $lottery['prizes'] : json_decode($lottery['prizes'], true);

            file_put_contents($logFile, "[$lotteryTime] 抽奖 #{$lottery['id']} 的奖项结构:\n" . json_encode($prizes, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

            // 处理每个奖项
            foreach ($prizes as $prizeIndex => $prize) {
                $winnersList[$prize['name']] = [];
                $prizeWinners = array_splice($participants, 0, min($prize['count'], count($participants)));

                file_put_contents($logFile, "[$lotteryTime] 抽取 {$prize['name']} 奖品\n", FILE_APPEND);

                foreach ($prizeWinners as $winner) {
                    try {
                        // Use telegramId and lotteryId for uniqueness
                        $uniqueIdentifier = ['telegramId' => $winner['telegramId'], 'lotteryId' => $lottery['id']];
                        $participant = $participantModel->where($uniqueIdentifier)->find();

                        if ($participant) {
                            $prizeContent = $prize['contents'][count($winnersList[$prize['name']])] ?? $prize['contents'][0];
                            $expAwarded = false;

                            if (preg_match('/「Exp(\d+)」/', $prizeContent, $matches)) {
                                $exp = intval($matches[1]);
                                $telegramUserModel = new \app\api\model\TelegramModel();
                                $tgUser = $telegramUserModel->where('telegramId', $winner['telegramId'])->find();

                                if (!$tgUser) {
                                    // 如果找不到TG用户,直接标记为未中奖
                                    file_put_contents($logFile, "[$lotteryTime] 找不到用户 {$winner['telegramId']} 的TG账号,标记为未中奖\n", FILE_APPEND);
                                    $participantModel->where($uniqueIdentifier)->update(['status' => 2]);
                                    continue;
                                }

                                $userid = $tgUser['userId'];
                                $userModel = new \app\api\model\UserModel();
                                $user = $userModel->where('id', $userid)->find();

                                if ($user && $user['authority'] >= 0) {
                                    $authority = $user['authority'];
                                    if ($authority >= 0) {
                                        if ($authority > 0) {
                                            $authority = $authority + $exp;
                                        }
                                        if ($authority > 100) {
                                            $authority = 100;
                                        }
                                        $userModel->where('id', $userid)->update(['authority' => $authority]);
                                        $expAwarded = true;
                                        file_put_contents($logFile, "[$lotteryTime] 更新用户 {$winner['telegramId']} 的经验为 Exp{$authority}\n", FILE_APPEND);
                                    }
                                }

                                // 如果无法兑换经验，需要重新抽取一位获奖者
                                if (!$expAwarded) {
                                    file_put_contents($logFile, "[$lotteryTime] 用户 {$winner['telegramId']} 无法兑换经验，重新抽取获奖者\n", FILE_APPEND);

                                    // 将当前参与者标记为未中奖
                                    $participantModel->where($uniqueIdentifier)->update(['status' => 2]);

                                    // 从剩余参与者中重新抽取一位
                                    $newWinner = $participantModel
                                        ->where('lotteryId', $lottery['id'])
                                        ->where('status', 0)
                                        ->orderRaw('RAND()')
                                        ->find();

                                    if ($newWinner) {
                                        file_put_contents($logFile, "[$lotteryTime] 重新抽取到新获奖者 {$newWinner['telegramId']}\n", FILE_APPEND);
                                        // 递归处理新获奖者
                                        array_splice($participants, array_search($winner, $participants), 1);
                                        array_push($participants, $newWinner->toArray());
                                        continue;
                                    } else {
                                        file_put_contents($logFile, "[$lotteryTime] 无法找到新的合格获奖者，跳过此奖项\n", FILE_APPEND);
                                        continue;
                                    }
                                }
                            }

                            file_put_contents($logFile, "[$lotteryTime] 更新获奖者 {$winner['telegramId']} 的状态\n", FILE_APPEND);
                            // 更新中奖状态
                            $participantModel->where($uniqueIdentifier)->update([
                                'status' => 1,
                                'prize' => json_encode([
                                    'name' => $prize['name'],
                                    'content' => $prizeContent
                                ])
                            ]);
                            file_put_contents($logFile, "[$lotteryTime] 成功更新获奖者 {$winner['telegramId']} 的状态，奖品：{$prize['name']}\n", FILE_APPEND);

                            // 记录获奖者信息
                            $winnersList[$prize['name']][] = $winner['telegramId'];
                            file_put_contents($logFile, "[$lotteryTime] {$prize['name']} 的获奖者：{$winner['telegramId']}\n", FILE_APPEND);

                            // 发送中奖私信通知
                            file_put_contents($logFile, "[$lotteryTime] 发送私信给 {$winner['telegramId']}\n", FILE_APPEND);
                            $privateMessage = "🎉 恭喜您！\n\n";
                            $privateMessage .= "您在「{$lottery['title']}」抽奖活动中获得了：\n";
                            $privateMessage .= "🎁 {$prize['name']}\n\n";
                            $privateMessage .= "奖品内容：" . ($prize['contents'][count($winnersList[$prize['name']])-1] ?? $prize['contents'][0]) . "\n\n";
                            $privateMessage .= "请注意查收您的奖品！";

                            $token = TG_CONFIG['tgBotToken'];
                            if (!$token) {
                                throw new \Exception("Telegram bot token not found in environment variables");
                            }

                            $telegram = new \Telegram\Bot\Api($token);

                            try {
                                $telegram->sendMessage([
                                    'chat_id' => $winner['telegramId'],
                                    'text' => $privateMessage,
                                    'parse_mode' => 'HTML',
                                ]);
                                file_put_contents($logFile, "[$lotteryTime] 已发送私信给获奖者 {$winner['telegramId']}\n", FILE_APPEND);
                            } catch (\Exception $e) {
                                file_put_contents($logFile, "[$lotteryTime] 发送私信时出错，用户 {$winner['telegramId']}：" . $e->getMessage() . "\n", FILE_APPEND);
                            }
                        } else {
                            file_put_contents($logFile, "[$lotteryTime] 找不到参与者 {$winner['telegramId']} 的记录\n", FILE_APPEND);
                        }
                    } catch (\Exception $e) {
                        file_put_contents($logFile, "[$lotteryTime] 处理获奖者 {$winner['telegramId']} 时出错，奖品：{$prize['name']}：" . $e->getMessage() . "\n", FILE_APPEND);
                    }
                }
            }

            // 更新未中奖的参与者状态
            try {
                file_put_contents($logFile, "[$lotteryTime] 更新未中奖参与者的状态\n", FILE_APPEND);
                $participantModel
                    ->where('lotteryId', $lottery['id'])
                    ->where('status', 0)
                    ->update(['status' => 2]);
                file_put_contents($logFile, "[$lotteryTime] 已更新抽奖 #{$lottery['id']} 的未中奖参与者状态\n", FILE_APPEND);
            } catch (\Exception $e) {
                file_put_contents($logFile, "[$lotteryTime] 更新抽奖 #{$lottery['id']} 的未中奖参与者状态时出错：" . $e->getMessage() . "\n", FILE_APPEND);
            }

            // 在群组中公布中奖名单
            $groupMessage = "🎉 抽奖结果公布 🎉\n\n";
            $groupMessage .= "「{$lottery['title']}」开奖啦！\n\n";

            foreach ($winnersList as $prizeName => $winners) {
                if (!empty($winners)) {
                    $groupMessage .= "🎁 {$prizeName}：\n";
                    foreach ($winners as $telegramId) {
                        $groupMessage .= "- <a href=\"tg://user?id={$telegramId}\">{$telegramId}</a>\n";
                    }
                    $groupMessage .= "\n";
                }
            }

            $groupMessage .= "恭喜以上中奖的小伙伴！🎊\n";
            $groupMessage .= "奖品详情已私信通知，请注意查收～";

            try {
                file_put_contents($logFile, "[$lotteryTime] 准备发送群组消息\n", FILE_APPEND);
                // 发送群组消息
                $token = TG_CONFIG['tgBotToken'];
                if (!$token) {
                    throw new \Exception("Telegram bot token not found in environment variables");
                }
                $telegram = new \Telegram\Bot\Api($token);
                try {
                    $telegram->sendMessage([
                        'chat_id' => $lottery['chatId'],
                        'text' => $groupMessage,
                        'parse_mode' => 'HTML',
                    ]);
                    file_put_contents($logFile, "[$lotteryTime] 已发送群组消息，抽奖 #{$lottery['id']}\n", FILE_APPEND);
                } catch (\Exception $e) {
                    file_put_contents($logFile, "[$lotteryTime] 发送群组消息时出错，抽奖 #{$lottery['id']}：" . $e->getMessage() . "\n", FILE_APPEND);
                }
            } catch (\Exception $e) {
                file_put_contents($logFile, "[$lotteryTime] 配置获取时出错，抽奖 #{$lottery['id']}：" . $e->getMessage() . "\n", FILE_APPEND);
            }

            // 更新抽奖状态为已开奖
            try {
                file_put_contents($logFile, "[$lotteryTime] 更新抽奖状态为已开奖\n", FILE_APPEND);
                $lotteryModel->where('id', $lottery['id'])->update(['status' => 2]);
                file_put_contents($logFile, "[$lotteryTime] 抽奖 #{$lottery['id']} 状态已更新为已开奖\n", FILE_APPEND);
                // $db->commit();
                file_put_contents($logFile, "[$lotteryTime] 交易提交成功，抽奖 #{$lottery['id']}\n", FILE_APPEND);
            } catch (\Exception $e) {
                // $db->rollBack();
                file_put_contents($logFile, "[$lotteryTime] 更新抽奖 #{$lottery['id']} 状态时出错：" . $e->getMessage() . "\n", FILE_APPEND);
                throw $e; // Re-throw for the outer catch block to log in the lottery_error log
            }

            // 记录开奖日志
            $successTime = date('Y-m-d H:i:s');
            $message = "[$successTime] 成功开奖，抽奖 {$lottery['id']}：{$lottery['title']}\n";
            file_put_contents($logFile, $message, FILE_APPEND);

        } catch (\Exception $e) {
            // $db->rollBack();

            // 记录错误日志
            $errorTime = date('Y-m-d H:i:s');
            $message = "[$errorTime] 开奖抽奖 {$lottery['id']} 时出错：" . $e->getMessage() . "\n";
            file_put_contents($logFile, $message, FILE_APPEND);
            file_put_contents(__DIR__ . '/runtime/log/lottery_error.log', $message, FILE_APPEND);
        }
    }
}

// 发送私信
function sendPrivateMessage($userId, $message) {
    $token = TG_CONFIG['tgBotToken'];
    if (!$token) {
        throw new \Exception("Telegram bot token not found in environment variables");
    }
    try {
        $telegram = new \Telegram\Bot\Api($token);
        $telegram->sendMessage([
            'chat_id' => $userId,
            'text' => $message,
            'parse_mode' => 'HTML',
        ]);
    } catch (\Exception $e) {
        // 如果是 Forbidden: bot was blocked by the user
        if (strpos($e->getMessage(), 'Forbidden: bot was blocked by the user') === false) {
            throw $e;
        } else {
            // 删除用户的TG ID
            Db::name('telegram_user')->where('userId', $userId)->delete();
        }
    }
}

// 发送群组消息
function sendGroupMessage($chatId, $message) {
    $token = TG_CONFIG['tgBotToken'];
    if (!$token) {
        throw new \Exception("Telegram bot token not found in environment variables");
    }
    $telegram = new \Telegram\Bot\Api($token);
    $telegram->sendMessage([
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML',
    ]);
}

// 修改 checkBetResult 函数
function checkBetResult() {
    $betModel = new \app\api\model\BetModel();
    $bets = $betModel->where('status', 1)
        ->whereRaw('endTime <= ?', [date('Y-m-d H:i:s')])
        ->select();

    foreach ($bets as $bet) {
        try {
            $result = 0;

            // 根据随机方式决定结果
            if ($bet['randomType'] == 'dice') {
                // 使用TG骰子
                $token = TG_CONFIG['tgBotToken'];
                if (!$token) {
                    throw new \Exception("Telegram bot token not found");
                }
                $telegram = new \Telegram\Bot\Api($token);

                // 发送骰子并获取消息
                $diceMsg = $telegram->sendDice([
                    'chat_id' => $bet['chatId'],
                    'emoji' => '🎲'
                ]);

                // 获取骰子点数
                $result = $diceMsg['dice']['value'];
            } else {
                // 使用mt_rand
                $result = mt_rand(1, 6);

                // 发送随机结果消息
                $token = TG_CONFIG['tgBotToken'];
                if ($token) {
                    $telegram = new \Telegram\Bot\Api($token);
                    $telegram->sendMessage([
                        'chat_id' => $bet['chatId'],
                        'text' => "🎲 骰子结果：{$result}",
                        'parse_mode' => 'HTML'
                    ]);
                }
            }

            $resultType = $result <= 3 ? '小' : '大';

            Db::startTrans();

            // 原子锁定赌局（仅当仍为进行中），防止并发/重入导致重复结算与重复派奖
            $claimed = $betModel->where('id', $bet['id'])->where('status', 1)->update([
                'status' => 2,
                'result' => $result
            ]);
            if (!$claimed) {
                Db::rollback();
                continue;
            }

            // 处理参与者
            $participants = Db::name('bet_participant')
                ->where('betId', $bet['id'])
                ->select();

            $totalBetAmount = 0;
            $totalWinAmount = 0;
            $winnersList = [];
            $totalWinnersBet = 0;

            // 计算总投注额和赢家总投注额
            foreach ($participants as $participant) {
                $totalBetAmount += $participant['amount'];
                if ($participant['type'] == $resultType) {
                    $totalWinnersBet += $participant['amount'];
                }
            }

            // 计算奖池(总投注额的95%)
            $prizePool = $totalBetAmount * 0.95;

            foreach ($participants as $participant) {
                if ($participant['type'] == $resultType) {
                    // 根据投注比例分配奖金
                    $winAmount = round($totalWinnersBet > 0 ?
                        ($participant['amount'] / $totalWinnersBet) * $prizePool :
                        0, 2);
                    $totalWinAmount += $winAmount;

                    // 原子加币派奖，避免并发读改写丢失更新
                    Db::name('user')->where('id', $participant['userId'])->inc('rCoin', $winAmount)->update();

                    // 更新参与记录
                    Db::name('bet_participant')
                        ->where('id', $participant['id'])
                        ->update([
                            'status' => 1,
                            'winAmount' => $winAmount
                        ]);

                    // 更新用户财务记录
                    Db::name('finance_record')->insert([
                        'userId' => $participant['userId'],
                        'action' => 8,
                        'count' => $winAmount,
                        'recordInfo' => json_encode([
                            'message' => '赌局#'.$bet['id'].'中奖',
                        ]),
                    ]);

                    // 添加到赢家列表
                    $winnersList[] = [
                        'telegramId' => $participant['telegramId'],
                        'amount' => $winAmount
                    ];
                } else {
                    // 更新参与记录
                    Db::name('bet_participant')
                        ->where('id', $participant['id'])
                        ->update(['status' => 2]);
                }
            }

            Db::commit();

            // 等待1秒让用户看清结果
            if ($bet['randomType'] == 'dice') {
                sleep(1);
            }

            // 发送开奖结果消息
            $message = "🎲 开奖结果\n\n";
            $message .= "点数：" . $result . "（" . $resultType . "）\n\n";
            $message .= "本局统计：\n";
            $message .= "总投注：" . number_format($totalBetAmount, 2) . "R\n";
            $message .= "总派奖：" . number_format($totalWinAmount, 2) . "R\n\n";

            if (!empty($winnersList)) {
                $message .= "赢家名单：\n";
                foreach ($winnersList as $winner) {
                    $message .= "- <a href=\"tg://user?id={$winner['telegramId']}\">{$winner['telegramId']}</a> ";
                    $message .= "赢得 " . number_format($winner['amount'], 2) . "R\n";
                }
            } else {
                $message .= "本局没有赢家\n";
            }

            $token = TG_CONFIG['tgBotToken'];
            if ($token) {
                $telegram = new \Telegram\Bot\Api($token);
                $telegram->sendMessage([
                    'chat_id' => $bet['chatId'],
                    'text' => $message,
                    'parse_mode' => 'HTML'
                ]);
            }

        } catch (\Exception $e) {
            Db::rollback();
            // 记录错误日志
            $logFile = __DIR__ . '/runtime/log/bet_error.log';
            $time = date('Y-m-d H:i:s');
            $message = "[$time] Error processing bet {$bet['id']}: " . $e->getMessage() . "\n";
            file_put_contents($logFile, $message, FILE_APPEND);
        }
    }
}

function runCrontab() {
    // 如果在容器中运行，就访问127.0.0.1:8018，否则访问 APP_HOST = https://randallanjie.com
    if (RUN_IN_DOCKER) {
        $host = 'http://127.0.0.1:8018';
    } else {
        $host = APP_HOST;
    }
    // 去掉末尾的斜杠
    $host = rtrim($host, '/');
    $url = $host . '/media/server/crontab?crontabkey=' . CRONTAB_KEY;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpCode != 200) {
        $logFile = __DIR__ . '/runtime/log/crontab_error.log';
        $time = date('Y-m-d H:i:s');
        $message = "[$time] Crontab 响应错误代码: $httpCode\n";
        $message .= "响应内容: $response\n";
        $message .= "----------------------------------------\n";
        file_put_contents($logFile, $message, FILE_APPEND);
    }
    curl_close($ch);
}


/**
 * 清理 runtime/log 目录下过大的日志文件（server.php 内大量 file_put_contents 直接追加写入的日志，
 * 如 lottery_draw.log / timer_error.log / proxy.log 等，均不受 config/log.php 管控）。
 * 单个 .log 超过 10MB 时，仅保留最近约 2MB 的尾部内容，丢弃更早的旧日志，避免磁盘被占满。
 * 注：ThinkPHP 框架自身按天分文件的日志由 config/log.php 的 max_files 控制，不在此处理。
 */
function cleanupLogFiles()
{
    $logDir = __DIR__ . '/runtime/log';
    if (!is_dir($logDir)) {
        return;
    }

    $maxSize  = 10 * 1024 * 1024; // 超过 10MB 触发截断
    $keepSize = 2 * 1024 * 1024;  // 截断后保留尾部约 2MB

    foreach (glob($logDir . '/*.log') as $file) {
        $size = @filesize($file);
        if ($size === false || $size <= $maxSize) {
            continue;
        }

        $fp = @fopen($file, 'r');
        if (!$fp) {
            continue;
        }
        fseek($fp, -$keepSize, SEEK_END);
        $tail = fread($fp, $keepSize);
        fclose($fp);

        // 从第一个换行处开始，避免保留半行
        $pos = strpos($tail, "\n");
        if ($pos !== false) {
            $tail = substr($tail, $pos + 1);
        }

        $header = '[' . date('Y-m-d H:i:s') . " 日志过大已自动截断，仅保留最近内容]\n";
        @file_put_contents($file, $header . $tail, LOCK_EX);
    }
}

function checkConfigDatabase()
{
    // 检查config表，查询全部数据
    $config = Db::name('config')->select();
    $data = [
        'avableRegisterCount' => 0,
        'chargeRate' => 1,
        'sysnotificiations' => '您有一条新消息：{Message}',
        'findPasswordTemplate' => '您的找回密码链接是：<a href="{Url}">{Url}</a>',
        'verifyCodeTemplate' => '您的验证码是：{Code}',
        'clientList' => '[]',
        'clientBlackList' => '[]',
        'maxActiveDeviceCount' => '0',
        'signInMaxAmount' => '0',
        'signInMinAmount' => '0',
        'telegramRules' => '[]',
        'privacyPolicy' => '',
        'userAgreement' => '',
    ];

    foreach ($data as $key => $value) {
        $found = false;
        foreach ($config as $conf) {
            if ($conf['key'] == $key) {
                $found = true;
                break;
            }
        }
        if (!$found && !empty($key) && !empty($value)) {
            // 插入
            Db::name('config')->insert([
                'key' => $key,
                'value' => $value,
                'appName' => 'media',
                'type' => 1,
                'status' => 1
            ]);
        }
    }
}

// 启动所有服务器
Worker::runAll(); 