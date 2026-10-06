#!/bin/sh
set -e

echo "[$(date)] Starting initialization..."

# 确保目录权限正确
echo "Setting up permissions..."
mkdir -p /app/runtime/log/

# 批量更改文件权限，避免每个文件 fork 一次进程拖慢启动。
find /app -print0 | xargs -0 chown www-data:www-data

# 数据库连接由 Compose 注入进程环境；旧业务配置由 settings:import-env 专门解析。

chmod -R 755 /app/runtime

# 启动前处理已堆积的文件日志，并每分钟检查轮转
echo "Starting log rotation..."
/usr/local/bin/emby-rotate-logs
crond -f -l 8 -L /dev/stderr -c /etc/emby-controller/crontabs &

# 运行数据库迁移
# 检查是否存在迁移文件
if [ -d "/app/database/migrations" ] && [ "$(ls -A /app/database/migrations)" ]; then
    echo "Running database migrations..."
    php think migrate:run
else
    echo "No migrations found, skipping migration step"
fi

# 导入旧环境设置并初始化数据库中的后台设置；不会覆盖已保存的值。
php /app/think settings:import-env

# 启动PHP-FPM
echo "Starting PHP-FPM..."
php-fpm -D

# 判断条件并启动队列
echo "Starting Queue in background..."
(
    queue_pid=
    trap 'if [ -n "$queue_pid" ]; then kill "$queue_pid" 2>/dev/null || true; wait "$queue_pid" || true; fi; exit 0' TERM INT
    while :; do
        php /app/think settings:queue-worker --queue main --tries 3 --sleep 5 &
        queue_pid=$!
        if wait "$queue_pid"; then
            queue_status=0
        else
            queue_status=$?
        fi
        queue_pid=
        echo "Queue worker exited (code $queue_status), restarting in 5 seconds..."
        sleep 5
    done
) &

# 启动GatewayWorker
echo "Starting GatewayWorker..."
php /app/server.php start





