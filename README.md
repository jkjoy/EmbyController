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

### Docker 部署

应用镜像使用 Caddy 2 提供 HTTP 入口，运行 PHP-FPM、队列、WebSocket 和日志维护，不包含 Nginx 或 Redis 服务。Compose 中不启动 Redis；默认使用文件缓存，如需外部 Redis，在管理后台填写容器可访问的连接地址、端口、密码和数据库编号。镜像保留用于连接外部 Redis 的 PHP 客户端扩展。

三套 Compose 的应用服务默认只发布 `8018:8018`，直接访问 `http://服务器IP:8018/user/login` 即可。Caddy 在容器内提供静态资源、转发 PHP 请求和同域 `/ws`，无需导出静态文件或安装外部 Nginx。默认部署使用 **SQLite**，无需安装 MySQL。数据库配置直接写在 Compose 的 `environment` 中，无需创建或挂载 `.env`：

```yaml
services:
  emby-controller:
    environment:
      DB_DRIVER: "sqlite"
      DB_TYPE: "sqlite"
      DB_NAME: "/app/data/emby-controller.sqlite"
      DB_PREFIX: "rc_"
    volumes:
      - ./data:/app/data
```

首次启动会创建数据库、运行迁移并初始化管理员和后台设置。数据文件位于 Compose 所在目录的 `data/emby-controller.sqlite`，容器内路径为 `/app/data/emby-controller.sqlite`。SQLite 的 WAL/SHM 文件也在这个目录中，因此必须持久化 **整个 `data` 目录**，不要只挂载单个数据库文件。重建或更新应用容器会继续使用该目录中的数据；修改表前缀后会使用另一套表，请保持已部署的 `DB_PREFIX`。

SQLite 适合单机部署，多个 PHP-FPM/Workerman 进程共享同一个本地数据库文件。应用使用 WAL 和写锁等待来处理并发访问，事务在写入前取得锁；写事务仍会串行执行。需要多台应用服务器共享数据库或较多并发写入时，使用 MySQL。不要把 SQLite 数据目录放在 NFS/SMB 等网络文件系统中，也不要使用 `:memory:` 作为多进程应用的数据源。

**切换 MySQL：** 连接外部 MySQL 时，将应用服务的 `environment` 替换为以下配置，并填写容器可访问的实际数据库地址及账号密码：

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

也可以使用保留 MySQL 服务的 `docker-compose-all-1.yml` 或 `docker-compose-all-2.yml`，应用的连接信息必须与数据库服务的数据库名、账号和密码一致。Compose 中密码包含 `$` 时写成 `$$`，以传入字面的 `$`。

**已有 MySQL 部署升级：** 保留原有 Compose 和数据库连接即可，不会自动改成 SQLite。仅修改 `DB_DRIVER` 不会搬迁用户、余额、Emby 账号或后台设置；这只是改用另一个数据源。需要转换数据库时，应先备份并另行迁移数据。

**备份 SQLite：** 在原 Compose 目录停止应用，再备份整个数据目录，以保留尚未合并到主文件的 WAL 内容；不要在服务写入期间只复制 `.sqlite` 主文件：

```sh
docker compose -f docker-compose.yml stop emby-controller
sudo install -d -m 700 backups
sudo tar -C data -czf "backups/sqlite-$(date +%Y%m%d-%H%M%S).tar.gz" .
docker compose -f docker-compose.yml start emby-controller
```

恢复时也先停止应用，将备份的完整数据目录恢复到原位置，再启动。`data` 和备份包含账号、凭据和后台设置，应妥善保存，不放到 Nginx 的 `public` 目录下；本地数据目录已被排除在 Git 和镜像构建之外。

`quickstart.sh` 只使用 Docker Compose 部署，优先使用 `docker compose`，兼容已有的 `docker-compose`。脚本仅在缺少配置文件时下载默认 SQLite 的 `docker-compose.yml`，保留已有文件；首次安装可直接启动，需要 MySQL 时先按上面的说明编辑配置。登录后台“系统设置”填写完整的网站地址（如 `http://服务器IP:8018` 或 `https://emby.example.com`），后台定时任务也通过该地址调用网站。

#### 1. 启动应用并直接访问

在 **Compose 文件所在目录** 执行；使用内置 MySQL 时，将所有 `docker-compose.yml` 替换为实际文件名。`quickstart.sh` 的部署目录为执行目录下的 `EmbyController` 文件夹。以下 Docker 命令假定当前用户有 Docker 操作权限，否则需使用 `sudo`。

```sh
docker compose -f docker-compose.yml pull
docker compose -f docker-compose.yml up -d
docker compose -f docker-compose.yml ps
docker compose -f docker-compose.yml logs --tail=100 emby-controller
```

等数据库迁移和应用启动完成后，开放防火墙/云安全组中的 TCP 8018，浏览器访问 `http://服务器IP:8018/user/login`。使用初始管理员 `admin/A123456` 登录并修改密码，在后台“系统设置”填写网站地址、网站标题、Logo、Emby 和所需服务。网站地址填写完整地址，例如 `http://服务器IP:8018`，不带路径前缀，并确保该地址从应用容器内也可访问；通知链接、支付回调和后台定时任务都使用它。

| 入口 | 用途 |
| --- | --- |
| `http://服务器IP:8018/` | 网站首页 |
| `/user/login` | 用户和管理员登录 |
| `/assets/` 等静态资源 | 由容器内 Caddy 直接读取 |
| 同域 `/ws` | 实时通知、未读数、在线人数；Caddy 转发到内部 WebSocket |

网站需要部署在域名根路径，不要添加 `/emby/` 等路径前缀。默认 8018 提供 HTTP；需要 HTTPS 时按下方可选反代步骤配置。

#### 2. 自定义端口与验证

需要使用宿主机 8090 端口时，只修改 Compose 应用服务的端口映射，容器端口仍为 8018：

```yaml
ports:
  - "8090:8018"
```

执行 `docker compose -f docker-compose.yml up -d` 重建应用容器，开放 TCP 8090，并在后台把网站地址改为 `http://服务器IP:8090`。`restart` 不会更新端口映射或数据库环境变量。浏览器的 `/ws` 自动跟随网站端口，无需单独发布 2347。

默认端口可按下面方式检查；使用 8090 时同步替换：

```sh
curl -I http://127.0.0.1:8018/assets/index/css/layui.css
curl -I http://127.0.0.1:8018/user/login
docker compose -f docker-compose.yml ps
docker compose -f docker-compose.yml logs --tail=100 emby-controller
```

静态资源和登录页应正常返回。打开浏览器开发者工具“网络 / WS”，进入带导航的页面后，`/ws` 握手应返回 **101 Switching Protocols**。普通 HTTP 请求访问 `/ws` 不等同于 WebSocket 握手。

#### 3. 更新镜像

在原 Compose 目录运行：

```sh
docker compose -f docker-compose.yml pull
docker compose -f docker-compose.yml up -d
```

静态资源随镜像一起更新，无需导出或复制。保留 SQLite 的 `data` 挂载、原有 MySQL 数据目录和数据库连接信息；不要使用删除数据卷的命令更新。应用运行中的日志和容器标准输出均按下方说明控制容量。

#### 4. 可选：外部 Nginx 提供域名和 HTTPS

下面以 **Nginx 安装在 Docker 宿主机上**、Ubuntu/Debian、域名 `emby.example.com` 为例。将 DNS 的 A/AAAA 记录指向服务器，开放网站的 80/443 端口；AAAA 记录必须对应可访问的 IPv6 地址。应用继续通过 Caddy 提供 HTTP，Nginx 只负责反向代理，无需在宿主机安装 PHP、复制静态文件或配置 FastCGI。

先将应用的端口映射改为只供宿主机使用，并重建容器：

```yaml
ports:
  - "127.0.0.1:8018:8018"
```

安装 Nginx；已安装的服务器直接添加站点：

```sh
sudo apt update
sudo apt install -y nginx
sudo systemctl enable --now nginx
```

将以下内容保存为 `/etc/nginx/conf.d/emby-controller.conf`，或使用仓库中的 [docker/nginx.conf](docker/nginx.conf)。它是由主配置 `http` 块加载的 `server` 片段，Ubuntu/Debian 默认已加载该目录。宝塔等面板可将规则放入对应域名的站点配置，替换原有重复的 PHP 和伪静态规则。

```nginx
server {
    listen 80;
    server_name emby.example.com;
    client_max_body_size 20m;

    access_log /var/log/nginx/emby-controller.access.log;
    error_log /var/log/nginx/emby-controller.error.log;

    location / {
        proxy_pass http://127.0.0.1:8018;
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Host $http_host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Port $server_port;
    }

    location = /ws {
        proxy_pass http://127.0.0.1:8018;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $http_host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Host $http_host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Port $server_port;
        proxy_read_timeout 86400s;
        proxy_send_timeout 86400s;
        proxy_buffering off;
    }
}
```

`proxy_pass` 的地址不带结尾 `/`，原请求路径和查询参数会继续传给 Caddy。普通网页、图片和 WebSocket 都走同一个 8018 上游。存在 AAAA 记录时，在同一 `server` 中添加 `listen [::]:80;` 并确保 IPv6 可达。

```sh
sudo nginx -t
sudo systemctl reload nginx
curl -I http://emby.example.com/user/login
```

#### 5. 配置可信反代

Caddy 默认不信任来访者提交的转发信息，直接 HTTP 部署无需改动。外部 Nginx 终止 HTTPS 时，需要在 Caddy 中 **只信任该 Nginx 的实际来源地址**，应用才能正确识别公开网站的 HTTPS 协议、端口和用户 IP。

先通过域名发起请求，在 `docker compose -f docker-compose.yml logs --tail=100 emby-controller` 的 Caddy 访问日志中查看该请求的 `request.remote_ip`。宿主机 Nginx 经 Docker 端口映射连接时，这通常是 Docker 网关地址，不一定是 `127.0.0.1`。下面的 `172.18.0.1` 仅为示例，必须替换为日志中确认的 Nginx 来源；不要使用任意来源或整个公网作为可信代理。

将当前容器的配置复制到 Compose 目录：

```sh
docker compose -f docker-compose.yml cp emby-controller:/etc/caddy/Caddyfile ./Caddyfile
```

只在文件顶部已有的全局块中添加 `servers`，保留其余 HTTP、PHP 和 WebSocket 规则：

```caddyfile
{
    admin off
    auto_https off
    persist_config off
    storage file_system {
        root /app/runtime/caddy
    }
    servers {
        trusted_proxies static 172.18.0.1
        trusted_proxies_strict
    }
}
```

在现有 Compose 应用服务的 `volumes` 列表中 **追加**以下挂载，保留 SQLite 的 `./data:/app/data` 及其它已有挂载：

```yaml
volumes:
  - ./Caddyfile:/etc/caddy/Caddyfile:ro
```

重新创建容器，并检查已挂载的配置：

```sh
docker compose -f docker-compose.yml up -d
docker compose -f docker-compose.yml exec emby-controller caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
```

Nginx 示例会覆盖客户端提交的 `X-Forwarded-For`、Host、协议和端口；Caddy 只接受上述明确来源的转发信息。以后仅修改已挂载的 Caddyfile 内容时，执行 `docker compose -f docker-compose.yml restart emby-controller` 使其加载新配置。更新镜像时也核对自定义 Caddyfile 与仓库规则是否一致，挂载的文件会继续覆盖镜像内配置。

#### 6. 申请并验证 HTTPS 证书

确认 HTTP 域名站点正常且可信反代已配置后，使用 Certbot 的 Nginx 插件：

```sh
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d emby.example.com --redirect
sudo nginx -t
sudo systemctl reload nginx
sudo certbot renew --dry-run
```

申请证书时域名必须正确解析且公网 80 端口可达。插件会添加 HTTPS 监听、证书和 HTTP 跳转，原来的两个 `location` 及转发头规则需要保留。确认系统已启用 Certbot 提供的自动续期任务，续期后 Nginx 也需要加载新证书。已有证书或由面板管理证书时可跳过 Certbot。

使用已有证书时，把原站点 `listen 80;` 替换为下方内容，填写实际证书路径，保留全部反代规则：

```nginx
listen 443 ssl;
# 有 IPv6 时再添加：listen [::]:443 ssl;
ssl_certificate /etc/letsencrypt/live/emby.example.com/fullchain.pem;
ssl_certificate_key /etc/letsencrypt/live/emby.example.com/privkey.pem;
ssl_protocols TLSv1.2 TLSv1.3;
```

再添加独立的 HTTP 跳转块：

```nginx
server {
    listen 80;
    # 有 IPv6 时再添加：listen [::]:80;
    server_name emby.example.com;
    return 301 https://emby.example.com$request_uri;
}
```

证书文件必须已存在并通过 `nginx -t` 才能重载。使用 HTTP webroot 验证续期时，按证书工具说明在外部 Nginx 中配置 `/.well-known/acme-challenge/` 的验证目录；不要依赖转发到应用的这个路径。Certbot 的 `--nginx` 模式会处理验证规则。

HTTPS 配好后，在后台把网站地址改为 `https://emby.example.com`，不带路径前缀，确认应用容器能访问该地址。浏览器会自动使用 `wss://emby.example.com/ws`；Nginx 到 Caddy 仍使用 `http://127.0.0.1:8018`。若前方还有 CDN 或其它代理，先确保到 Nginx 的连接使用 HTTPS，再按该代理的说明在 Nginx 正确设置真实 IP，并限定可信来源。

外部 Nginx 的日志由宿主机管理，不受应用 Compose 的日志轮转限制。Ubuntu/Debian 软件包通常通过 `/etc/logrotate.d/nginx` 维护 `/var/log/nginx/*.log`；面板或自装 Nginx 需要检查自己的轮转设置。

#### 7. 其它反代位置和旧部署兼容

**另一台服务器上的 Nginx：** 假设 Docker 宿主机私网地址是 `10.0.0.10`，将应用映射改为 `10.0.0.10:8018:8018`，执行 Compose `up -d`。Nginx 两处 `proxy_pass` 改为 `http://10.0.0.10:8018`，在防火墙中只允许 Nginx 服务器访问该端口，并在 Caddy 可信代理中填写 Nginx 的实际私网来源地址。该私网地址必须实际属于 Docker 宿主机；无私网时先通过 VPN 建立私有连接。无需同步静态文件。

**独立 Nginx 容器：** 让它与应用加入同一个私有 Docker 网络，两处上游改为 `http://emby-controller:8018`。可先创建外部网络：

```sh
docker network create emby-proxy
```

将以下配置合并到原应用 Compose，再执行原部署的 Compose `up -d`。示例适用于默认 SQLite 或 `docker-compose-all-2.yml`；`docker-compose-all-1.yml` 的服务网络应保留原来的 `emby-network`，用它替换下方 `default`，并保留原顶层网络定义。已有自定义网络时保留原列表，只追加 `emby-proxy`。

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

外部 Nginx 的独立部署也加入该外部网络，挂载自己的站点配置即可，不需要应用静态资源挂载。Caddy 只信任该 Nginx 容器在共享网络中的实际来源地址；建议为代理分配稳定地址，避免重建后地址变化。应用重建改变上游 IP 后应重载 Nginx，使其重新解析服务名。网络直连不依赖宿主机端口映射，确认不再需要直接访问后可移除应用的 `ports`。应用和内置 MySQL 必须继续共享原来的网络。

**旧外部 Nginx 直接使用 PHP-FPM：** 容器仍保留 9000 FastCGI 和 2347 WebSocket 监听。旧部署可显式保留或追加以下映射，它们不属于三套 Compose 的默认发布端口：

```yaml
ports:
  - "8018:8018"
  - "127.0.0.1:9000:9000"
  - "127.0.0.1:2347:2347"
```

该模式沿用旧 Nginx 的 FastCGI/PATH_INFO、同域 `/ws` 和静态资源目录配置；执行入口仍为容器内的 `/app/public/index.php`，旧静态资源副本须随应用版本自行更新。9000 不是 HTTP 端口。建议按前面的 HTTP 反代方式迁移，迁移完成后移除不再使用的两项映射。

外部 Redis 的 `127.0.0.1` 指向应用容器自身，后台应填写容器实际可达的 Redis 地址。

#### 常见问题

| 现象 | 检查与处理 |
| --- | --- |
| 8018 无法访问 | 检查 Compose `ports`、实际宿主端口、防火墙/云安全组及 `docker compose … ps`；查看日志确认迁移、Caddy、FPM 和 WebSocket 均正常启动。 |
| 登录页 502 | 查看应用日志和数据库连接；确认 Caddy 和 PHP-FPM 正常，外部 Nginx 的上游为 HTTP 8018。 |
| 默认欢迎页或请求到了别的站点 | 检查域名 DNS、端口和 `server_name`，用 `sudo nginx -T` 确认站点已加载且没有重复域名配置。 |
| 样式、图片或动态路由 404 | 默认部署使用镜像自带资源和 Caddyfile；检查是否有旧挂载覆盖 `/app/public` 或自定义 Caddyfile，网站应位于域名根路径。 |
| 页面正常，实时通知无法连接 | 在浏览器 WS 中检查 `/ws`；外部反代需要 HTTP/1.1、Upgrade/Connection 和长连接超时，网站和 `/ws` 使用同一个公开端口。 |
| HTTPS 链接协议、端口或登录 IP 错误 | 核对 Caddy 可信代理实际来源地址及 Nginx 的转发头；后台填写完整公开网站地址，包含自定义端口。 |
| 后台定时任务请求失败 | 网站地址必须从应用容器内可达，检查 DNS、端口、HTTPS 证书和网络路径；`http://127.0.0.1` 不会自动补上 8018。 |
| HTTPS 重载失败 | 检查证书文件、域名和私钥权限，先通过 `nginx -t` 再重载。 |

### 后台配置与旧环境变量迁移

环境变量只保存数据库信息。默认 SQLite 使用 `DB_DRIVER`、`DB_TYPE`、`DB_NAME`、`DB_PREFIX`；MySQL 另外使用 `DB_HOST`、`DB_USER`、`DB_PASS`、`DB_PORT`、`DB_CHARSET`。网站标题、副标题、描述、关键词、Logo、图标、页脚，以及网站地址、Emby、线路、Telegram、邮件、支付、缓存、代理和 AI 等配置统一在后台“系统设置”填写，保存到所选数据库的配置表（默认 `rc_config`）。

Docker 启动脚本会自动运行数据库迁移并初始化后台设置。使用初始管理员 `admin/A123456` 登录并修改密码，再在后台填写网站地址、Emby 连接及所需服务。未填写的可选服务保持关闭。定时任务密钥会自动初始化为随机值；密钥和密码在后台只显示是否已配置，留空保存会保留已有值，需要删除时勾选“清除已保存内容”。定时任务密钥的对应选项为“重置为随机密钥”，重置后仍保持已配置，原有 Webhook 调用方需更新密钥。

升级旧 Docker 部署时，应在切换新的 Compose 配置、移除旧 `.env` 前完成导入。先保留原部署中的 `.env` 挂载，使用支持 `settings:import-env` 的新版镜像启动一次；启动脚本会在补齐默认设置前从旧文件导入数据库中尚未配置的项目。仍使用原部署配置时，也可手动执行：

```sh
docker compose -f docker-compose.yml exec emby-controller php think settings:import-env
```

文件名按原部署替换。确认后台配置完整后，备份旧 `.env`，把九项数据库连接写入新 Compose 的 `environment`，删除原 `env_file` 和 `.env` 挂载，再重建容器。导入只补齐缺少的项目，不覆盖已有后台设置；如果已在没有旧配置的情况下初始化了默认值，请在后台校正相关设置。导入后的服务配置以数据库为准，修改旧环境变量不再覆盖后台设置。

非 Docker 的本地开发或旧部署可在项目目录运行 `php think settings:import-env`，也可用 `php think settings:import-env --file=/实际路径/旧配置.env` 指定旧配置文件。

后台保存后，新请求立即使用最新配置，Workerman 会在约 5 秒内同步。缓存或队列连接切换的生效方式见后台对应设置说明。

邮件目前仅支持直接连接 SMTP；历史“邮件使用 SOCKS5”选项暂不支持，在后台保持关闭。

### 用户修改邮箱与密码

用户登录后进入左侧菜单或右上角菜单的“用户设置”（`/user/userconfig`），可以修改登录名、昵称、邮箱和网站登录密码。修改邮箱或密码时必须输入当前密码；新密码留空表示保留原密码，填写新密码时需再次确认。新密码为 6–40 位，支持字母、数字、点、下划线和短横线。这是本网站的登录密码，Emby 密码在“站点账号”中单独修改。

邮箱必须格式正确且未被其他用户使用。管理员已配置邮件服务时，修改邮箱需点击“发送验证码”，填写新邮箱收到的六位验证码后保存；验证码有效期 5 分钟。未配置邮件服务时，仍可使用当前密码修改邮箱。新邮箱保存后可用于登录；修改密码后需要重新登录，原密码和此前登录的会话失效。

### 货币名称与兑换码

在后台“系统设置”→“站点与个性化”中修改“站内货币名称”，默认是 `R币`，例如可改为“积分”。名称保存在数据库中，页面余额、充值、签到、账单和新通知会使用该名称，无需设置环境变量。修改名称只改变显示，不会换算现有余额、会员价格或充值到账比例；历史账单和通知保留当时的文字。

管理员进入“兑换码管理”→“添加兑换码”，选择单个添加或批量生成；每批可生成 1–100 个随机兑换码，生成后可复制分发。可选类型如下：

| 类型 | 可兑换内容 |
| --- | --- |
| 激活账号 | 固定 1 天会员时长 |
| 会员（按天） | 1–3650 天的整数时长 |
| 会员（按月） | 1–120 个月的整数时长，每月按 30 天计算 |
| 余额 | 0.01–1000000.00 单位货币，最多两位小数 |

用户登录后进入“充值与兑换”（`/finance/user`），填写兑换码即可兑换，无需启用在线支付。兑换余额不要求绑定 Emby 账号；兑换会员时长需先在“影视站账号”创建 Emby 账号，并在后台配置可用的 Emby 服务。未到期会员在原到期时间上累加，已过期会员从兑换时开始计算；终身会员可兑换余额，无需兑换会员时长。

每个兑换码仅能使用一次。管理员可在列表禁用或重新启用未使用的码，已使用的码不能再次启用。会员兑换会保留 Emby 的其他账号权限，并在需要时启用已禁用账号。数据库或 Emby 请求失败时，不会扣掉兑换码；用户可重试或联系管理员。兑换成功会记录账单，后台列表可查看使用者和使用时间。

升级镜像时会自动执行 SQLite/MySQL 数据库迁移以支持两位小数余额，已有兑换码继续可用；若历史数据有重复兑换码，系统会拒绝兑换，由管理员核实处理。

### 每日签到与 Telegram 抽奖

后台设置签到金额范围（0–10，最多两位小数），最大金额为 0 时关闭签到；最小金额可为 0，两端相同则发放固定奖励。网页与机器人共用每日一次的限制，余额和账单一起提交。机器人绑定账号后发送 `/sign`，打开 `/account/sign?signkey=…` 链接，使用登录过的 IP 或同城网络完成验证。链接有效期 5 分钟，成功后失效；关闭签到、禁用或删除账号后，已生成的链接也不能领奖。

管理员在“抽奖管理”设置开奖时间、群组、奖品数量和每份奖品内容。单次最多 100 个奖项、1000 份奖品，每份内容最多 3000 字，编码后的全部奖品数据最多 60000 字节。填写群组会开放报名；留空时，在目标群组发送 `/startlottery` 开始尚未过期的待开始抽奖。群内 `/lottery` 查看活动，`/joinlottery 抽奖ID` 报名，`/exitlottery` 退出；配置关键词后也可发送关键词报名。参与者必须绑定有效账号，截止后不能报名或退出，同一账号不能重复报名或重复中奖。

描述中的 `「LockTime-24h-3」` 表示最近 24 小时至少有 3 条观影记录；`「LockExp-10」` 要求至少 Exp10，管理员不受等级限制。奖品内容包含 `「Exp10」` 时，系统自动增加 10 点经验，普通账号等级封顶 100，管理员身份保持不变。其他奖品内容会作为私信发送给中奖者，群内只公布结果。

常驻进程每 10 秒检查到期抽奖。报名、奖励和开奖结果通过事务与唯一约束防止并发重复发放。通知保存在数据库中，Telegram 发送失败时自动重试，后台参与者页可查看奖品和通知状态。发送重试极少数情况下可能产生重复消息，但不会重复发奖。升级会自动迁移数据库并归档历史重复报名；旧版中断的开奖任务会保留已记录结果并结束，不重复发放可能已到账的经验，管理员需核对日志和历史奖励。

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
- **数据库**：SQLite（默认）/ MySQL
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

   PHP 需启用 PDO SQLite 扩展；使用 MySQL 时启用 PDO MySQL。Docker 镜像自带两种驱动。

3. **配置环境**：
   - 默认无需 `.env`，使用项目目录下的 `data/emby-controller.sqlite`。
   - 自定义数据库时，将 `example.env` 复制成 `.env`，只填写数据库信息。SQLite 的 `DB_NAME` 支持相对项目目录或绝对文件路径；MySQL 填写上文所列连接参数。

4. **初始化数据库**：
   - 执行 `php think migrate:run` 创建数据库目录、建表并初始化管理员。
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
