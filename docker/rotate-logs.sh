#!/bin/sh
set -e

# 清理停止写入的日期日志及归档，避免旧月份的文件永久堆积。
for log_root in /app/runtime /app/app/runtime; do
    if [ -d "$log_root" ]; then
        find "$log_root" -type f -path '*/log/*' \
            \( -name '*.log' -o -name '*.log.[0-9]' -o -name '*.log.[0-9].gz' \) \
            -mtime +6 -delete
    fi
done

logrotate --state /var/lib/logrotate/emby-controller.status /etc/emby-controller/logrotate.conf
