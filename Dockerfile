# 使用PHP 8.3 FPM Alpine作为基础镜像
FROM php:8.3-fpm-alpine AS builder

# 设置工作目录
WORKDIR /app

# 安装构建依赖
RUN apk add --no-cache \
    # 系统工具
    curl \
    autoconf \
    gcc \
    g++ \
    make \
    # PHP扩展依赖
    libpng-dev \
    libzip-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    sqlite-dev \
    # 额外工具
    zip \
    git

# 配置并安装PHP扩展
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    pdo_sqlite \
    pcntl \
    bcmath \
    gd \
    zip \
    opcache

# 安装连接外部 Redis 的 PHP 客户端扩展，不安装 Redis 服务
RUN pecl install redis \
    && docker-php-ext-enable redis

# 配置opcache
RUN { \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=8'; \
        echo 'opcache.max_accelerated_files=4000'; \
        echo 'opcache.revalidate_freq=60'; \
        echo 'opcache.fast_shutdown=1'; \
        echo 'opcache.enable_cli=1'; \
        # 镜像内代码不可变，关闭时间戳校验，避免每次请求 stat() 文件，提升性能
        echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini

# 安装Composer（固定大版本，保证构建可复现）
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 先只复制依赖清单，单独成层：只要 composer.json/lock 不变，
# 后续源码改动不会让依赖层缓存失效，避免每次构建都重新下载全部依赖。
# composer.lock* 中的 * 让 lock 文件“可选”——存在则用它保证可复现，不存在也不报错。
COPY composer.json composer.lock* /app/

# 安装依赖：此时源码尚未拷入，先跳过脚本与 autoloader 生成
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-autoloader \
    --prefer-dist

# 复制项目源码
COPY . /app

# 生成优化后的 autoloader，并触发 post-autoload-dump
# （think service:discover + vendor:publish，须在源码就位后执行）
RUN composer dump-autoload --optimize --no-dev

# 在实际 PHP 8.3/Alpine 环境验证默认 SQLite，失败时不发布镜像。
# 测试只使用临时数据库，不读取部署数据。
RUN php tests/database_environment.php \
    && php tests/system_settings.php \
    && php tests/system_settings_integration.php

# 调整PHP配置
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && sed -i \
        -e 's/memory_limit = 128M/memory_limit = 256M/g' \
        -e 's/max_execution_time = 30/max_execution_time = 60/g' \
        -e 's/upload_max_filesize = 2M/upload_max_filesize = 20M/g' \
        -e 's/post_max_size = 8M/post_max_size = 20M/g' \
        -e 's/variables_order = "GPCS"/variables_order = "EGPCS"/g' \
        "$PHP_INI_DIR/php.ini"

# 准备运行时目录，并清理不需要打进镜像的内容
# （运行时脚本与配置会在最终阶段单独 COPY 到系统目录，源码副本无需保留）
RUN mkdir -p /app/runtime/log/ \
    && rm -rf /app/docker \
    && chmod -R 755 /app \
    && chown -R www-data:www-data /app

# 最终镜像
FROM php:8.3-fpm-alpine

# 安装运行时依赖
RUN apk add --no-cache \
    fcgi \
    logrotate \
    su-exec \
    sqlite-libs \
    libpng \
    libjpeg \
    freetype \
    libzip \
    tzdata \
    && cp /usr/share/zoneinfo/Asia/Shanghai /etc/localtime \
    && echo "Asia/Shanghai" > /etc/timezone

# 复制必要文件（运行时无需 composer，故不再复制）
COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY --from=builder /usr/local/etc/php/php.ini /usr/local/etc/php/php.ini
COPY --from=builder /app /app

# 复制配置文件
COPY docker/start.sh /start.sh
COPY docker/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/logrotate.conf /etc/emby-controller/logrotate.conf
COPY docker/rotate-logs.sh /usr/local/bin/emby-rotate-logs
COPY docker/logrotate.cron /etc/emby-controller/crontabs/root

# 设置权限和工作目录
WORKDIR /app
RUN chmod +x /start.sh \
    && chmod +x /usr/local/bin/emby-rotate-logs \
    && chmod 644 /etc/emby-controller/logrotate.conf /etc/emby-controller/crontabs/root \
    && mkdir -p /var/lib/logrotate \
    && chown -R www-data:www-data /app \
    && chmod -R 755 /app/runtime

# 最终运行环境以与 FPM 相同的用户检查 SQLite 文件权限、迁移和并发事务。
RUN php-fpm -t \
    && su-exec www-data:www-data php tests/sqlite_connection.php \
    && su-exec www-data:www-data php tests/sqlite_migrations.php \
    && su-exec www-data:www-data php tests/sqlite_business.php

# 暴露端口
EXPOSE 9000 2347

# 使用 FastCGI 检查 PHP-FPM，不依赖外部 Nginx
HEALTHCHECK --interval=30s --timeout=3s \
    CMD SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET \
        cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q '^pong'

# 启动命令
CMD ["/start.sh"]
