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

启动应用后，将镜像中的静态文件导出到宿主机；以下示例在原 Compose 部署目录执行，文件名按实际部署替换：

```sh
docker compose -f docker-compose.yml up -d
sudo mkdir -p /srv/emby-controller/public
sudo docker cp "$(docker compose -f docker-compose.yml ps -q emby-controller):/app/public/." /srv/emby-controller/public/
```

导出目录需允许 Nginx 用户读取；每次更新镜像后同步静态文件。`quickstart.sh` 下载的部署配置位于执行目录下的 `EmbyController` 文件夹，上述命令应在该文件夹执行。

将 [docker/nginx.conf](docker/nginx.conf) 作为宿主机 Nginx 站点配置，按需修改域名、监听端口、TLS 和 `root` 目录，再执行 `nginx -t` 并重载。示例监听 8018，通过 FastCGI 将 PHP 请求交给容器，并将同域 `/ws` 转发至 2347；`SCRIPT_FILENAME` 必须保留容器内的 `/app/public` 路径。Nginx 的访问日志和错误日志由宿主机管理。

如果 Nginx 位于另一台机器或独立容器，请通过可达的地址或私有 Docker 网络连接应用的 9000、2347 端口，并调整代理地址。外部 Redis 的 `127.0.0.1` 指向应用容器自身，应填写实际可达的服务器地址。

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
