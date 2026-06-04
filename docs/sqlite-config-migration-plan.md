# EmbyController:切换 SQLite + 配置项入库 改造方案

> 状态:方案稿(未动代码)。基于 ThinkPHP 8.0 / think-orm 3.0 / Workerman 4.1 实测代码现状编写。

---

## 0. 结论先行

两件事都可行,且**地基已有**:项目里已存在 `rc_config` 表 + `SysConfigModel` + 后台 `Admin::setting()` 设置页 + `server.php::checkConfigDatabase()` 默认值初始化。配置入库本质是把 `.env` 里的对接类配置接入这套现成机制。

两个利好让 SQLite 切换风险大幅降低:
- `server.php`(Workerman 常驻进程)也走 think-orm 的 `Db`,不是原生 mysqli/PDO(全项目零匹配 `mysqli`/`new PDO`)。
- 几乎无 MySQL 专有 SQL(无 `FIND_IN_SET`/`DATE_FORMAT`/`ON DUPLICATE`),raw SQL 仅 `Db::query("SELECT 1")`、一处标准 `whereRaw`、migration 内带反引号 INSERT —— 全部 SQLite 兼容。

最大现实风险是 **SQLite 的整库写锁**:本系统有 Workerman 常驻 + 定时任务 + Web 三方并发写库(会话上报、签到、计费),SQLite 高并发写易 `database is locked`。详见 §2.5。

---

## 1. 配置加载的两条路径(现状)

改造必须同时覆盖这两条独立路径:

**路径 A — ThinkPHP Web/API 应用**
`.env` → `config/*.php`(用 `env()`)→ 业务代码 `config()` / `Config::get()`。
- 业务代码里直接 `env()` 仅 4 处(`Media.php`×2、`MoviePilot.php`×2),其余全走 `config()`/`Config::get()`。
- 这意味着:**只要在请求初始化阶段把数据库里的值 `Config::set()` 进去,业务代码几乎零改动**。

**路径 B — Workerman 常驻进程(`server.php`)**
`server.php` 自己解析 `.env` 并 `define()` 出常量:`RUN_IN_DOCKER`、`APP_HOST`、`CRONTAB_KEY`、`DB_CONFIG`、`MEDIA_CONFIG`、`TG_CONFIG`(`server.php:72-109`)。
- 这些常量在 `server.php` 自身、`app/websocket/Events.php:16`(`DB_CONFIG`)、`app/media/controller/Admin.php:1109`(`MEDIA_CONFIG`)使用。
- 常量一旦 `define` 不可变,Workerman 进程常驻 → 配置入库后「改了不生效」,需 reload。详见 §3.4。

---

## 2. SQLite 切换方案

### 2.1 兼容性结论

| 项 | 结论 |
|----|------|
| 数据库访问层 | 统一走 think-orm `Db`,SQLite 由框架接管 ✅ |
| MySQL 专有函数 | 未发现 ✅ |
| raw SQL | `server.php:178/407` `SELECT 1`、`server.php:1083` `whereRaw('endTime <= ?')`、migration 反引号 INSERT —— 均 SQLite 兼容 ✅ |
| `ON UPDATE CURRENT_TIMESTAMP` | ❌ SQLite 不支持。8 个 migration 约 19 处需处理 |
| `engine => InnoDB` | ⚠️ SQLite 无 engine 概念。2 处需处理 |
| `default => CURRENT_TIMESTAMP` | ✅ SQLite 支持,保留 |
| 并发写 | ⚠️ 整库锁,见 §2.5 |

### 2.2 配置改动

**`config/database.php`** — 新增 sqlite 连接,并让 default 可切换:
```php
'default' => env('DB_DRIVER', 'mysql'),   // 切 sqlite 时 .env 写 DB_DRIVER=sqlite
'connections' => [
    'mysql' => [ /* 保持不变 */ ],
    'sqlite' => [
        'type'        => 'sqlite',
        // 数据库文件路径,建议放 runtime 外、可备份处
        'database'    => env('DB_SQLITE_PATH', app()->getRootPath() . 'database/database.sqlite'),
        'prefix'      => env('DB_PREFIX', 'rc_'),
        'fields_strict' => true,
        'trigger_sql'   => env('APP_DEBUG', true),
        'fields_cache'  => true,
        // sqlite 不需要 hostname/username/password/charset
    ],
],
```

**`config/migration.php`** — Phinx 增加 sqlite 环境(当前写死 mysql):
```php
'environments' => [
    'default_migration_table' => env('DB_PREFIX', 'rc_') . 'migrations',
    'default_database' => env('DB_DRIVER', 'mysql'),
    'mysql'  => [ /* 保持不变 */ ],
    'sqlite' => [
        'adapter' => 'sqlite',
        'name'    => env('DB_SQLITE_PATH', 'database/database'), // Phinx 会自动加 .sqlite3,注意路径写法
        'table_prefix' => env('DB_PREFIX', 'rc_'),
    ],
],
```
> 注意:Phinx 的 sqlite `name` 路径与 think-orm 的 `database` 路径需指向同一文件,二者对 `.sqlite/.sqlite3` 后缀处理不同,落地时要核对实际生成的文件名。

**`.env` 新增项:**
```
DB_DRIVER = sqlite
DB_SQLITE_PATH = /绝对路径/database/database.sqlite
```

**PHP 扩展:** 确保启用 `pdo_sqlite`(Dockerfile 里 `docker-php-ext-install pdo_sqlite`)。

### 2.3 migration 兼容性改造

think-orm 模型层已开启 `auto_timestamp`(`config/database.php:13`)且各模型设了 `autoWriteTimestamp`(如 `SysConfigModel.php:25`),时间戳实际由 PHP 框架写入,**数据库级 `ON UPDATE` 是冗余的,删除不影响功能**。

- 删除全部 `'update' => 'CURRENT_TIMESTAMP'` 选项(保留 `'default' => 'CURRENT_TIMESTAMP'`)。涉及文件:
  `create_auth_tables`、`create_emby_tables`、`create_media_tables`、`create_user_tables`、`create_finance_tables`、`create_system_tables`、`create_memo_tables`、`create_communication_tables`。
- `create_media_seek_tables` 的 `'engine' => 'InnoDB'`(2 处):SQLite 下删除或条件化。

> 改完后一套 migration 对 MySQL / SQLite 通用。
> ⚠️ 改 migration 文件**不会影响已建好的 MySQL 库**(migration 只对未执行的迁移生效)。所以这步只对「全新建 SQLite 库」有效;已有数据的迁移见 §2.4。

### 2.4 数据迁移(MySQL → SQLite)

新部署可跳过。已有 MySQL 数据要搬到 SQLite:
1. 用改造后的 migration 在 SQLite 全新建表结构:`php think migrate:run`(default 指向 sqlite)。
2. 数据搬运二选一:
   - 写一个一次性命令(`php think` 自定义命令),逐表 `Db::connect('mysql')->name($t)->cursor()` 读 → `Db::connect('sqlite')->name($t)->insertAll()` 写;
   - 或用外部工具(如 `mysql2sqlite`、Navicat 数据传输),但要注意类型与时间格式。
3. 校验:逐表行数比对 + 关键表(user/finance)抽样比对。

### 2.5 并发写锁(必须正视)

SQLite 是整库文件锁,本项目并发写来自:Workerman 进程(`$ws->count = 4` 等多进程)+ think-queue + 定时任务 + Web。缓解措施:
- **开启 WAL**:连接后执行 `PRAGMA journal_mode=WAL;`(读写并发显著改善,但多写者仍串行)。可在初始化处或 server.php 连库后执行。
- 设置 `PRAGMA busy_timeout=5000;` 减少瞬时锁报错。
- 评估写频率:若 Emby 会话上报/计费写入频繁,SQLite 可能成为瓶颈,届时仍建议 MySQL。
- **建议**:把数据库类型做成可切换(本方案已支持),SQLite 用于小规模/单机/自用,规模上来切回 MySQL。

---

## 3. env 配置入库方案

### 3.1 配置分类

**必须留在 `.env`(引导期 / 鸡生蛋 / 安全):**
- `DB_DRIVER` / `DB_*` / `DB_SQLITE_PATH` —— 要读库配置必须先连库
- `CACHE_TYPE` / `REDIS_*` —— 缓存在配置注入前就要用(本方案用它缓存配置)
- `APP_DEBUG`、`IS_DOCKER`、`CRONTAB_KEY` —— 启动期 / 安全密钥

**可入库(接入 rc_config):**
- Emby:`EMBY_URLBASE`/`EMBY_APIKEY`/`EMBY_ADMINUSERID`/`EMBY_TEMPLATEUSERID`/线路列表
- 支付:`PAY_URL`/`PAY_MCHID`/`PAY_KEY`/`AVAILABLE_PAYMENT_*`
- Telegram:`TG_BOT_TOKEN`/`TG_BOT_USERNAME`/`TG_BOT_ADMIN_ID`/`TG_BOT_GROUP_*`/`TG_BOT_WEBHOOK_SECRET`
- 邮件:`MAIL_*`
- AI:`AI_*`/`GEMINI_API_KEY`/`XFYUNLIST_*`
- 地图:`TENCENT_MAP_KEY`/`TENCENT_MAP_SK`
- 验证码:`CLOUDFLARE_TURNSTILE_*`
- 代理:`SOCKS5_*`
- 其它:`APP_HOST`、`DEFAULT_LANG`

### 3.2 复用现有 rc_config 机制

`rc_config` 字段:`appName / key / value / type / status`。
- `appName`:按配置域归类(`media`/`payment`/`telegram`/`mail`/`ai`/`map`/`captcha`/`proxy`/`app`)。
- `type`:**0=仅管理员可见**(敏感项如 token/key/password 用 0),1=登录可见,2=公开。已有字段,直接用于权限控制。
- 复杂结构(线路列表、`AVAILABLE_PAYMENT`、`XFYUNLIST`)以 JSON 字符串存 `value`。

### 3.3 注入方案(路径 A,业务代码零改动)

新增 `app/service/SettingService.php`:启动时把 rc_config 读出 → 映射到对应 config 节点 → `Config::set()` 覆盖;数据库没有的项 fallback 到 env 默认值。

```php
// 伪代码
class SettingService extends Service
{
    public function boot()
    {
        \think\facade\Event::listen('AppInit', function () {
            $all = \think\facade\Cache::remember('sys_config_all', function () {
                return \app\media\model\SysConfigModel::where('status', 1)->select()->toArray();
            }, 600);

            // 把扁平 key/value 按映射表写回 config('telegram.*') / config('media.*') ...
            foreach (self::MAP as $key => $configPath) {
                $val = self::pick($all, $key);
                if ($val !== null) \think\facade\Config::set([...], '节点');
            }
            // payment/mailer/map 的 enable 在此处按入库值重新计算(见 §3.5)
        });
    }
}
```
- 注册:`app/service.php` 加入 `SettingService::class`(ThinkPHP 8 服务发现)。
- 缓存:用 `Cache::remember` 避免每请求查库;`Admin::setting()` 保存后 `Cache::delete('sys_config_all')`。
- 时机:`AppInit` 时 config/database.php 已就绪,DB 懒连接可用,安全。

> 渐进式:映射表 `MAP` 可一个域一个域地加(先 telegram、再 media……),每加一域回归测试一次,降低风险。

### 3.4 路径 B(server.php / Workerman)处理

`server.php` 的 `MEDIA_CONFIG`/`TG_CONFIG` 由 `define()` 固化,常驻进程改配置不生效。三种取舍:

- **方案 1(推荐,最省事)**:`server.php` 用到的对接配置(Emby/TG)**维持 `.env`**,只把「Web 后台管理的业务配置」入库。理由:server.php 主要跑定时任务/告警,Emby/TG 凭证极少变动,放 .env 完全可接受,避免引入常驻进程热更新复杂度。
- **方案 2**:`server.php` 连库后从 rc_config 读值再 `define`,接受「改这些配置需 `php think worker:reload` 或重启 server.php」。
- **方案 3(最复杂)**:把常量改成 getter + 定时从库刷新 / 监听 reload 信号,实现热更新。

> `DB_CONFIG` 必须始终来自 `.env`(§3.1)。`Admin.php:1109` 用的 `MEDIA_CONFIG` 也要一并改成 `config('media.*')` 或入库读取,避免 Web 端依赖常量。

### 3.5 加载期 enable 逻辑 & 敏感项安全

- `config/payment.php`、`config/mailer.php`、`config/map.php` 现在在**文件加载时**根据 env 是否为空计算 `enable`。入库后这些判断要移到 `SettingService` 注入完成后重新计算(用入库值判断空)。
- 敏感项(token/key/password):
  - 入库 `type=0` 确保不经公开接口下发;
  - 后台设置页对密钥做 mask 显示(不回显原值,留空则不更新);
  - 可选:对 value 做对称加密存储(`CRONTAB_KEY` 或独立密钥派生),密钥仍放 `.env`。
- 后台 `Admin::setting()` 现在是「任意 key 平铺保存」,需扩展为**按域分组的表单 + 字段白名单 + 类型/必填校验**,否则容易写脏配置。

---

## 4. 实施阶段与顺序

建议分阶段、每阶段可独立验证、可回滚:

- **阶段 0 — 准备**:备份现有 MySQL 数据;确认 `pdo_sqlite` 扩展;新建 `docs` 方案(本文件)。
- **阶段 1 — SQLite 可切换(不影响现网)**:改 `database.php`/`migration.php` 增 sqlite 连接;改 migration 去除 `ON UPDATE`/`engine`;本地用 SQLite 全新建库 + 跑通基本功能。MySQL 仍为默认,零影响。
- **阶段 2 — 数据迁移工具**:写 MySQL→SQLite 搬运命令并校验(若需要真正切换)。
- **阶段 3 — 配置入库(路径 A)**:建 `SettingService` + 映射表 + 缓存;逐域接入(先 1 个域验证);重写 payment/mailer/map 的 enable;扩展后台设置页。
- **阶段 4 — 路径 B**:按 §3.4 选定方案处理 server.php;改造 `Admin.php:1109` 的 `MEDIA_CONFIG` 依赖。
- **阶段 5 — 收尾**:更新 `example.env`(标注哪些已移入后台);文档化「哪些配置在 .env、哪些在后台」。

> SQLite 与配置入库**互相独立**,可分别推进,也可只做其一。

---

## 5. 风险、回滚、验证

**风险**
- SQLite 并发写锁(§2.5)—— 最大风险,规模相关。
- 常驻进程配置热更新(§3.4)。
- 敏感配置入库后的泄露面(§3.5)。
- 配置注入若出错会影响全站读配置 —— 用「DB 没有则 fallback env」兜底,且分域灰度。

**回滚**
- SQLite:`.env` 改回 `DB_DRIVER=mysql` 即切回,数据库文件保留。
- 配置入库:`SettingService` 注入失败时 fallback 到 env;必要时摘除 service.php 注册即恢复纯 env。

**验证清单**
- SQLite:迁移建表成功、登录/注册、签到、计费、Emby 开号、TG webhook、定时任务、WebSocket 通知全链路。
- 配置入库:逐域改后台值 → 对应功能即时生效(Web);清缓存逻辑生效;敏感项不在公开接口出现。

---

## 6. 工作量预估(粗略)

| 模块 | 量级 |
|------|------|
| SQLite 可切换(阶段 1) | 小 — 改 2 个 config + ~21 处 migration 选项 |
| 数据迁移工具(阶段 2) | 小~中 — 一个命令 + 校验 |
| 配置入库注入(阶段 3) | 中 — 新增 service + 映射表 + 改 3 个 config 的 enable + 后台页 |
| server.php / 路径 B(阶段 4) | 小(方案1)~中(方案2/3) |

> 若目标只是「自用、单机、想摆脱 MySQL」:阶段 1 + 阶段 3(方案1)即可,工作量最小、收益明确。
