# 使用PHP 8.2 Alpine基础镜像
FROM php:8.2-fpm-alpine

# 设置工作目录
WORKDIR /var/www/html

# 安装系统依赖和构建依赖
RUN apk add --no-cache \
    curl \
    libpng-dev \
    oniguruma-dev \
    libxml2-dev \
    unzip \
    libzip-dev \
    nginx 

# 安装PHP扩展
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    zip \
    exif \
    pcntl \
    bcmath \
    gd

RUN apk --no-cache add pcre-dev ${PHPIZE_DEPS} \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del pcre-dev ${PHPIZE_DEPS} \
    && rm -rf /tmp/pear
  
# 配置PHP
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && sed -i 's/memory_limit = 128M/memory_limit = 256M/g' "$PHP_INI_DIR/php.ini"

# 安装Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 复制项目文件
COPY . /var/www/html/

# 创建运行时目录并设置权限
RUN mkdir -p /var/www/html/runtime \
    && chmod -R 755 /var/www/html 

# 安装项目依赖
RUN composer install --no-dev --optimize-autoloader

# 删除默认的nginx配置
RUN rm -f /etc/nginx/http.d/default.conf

# 复制Nginx配置文件
COPY docker/nginx.conf /etc/nginx/http.d/default.conf

 
# 复制启动脚本
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

# 复制PHP-FPM配置
COPY docker/www.conf /usr/local/etc/php-fpm.d/www.conf

# 暴露端口
EXPOSE 8018 2347 2348

# 启动命令
CMD ["/start.sh"]
