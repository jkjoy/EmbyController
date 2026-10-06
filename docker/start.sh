#!/bin/sh
set -e

children=
cleanup() {
    status=$?
    trap - 0
    trap '' TERM INT
    # tini -g 为本脚本建立独立进程组；同时停止 master 意外退出后留下的子进程。
    kill -TERM 0 2>/dev/null || true
    for pid in $children; do
        kill -TERM "$pid" 2>/dev/null || true
    done

    # 留出正常清理时间，并在 Docker 默认的 10 秒停止期限前退出。
    remaining=8
    while [ "$remaining" -gt 0 ]; do
        alive=
        for pid in $children; do
            if kill -0 "$pid" 2>/dev/null; then alive=1; fi
        done
        if [ -z "$alive" ]; then break; fi
        sleep 1
        remaining=$((remaining - 1))
    done
    for pid in $children; do
        kill -KILL "$pid" 2>/dev/null || true
        wait "$pid" 2>/dev/null || true
    done
    exit "$status"
}
trap cleanup 0
trap 'exit 0' TERM INT

echo "[$(date)] Starting initialization..."
echo "Setting up permissions..."
export XDG_CONFIG_HOME=/app/runtime/caddy/config
export XDG_DATA_HOME=/app/runtime/caddy/data
mkdir -p /app/runtime/log/ /app/data "$XDG_CONFIG_HOME" "$XDG_DATA_HOME"

# 批量更改文件权限，避免每个文件 fork 一次进程拖慢启动。
find /app -print0 | xargs -0 chown www-data:www-data
chmod -R 755 /app/runtime
chmod 750 /app/data

# 启动前处理已堆积的文件日志。
echo "Starting log rotation..."
/usr/local/bin/emby-rotate-logs

if [ -d "/app/database/migrations" ] && [ "$(ls -A /app/database/migrations)" ]; then
    echo "Running database migrations..."
    su-exec www-data:www-data php think migrate:run
else
    echo "No migrations found, skipping migration step"
fi

# 导入旧环境设置并初始化后台设置，不会覆盖已保存的值。
su-exec www-data:www-data php /app/think settings:import-env
su-exec www-data:www-data caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile

echo "Starting log rotation scheduler..."
crond -f -l 8 -L /dev/stderr -c /etc/emby-controller/crontabs &
cron_pid=$!
children="$children $cron_pid"

echo "Starting PHP-FPM..."
php-fpm -F &
fpm_pid=$!
children="$children $fpm_pid"

echo "Starting Caddy..."
su-exec www-data:www-data caddy run --config /etc/caddy/Caddyfile --adapter caddyfile &
caddy_pid=$!
children="$children $caddy_pid"

echo "Starting Queue in background..."
(
    trap - 0
    queue_pid=
    trap 'if [ -n "$queue_pid" ]; then kill -TERM "$queue_pid" 2>/dev/null || true; wait "$queue_pid" || true; fi; exit 0' TERM INT
    while :; do
        su-exec www-data:www-data php /app/think settings:queue-worker --queue main --tries 3 --sleep 5 &
        queue_pid=$!
        if wait "$queue_pid"; then queue_status=0; else queue_status=$?; fi
        queue_pid=
        echo "Queue worker exited (code $queue_status), restarting in 5 seconds..."
        sleep 5 &
        queue_pid=$!
        wait "$queue_pid"
        queue_pid=
    done
) &
queue_supervisor_pid=$!
children="$children $queue_supervisor_pid"

echo "Starting GatewayWorker..."
su-exec www-data:www-data php /app/server.php start &
workerman_pid=$!
children="$children $workerman_pid"

check_process() {
    if ! kill -0 "$1" 2>/dev/null; then
        if wait "$1"; then status=1; else status=$?; fi
        echo "$2 exited unexpectedly (code $status), stopping container..." >&2
        exit "$status"
    fi
}

while :; do
    check_process "$fpm_pid" PHP-FPM
    check_process "$caddy_pid" Caddy
    check_process "$workerman_pid" GatewayWorker
    check_process "$cron_pid" 'Log rotation scheduler'
    check_process "$queue_supervisor_pid" 'Queue supervisor'
    sleep 1
done
