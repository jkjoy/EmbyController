# EMBY 影视管理系统

## 演示以及最新Beta功能

- [影视管理-算艺轩](https://randallanjie.com/media)
- [TG群组](https://t.me/randall_home)

## 概述

该项目是一个用于管理EMBY的影视管理系统，提供了用户注册、登录、密码找回、最近更新显示、影片评价系统、EMBY账号管理、激活、改密、查看影视站线路、测速、会话查看、工单、充值、签到、邮箱和Telegram机器人通知等功能。

## 安装

[见项目Wiki](https://github.com/RandallAnjie/EmbyController/wiki/InstallDoc)

### 镜像发布

推送到 `main` 或 `master`、发布 Release 或手动触发工作流后，GitHub Actions 使用仓库内置的 `GITHUB_TOKEN` 将镜像发布到 `ghcr.io/<仓库所有者小写>/emby-controller:latest`，无需配置 Docker Hub Secrets。Release 还会发布对应版本标签。本仓库的 Compose 和快速启动脚本使用 `ghcr.io/jkjoy/emby-controller:latest`。

首次发布的 GHCR 包可能默认为私有。需要匿名拉取（包括 `quickstart.sh`）时，在 GitHub 的包设置中将可见性设为 Public；保持私有时，先执行 `docker login ghcr.io`，使用有包读取权限的账号和令牌登录后再拉取镜像。

### Docker 与外部 Nginx

应用镜像运行 PHP-FPM、队列、WebSocket 和日志维护，不包含 Nginx 或 Redis 服务。Compose 中不再启动 Redis；默认使用文件缓存，如需外部 Redis，在管理后台填写容器可访问的连接地址、端口、密码和数据库编号。镜像保留用于连接外部 Redis 的 PHP 客户端扩展。

PHP-FPM 和 WebSocket 分别通过宿主机的 `127.0.0.1:9000`、`127.0.0.1:2347` 提供服务。9000 是 FastCGI 端口，网站 HTTP 端口由宿主机 Nginx 提供。Docker 部署直接修改 Compose 文件中应用服务的 `environment`，无需创建或挂载 `.env`：

```yaml
services:
  emby-controller:
    environment:
      DB_DRIVER: "mysql"
      DB_TYPE: "mysql"
      DB_HOST: "your-mysql-host"
      DB_NAME: "randallanjie"
      DB_USER: "root"
      DB_PASS: "replace-with-your-database-password"
      DB_PORT: "3306"
      DB_CHARSET: "utf8mb4"
      DB_PREFIX: "rc_"
```

以上片段对应连接外部 MySQL 的 `docker-compose.yml`，启动前必须填写容器可访问的实际数据库地址及账号密码。使用 `docker-compose-all-1.yml` 或 `docker-compose-all-2.yml` 内置数据库时，应用的连接信息必须与数据库服务的数据库名、账号和密码一致。Compose 中密码包含 `$` 时写成 `$$`，以传入字面的 `$`。

`quickstart.sh` 只使用 Docker Compose 部署，优先使用 `docker compose`，兼容已有的 `docker-compose`。脚本仅在缺少配置文件时下载 `docker-compose.yml`，保留已有文件，并在启动前提示编辑数据库配置。配置外部 Nginx 后，登录后台“系统设置”填写完整的网站地址（如 `https://emby.example.com`），后台定时任务也通过该地址调用网站。

#### 1. 部署位置与请求路径

以下完整步骤以 **Nginx 直接安装在 Docker 宿主机上**、Ubuntu/Debian、域名 `emby.example.com` 为例。请将示例域名替换为自己的域名，把 DNS 的 A/AAAA 记录指向服务器，并允许网站使用的 80/443 端口通过防火墙。AAAA 记录也必须对应可访问的 IPv6 地址。已有 Nginx 的服务器可直接添加站点，不必重复安装。

| 请求/端口 | 用途 | Nginx 如何处理 |
| --- | --- | --- |
| 网站的 80/443（或自定义 8018） | 浏览器访问 HTTP/HTTPS | 由外部 Nginx 监听 |
| `/assets/`、`/static/` 等文件 | 样式、脚本、图片 | 从宿主机导出的 `public` 目录读取 |
| `/media/user/login` 等动态路由 | ThinkPHP 页面与 API | 转成 `/index.php/…`，用 FastCGI 交给 `127.0.0.1:9000` |
| 同域 `/ws` | 实时通知、未读数、在线人数 | 用 HTTP Upgrade 转发到 `127.0.0.1:2347` |

9000/2347 的 Compose 映射保持 `127.0.0.1` 即可，无需向公网开放。9000 不是 HTTP 服务，不能用 `proxy_pass http://127.0.0.1:9000` 或浏览器直接访问。应用需要部署在域名根路径，网站入口为 `/media`；不要再套一层 `/emby/` 等路径前缀。

#### 2. 安装 Nginx、启动应用并导出静态文件

在服务器安装 Nginx：

```sh
sudo apt update
sudo apt install -y nginx
sudo systemctl enable --now nginx
```

填写 Compose 中的数据库连接后，在 **Compose 文件所在目录** 执行；使用内置数据库时，将下面所有 `docker-compose.yml` 替换为实际文件名。`quickstart.sh` 的部署目录为执行目录下的 `EmbyController` 文件夹。以下 Docker 命令假定当前用户有 Docker 操作权限，否则需使用 `sudo`，包括命令替换中的 `docker compose`。

```sh
docker compose -f docker-compose.yml pull
docker compose -f docker-compose.yml up -d
docker compose -f docker-compose.yml ps
```

等应用完成数据库迁移并正常启动，再导出 **运行中的同一版本容器** 的 `public` 文件：

```sh
sudo install -d -m 755 /srv/emby-controller /srv/emby-controller/public
sudo docker cp "$(docker compose -f docker-compose.yml ps -q emby-controller):/app/public/." /srv/emby-controller/public/
sudo chmod -R a+rX /srv/emby-controller/public
```

`public/.` 会复制目录内容，最终应存在 `/srv/emby-controller/public/index.php` 和 `/srv/emby-controller/public/assets/`，不要多套一层 `public/public`。Nginx 用户需要读取文件、遍历每级父目录；这里导出的都是公开资源，不必让 Nginx 对目录有写权限。

两套路径的用途不同，后面的配置必须分别填写：

| 配置 | 示例路径 | 谁使用 |
| --- | --- | --- |
| `root` | `/srv/emby-controller/public` | 宿主机 Nginx，读取静态文件 |
| `SCRIPT_FILENAME` | `/app/public/index.php` | 容器内 PHP-FPM，执行应用入口 |

PHP 在容器内执行，不需要在宿主机安装 PHP、Composer 或复制 `vendor`。不要把宿主机整个 `public` 目录挂载到容器的 `/app/public`，以免覆盖镜像中的入口和新版资源。现有 `public/uploads` 中的资源也会被导出；以后若新增容器内生成或上传的文件，需同步对应目录或为它单独设计共享存储，仅首次导出不会自动同步新文件。

#### 3. 添加可直接使用的 HTTP 站点配置

将下面内容保存为 `/etc/nginx/conf.d/emby-controller.conf`，或使用仓库中的 [docker/nginx.conf](docker/nginx.conf)。该文件是 `server` 片段，必须由 Nginx 主配置的 `http` 块加载；Ubuntu/Debian 默认已加载 `/etc/nginx/conf.d/*.conf`。使用宝塔等面板时，在该域名的站点配置中使用这些规则，替换原有重复的 PHP 和伪静态规则，并将 `root` 改为实际导出目录。

```nginx
server {
    listen 80;
    server_name emby.example.com;
    root /srv/emby-controller/public;
    index index.php;
    client_max_body_size 20m;

    access_log /var/log/nginx/emby-controller.access.log;
    error_log /var/log/nginx/emby-controller.error.log;

    # 同域 WebSocket 入口，保留 /ws 和查询参数。
    location = /ws {
        proxy_pass http://127.0.0.1:2347;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $http_host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 86400s;
        proxy_send_timeout 86400s;
        proxy_buffering off;
    }

    # 找到静态文件就直接返回，否则交给 ThinkPHP 的前端入口。
    location / {
        try_files $uri $uri/ /index.php$uri$is_args$args;
    }

    location ~ /\. {
        deny all;
    }

    # 只执行 index.php，PATH_INFO 用于 /media/... 等路由。
    location ~ ^/index\.php(?:/|$) {
        fastcgi_split_path_info ^(/index\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /app/public/index.php;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_param HTTPS $https if_not_empty;
        fastcgi_param HTTP_HOST $http_host;
        fastcgi_param HTTP_X_FORWARDED_HOST $http_host;
        fastcgi_param HTTP_X_FORWARDED_PORT $server_port;
        fastcgi_param HTTP_X_FORWARDED_PROTO $scheme;
        fastcgi_param HTTP_X_FORWARDED_FOR $remote_addr;
        fastcgi_param HTTP_X_REAL_IP $remote_addr;
        fastcgi_pass 127.0.0.1:9000;
    }

    location ~ \.php(?:/|$) {
        return 404;
    }
}
```

`include fastcgi_params` 使用 Nginx 自带的参数文件，不能直接套用面板的本地 PHP 配置。某些安装自带的参数文件也定义了 `SCRIPT_FILENAME`，这时应在本站点使用的参数副本中去掉该定义，确保只传入容器路径 `/app/public/index.php`。`fastcgi_split_path_info` 和 `PATH_INFO` 需要保留，否则 `/media/user/login` 等路由可能无法识别。

检查配置并加载：

```sh
sudo nginx -t
sudo systemctl reload nginx
```

浏览器打开 `http://emby.example.com/media/user/login`。如果只做 IP/端口测试，把 `listen 80` 改为 `listen 8018`、`server_name` 改为实际 IP，开放 8018，访问 `http://服务器IP:8018/media/user/login`。后台“网站地址”也要包含该端口，例如 `http://服务器IP:8018`。如果存在 AAAA 记录，还需在同一 `server` 中加 `listen [::]:80;`（自定义端口时同步调整），并确保服务器 IPv6 可达。

#### 4. 配置 HTTPS

先确认上面的 HTTP 站点可以通过实际域名访问，再申请证书。Ubuntu/Debian 可使用 Certbot 的 Nginx 插件：

```sh
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d emby.example.com --redirect
sudo nginx -t
sudo systemctl reload nginx
sudo certbot renew --dry-run
```

申请证书时域名必须正确解析且公网 80 端口可达；已有证书或由面板管理证书时可跳过 Certbot。插件会为对应域名添加 HTTPS 监听和证书，并配置 HTTP 跳转，原来的 PHP 和 `/ws` 规则仍需保留。确认系统已启用 Certbot 提供的自动续期任务，续期后 Nginx 也需要加载新证书。

已有证书时，将原站点的 `listen 80;` 替换为下面内容，证书路径填写实际已有文件，保留 `root`、所有 `location` 和日志配置：

```nginx
listen 443 ssl;
# 有 IPv6 时再添加：listen [::]:443 ssl;
ssl_certificate /etc/letsencrypt/live/emby.example.com/fullchain.pem;
ssl_certificate_key /etc/letsencrypt/live/emby.example.com/privkey.pem;
ssl_protocols TLSv1.2 TLSv1.3;
```

再添加一个独立 HTTP 跳转块；仅在证书已经准备好时测试并重载，不存在的证书路径会导致 `nginx -t` 失败：

```nginx
server {
    listen 80;
    # 有 IPv6 时再添加：listen [::]:80;
    server_name emby.example.com;
    return 301 https://emby.example.com$request_uri;
}
```

如果证书续期采用 HTTP webroot 验证，需按证书工具说明为 `/.well-known/acme-challenge/` 添加专用放行规则和实际验证目录；当前配置的隐藏文件规则会阻止这个路径。Certbot 的 `--nginx` 模式会处理验证规则。

HTTPS 配好后，在后台“系统设置”把网站地址设为 `https://emby.example.com`，不带 `/media`。浏览器会自动使用 `wss://emby.example.com/ws`；Nginx 负责 TLS，2347 上游仍使用 `http://127.0.0.1:2347`。配置中的 `HTTPS` 和 `HTTP_X_FORWARDED_PROTO` 参数让 PHP 识别网站协议。若前方还有 CDN 或其它代理，建议它们到本站 Nginx 也使用 HTTPS，保证这一层的 `$scheme` 与公开网站协议一致。

#### 5. 验证访问与查看日志

```sh
# 没有启用 HTTPS 时，将下面的 https 改为 http。
curl -I https://emby.example.com/assets/index/css/layui.css
curl -I https://emby.example.com/media/user/login
docker compose -f docker-compose.yml ps
docker compose -f docker-compose.yml logs --tail=100 emby-controller
sudo tail -n 100 /var/log/nginx/emby-controller.error.log
```

静态文件和登录页应正常返回（登录页通常为 200；已登录状态可能跳转）。使用初始管理员 `admin/A123456` 登录并修改密码，在后台配置网站地址、网站标题、Logo、Emby 及所需服务。打开浏览器开发者工具的“网络 / WS”，进入带导航的页面后，`/ws` 握手应返回 **101 Switching Protocols**。直接用普通 HTTP 请求打开 `/ws` 不等同于 WebSocket 握手。

外部 Nginx 的日志由宿主机管理，不受应用 Compose 的日志轮转限制。Ubuntu/Debian 软件包通常通过 `/etc/logrotate.d/nginx` 维护 `/var/log/nginx/*.log`；面板或自装 Nginx 需要检查自己的日志轮转设置。

#### 6. 更新镜像与同步静态文件

每次更新镜像后，在原 Compose 目录运行以下命令，确保宿主机资源与容器应用版本一致：

```sh
docker compose -f docker-compose.yml pull
docker compose -f docker-compose.yml up -d
sudo docker cp "$(docker compose -f docker-compose.yml ps -q emby-controller):/app/public/." /srv/emby-controller/public/
sudo chmod -R a+rX /srv/emby-controller/public
```

复制会覆盖同名文件，不会清除宿主机多余文件；若曾自行添加上传资源，请先备份并单独维护该目录。更新静态文件不要求重载 Nginx，改动 Nginx 配置后才需要 `nginx -t` 和重载。改动 Compose 的数据库环境变量或端口映射后，执行 `docker compose -f docker-compose.yml up -d` 让 Compose 重建应用容器，单纯 `restart` 不会更新这些配置。

#### 7. Nginx 在另一台机器或独立容器时

**另一台服务器上的 Nginx：** 当前 `127.0.0.1` 映射只能供 Docker 宿主机使用。假设 Docker 宿主机的私网地址是 `10.0.0.10`，将应用服务的端口映射改为：

```yaml
ports:
  - "10.0.0.10:9000:9000"
  - "10.0.0.10:2347:2347"
```

该地址必须实际属于 Docker 宿主机。在防火墙中只允许 Nginx 服务器访问这两个私网端口，应用更新后执行 Compose `up -d`。将 Nginx 配置改为 `fastcgi_pass 10.0.0.10:9000;` 和 `proxy_pass http://10.0.0.10:2347;`，并把导出的 `public` 文件同步到 **Nginx 所在服务器** 的 `root` 目录。`SCRIPT_FILENAME` 仍为 `/app/public/index.php`。没有私网时应先通过 VPN 等建立私有连接。

**独立的 Nginx 容器：** Nginx 容器中的 `127.0.0.1` 指向自身，应让它与应用加入同一个私有 Docker 网络，再使用应用服务名。例如先创建共享网络：

```sh
docker network create emby-proxy
```

在现有应用 Compose 中合并以下网络配置，并执行原部署的 Compose `up -d`。下面示例适用于 `docker-compose.yml` 和 `docker-compose-all-2.yml`；使用 `docker-compose-all-1.yml` 时，将应用网络列表中的 `default` 换成它原有的 `emby-network`，并保留原来的顶层 `emby-network` 定义。已有自定义网络的部署也应保留原网络列表，只追加 `emby-proxy`：

```yaml
services:
  emby-controller:
    networks:
      - default
      - emby-proxy
networks:
  emby-proxy:
    external: true
    name: emby-proxy
```

应用与内置数据库必须继续共享原来的网络。由外部 Nginx 的独立部署同样加入这个 `emby-proxy` 外部网络，设置 `fastcgi_pass emby-controller:9000;` 和 `proxy_pass http://emby-controller:2347;`。服务名通过 Docker DNS 解析，应用重建导致容器 IP 改变后应重载 Nginx，让它重新解析上游地址。

将宿主机导出的 `/srv/emby-controller/public` 只读挂载到 Nginx 容器，例如 `/srv/emby-controller/public:/srv/emby-controller/public:ro`，`root` 填写 **Nginx 容器内** 的挂载路径。站点配置也由外部 Nginx 的部署挂载，日志路径必须在该容器内存在。网络直连使用容器端口，不依赖宿主机端口映射；确认宿主机不再需要访问后可移除应用的 `ports`。本项目不把 Nginx 加回应用镜像或默认 Compose。

无论哪种方式，外部 Redis 的 `127.0.0.1` 都指向应用容器自身，后台应填写应用容器实际可达的 Redis 地址。

#### 常见问题

| 现象 | 检查与处理 |
| --- | --- |
| 默认欢迎页、请求到了别的站点 | 检查域名 DNS、请求端口和 `server_name`；用 `sudo nginx -T` 确认本站点已加载，检查是否存在重复域名配置。 |
| 动态页 502 / `Connection refused` | 用 `docker compose … ps` 和日志确认应用启动成功、数据库可连接，检查 9000 映射；Nginx 在另一台机器/容器时不能使用自身的 `127.0.0.1`。 |
| `Primary script unknown` / `File not found` | `SCRIPT_FILENAME` 必须是容器内的 `/app/public/index.php`，不能是 `/srv/…`；检查参数文件或面板规则是否重复覆盖了它。 |
| 静态文件正常，`/media/…` 页面 404 | 确认 `try_files` 转发到 `/index.php` 并保留原路径，PHP 规则有 `fastcgi_split_path_info` 和 `PATH_INFO`。 |
| 样式、图片 404/403 | 检查 `root`、文件是否实际导出、是否多套 `public`，以及 Nginx 对文件和所有父目录的权限；更新镜像后重新导出。启用 SELinux 的系统还需按发行版要求允许 Nginx 读取站点目录和连接上游。 |
| 页面正常，实时通知无法连接 | 在浏览器 WS 中检查 `/ws`；确认 `Upgrade`/`Connection`、HTTP/1.1 和 2347 地址正确，应用日志中 WebSocket 已启动，上游代理/CDN 也支持 WebSocket。 |
| HTTPS 跳转或生成链接协议错误 | 保留 FastCGI 的 `HTTPS`/协议参数，在后台填写实际 HTTPS 网站地址；前方代理到本站 Nginx 也使用 HTTPS。 |
| HTTPS 重载失败 | 检查证书文件是否存在、域名证书是否正确、私钥是否可由 Nginx 主进程读取，先通过 `nginx -t` 再重载。 |
| 自定义端口访问失败 | 同步修改 Nginx `listen`、防火墙/云安全组和后台网站地址；浏览器 `/ws` 会跟随网站端口，无需修改前端代码。 |

### 后台配置与旧环境变量迁移

环境变量只保留 `DB_DRIVER`、`DB_TYPE`、`DB_HOST`、`DB_NAME`、`DB_USER`、`DB_PASS`、`DB_PORT`、`DB_CHARSET`、`DB_PREFIX`。网站标题、副标题、描述、关键词、Logo、图标、页脚，以及网站地址、Emby、线路、Telegram、邮件、支付、缓存、代理和 AI 等配置统一在后台“系统设置”填写，保存到现有数据库配置表（默认 `rc_config`）。

Docker 启动脚本会自动运行数据库迁移并初始化后台设置。使用初始管理员 `admin/A123456` 登录并修改密码，再在后台填写网站地址、Emby 连接及所需服务。未填写的可选服务保持关闭。定时任务密钥会自动初始化为随机值；密钥和密码在后台只显示是否已配置，留空保存会保留已有值，需要删除时勾选“清除已保存内容”。定时任务密钥的对应选项为“重置为随机密钥”，重置后仍保持已配置，原有 Webhook 调用方需更新密钥。

升级旧 Docker 部署时，应在切换新的 Compose 配置、移除旧 `.env` 前完成导入。先保留原部署中的 `.env` 挂载，使用支持 `settings:import-env` 的新版镜像启动一次；启动脚本会在补齐默认设置前从旧文件导入数据库中尚未配置的项目。仍使用原部署配置时，也可手动执行：

```sh
docker compose -f docker-compose.yml exec emby-controller php think settings:import-env
```

文件名按原部署替换。确认后台配置完整后，备份旧 `.env`，把九项数据库连接写入新 Compose 的 `environment`，删除原 `env_file` 和 `.env` 挂载，再重建容器。导入只补齐缺少的项目，不覆盖已有后台设置；如果已在没有旧配置的情况下初始化了默认值，请在后台校正相关设置。导入后的服务配置以数据库为准，修改旧环境变量不再覆盖后台设置。

非 Docker 的本地开发或旧部署可在项目目录运行 `php think settings:import-env`，也可用 `php think settings:import-env --file=/实际路径/旧配置.env` 指定旧配置文件。

后台保存后，新请求立即使用最新配置，Workerman 会在约 5 秒内同步。缓存或队列连接切换的生效方式见后台对应设置说明。

邮件目前仅支持直接连接 SMTP；历史“邮件使用 SOCKS5”选项暂不支持，在后台保持关闭。

### 日志容量限制

项目提供的 Docker Compose 文件已为容器的标准输出/错误日志启用轮转，`quickstart.sh` 使用同一配置：使用 `json-file`，单个日志文件最大 `10m`，最多保留 `3` 个文件，每个容器约占用 30 MB。超过限额后会自动清理最旧的日志。

容器内的 ThinkPHP（含子应用和月份目录）、定时任务、WebSocket 和 Workerman 文件日志在启动时及每分钟检查一次：每天或超过 10 MiB 时轮转，每个文件最多保留 3 份压缩归档，停止写入满 7 天的应用日志及归档自动清理。10 MiB 是检查阈值，检查间隔内文件仍可能暂时超过该大小；文件日志使用 `copytruncate`，复制与截断之间可能丢失少量并发记录。构建镜像时排除运行日志。

文件日志维护需要先构建本次修改后的镜像，或在新版镜像发布后拉取更新（`docker compose -f docker-compose.yml pull`），再重建容器。仅更新 Compose 文件可立即启用 Docker 日志限额，但无法改变旧镜像内部的文件日志行为。

已有容器必须重新创建才能应用新的日志配置，单纯重启容器不会生效。使用 Compose 部署时，在原部署目录执行以下命令，并将文件名替换为实际使用的 `docker-compose.yml`、`docker-compose-all-1.yml` 或 `docker-compose-all-2.yml`：

```sh
docker compose -f docker-compose.yml up -d --force-recreate
```

历史独立 Docker 容器需先记录原有端口、环境配置和数据挂载，再使用原参数重建并增加 `--log-driver json-file --log-opt max-size=10m --log-opt max-file=3`；也可先完成旧配置导入，再改用 Compose 管理。

重新创建时保留现有数据库连接和数据挂载，不要删除数据库目录或数据卷。原 Compose 创建的 Redis 容器及数据不会自动删除；确认外部 Redis 已迁移或使用文件缓存后，可自行停止旧 Redis 容器。

## 功能

- **用户管理**：注册、登录、密码找回
- **影视管理**：显示最近更新、影片评价系统
- **EMBY账号管理**：账号创建、激活、改密
- **站点管理**：查看影视站线路、测速
- **会话管理**：查看活跃会话
- **工单系统**：提交和管理支持工单
- **充值**：用户账号充值
- **签到系统**：每日签到获取奖励
- **通知**：通过邮箱和Telegram机器人接收通知

## 预览

### 首页

![](image/index1.png)
![](image/index2.png)
![](image/index3.png)

### 控制台

![](image/dashboard.png)

### 用户中心

![](image/user-config.png)

### 站点账号

激活账号：
![](image/account-active.png)
未激活账号：
![](image/account-inactive.png)

### 工单系统

![](image/request-list.png)

![](image/request-detail.png)

### 充值中心

![](image/finace-pay.png)

### 影评系统

![](image/comment-detail.png)

## 使用

- **用户注册**：用户可以通过提供邮箱、用户名和密码进行注册。
- **登录**：注册用户可以使用凭证登录。
- **密码找回**：用户可以通过邮箱验证找回密码。
- **最近更新**：显示最新的影视更新。
- **影片评价**：用户可以对影片进行评分和评论。
- **EMBY账号管理**：创建、激活和修改EMBY账号密码。
- **站点线路**：查看和测试影视站线路。
- **会话管理**：查看活跃用户会话。
- **工单系统**：提交和管理支持工单。
- **充值**：用户账号充值。
- **签到系统**：每日签到获取奖励。
- **通知**：通过邮箱和Telegram机器人接收通知。

## 使用技术

- **后端**：PHP8
- **前端**：Html JavaScript Css Tailwindcss
- **数据库**：MySQL
- **框架**：ThinkPHP Layui
- **其他工具**：Composer、cURL、Cloudflare Turnstile、Telegram Bot API


## 开发

以下步骤用于非 Docker 的本地开发；Docker 的数据库配置直接写入 Compose `environment`。

1. **克隆仓库**：
    ```sh
    git clone https://github.com/RandallAnjie/EmbyController.git
    cd EmbyController
    ```

2. **安装依赖**：
    ```sh
    composer install
    ```

3. **配置环境**：
   - 将 `example.env` 复制成 `.env` 。
   - 只更新 `.env` 中的数据库连接信息。

4. **初始化数据库**：
   - 执行 `php think migrate:run` 建表并初始化管理员。
   - 初始用户名/密码：admin/A123456，首次登录后修改密码。

5. **启动开发服务器**：
    ```sh
    php think run
    ```

6. **配置网站和服务**：
   - 登录后台“系统设置”，填写网站地址、个性化信息、Emby 和其它服务连接。
   - 升级旧部署时按上文导入旧环境变量配置。

## 贡献

1. Fork 仓库。
2. 创建新分支（`git checkout -b feature-branch`）。
3. 进行修改。
4. 提交修改（`git commit -m 'Add some feature'`）。
5. 推送到分支（`git push origin feature-branch`）。
6. 打开Pull Request。

## 许可证

该项目使用Apache许可证。详情请参阅[LICENSE](LICENSE)文件。

## 联系

如有任何问题或建议，请联系[randall@randallanjie.com](mailto:randall@randallanjie.com)。
