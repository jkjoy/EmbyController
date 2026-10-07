<?php

namespace app\service;

/** 后台可管理的配置；数据库连接不在此列表中。 */
class SettingsSchema
{
    public static function fields(): array
    {
        $groups = [
            '站点与个性化' => [
                'siteName' => ['网站标题', 'text', '算艺轩', 'APP_NAME', 'app.app_name', ['required' => true, 'maxLength' => 120]],
                'siteSubtitle' => ['网站副标题', 'text', '影视管理站', 'APP_SUBTITLE', 'app.app_subtitle'],
                'currencyName' => ['站内货币名称', 'text', 'R币', null, 'app.currency_name', ['required' => true, 'maxLength' => 20, 'help' => '例如 R币、积分或金币；用于余额、账单、签到和通知']],
                'poweredBy' => ['技术支持 / 版权署名', 'text', 'RandallAnjie.com', 'POWERED_BY', 'app.powered_by'],
                'siteDescription' => ['网站描述', 'text', '专业的影视管理平台', null, 'app.site_description'],
                'siteKeywords' => ['网站关键词', 'text', '', null, 'app.site_keywords', ['help' => '多个关键词用逗号分隔']],
                'siteLogo' => ['网站 Logo', 'asset', '', null, 'app.site_logo', ['help' => '图片 URL 或以 / 开头的站内路径，留空使用文字标题']],
                'siteFavicon' => ['网站图标', 'asset', '', null, 'app.site_favicon'],
                'siteFooter' => ['页脚文字', 'text', '', null, 'app.site_footer'],
                'appHost' => ['网站地址', 'url', '', 'APP_HOST', 'app.app_host', ['help' => '完整站点地址，例如 https://emby.example.com；用于通知链接、Webhook 和后台定时任务']],
                'defaultLang' => ['默认语言', 'select', 'zh-cn', 'DEFAULT_LANG', 'lang.default_lang', ['options' => ['zh-cn' => '简体中文', 'en-us' => 'English']]],
                'appDebug' => ['调试模式', 'bool', false, 'APP_DEBUG', null, ['help' => '生产站点建议关闭']],
            ],
            'Emby 与定时任务' => [
                'embyUrlBase' => ['Emby API 地址', 'url', '', 'EMBY_URLBASE', 'media.urlBase', ['help' => '例如 http://emby:8096/emby/']],
                'embyApiKey' => ['Emby API 密钥', 'secret', '', 'EMBY_APIKEY', 'media.apiKey'],
                'embyAdminUserId' => ['Emby 管理员用户 ID', 'text', '', 'EMBY_ADMINUSERID', 'media.adminUserId'],
                'embyTemplateUserId' => ['Emby 开号模板用户 ID', 'text', '', 'EMBY_TEMPLATEUSERID', 'media.UserTemplateId'],
                'embyLineList' => ['Emby 线路', 'json', [], null, 'media.lineList', ['help' => '[{"name":"直连","url":"https://emby.example.com"}]']],
                'crontabKey' => ['Webhook / 定时任务密钥', 'secret', '', 'CRONTAB_KEY', 'media.crontabKey', ['help' => '初始化时自动生成；留空保留，重置时生成新密钥。配置 Emby Webhook 时请填写自行保存的新密钥；重置后原调用方需同步更新']],
            ],
            '缓存与队列' => [
                'cacheType' => ['缓存方式', 'select', 'file', 'CACHE_TYPE', 'cache.default', ['options' => ['file' => '文件缓存', 'redis' => '外部 Redis'], 'help' => '文件缓存同步发送邮件；Redis 支持异步任务和 Telegram 消息延迟删除。连接设置保存后常驻进程约 5 秒内生效']],
                'redisHost' => ['Redis 地址', 'text', '127.0.0.1', 'REDIS_HOST', 'cache.stores.redis.host'],
                'redisPort' => ['Redis 端口', 'int', 6379, 'REDIS_PORT', 'cache.stores.redis.port', ['min' => 1, 'max' => 65535]],
                'redisPass' => ['Redis 密码', 'secret', '', 'REDIS_PASS', 'cache.stores.redis.password'],
                'redisDb' => ['Redis 数据库编号', 'int', 0, 'REDIS_DB', 'cache.stores.redis.db', ['min' => 0, 'max' => 1023]],
            ],
            '邮件通知' => [
                'mailType' => ['邮件协议', 'select', 'smtp', 'MAIL_TYPE', 'mailer.scheme', ['options' => ['smtp' => 'SMTP', 'smtps' => 'SMTPS']]],
                'mailHost' => ['SMTP 地址', 'text', '', 'MAIL_HOST', 'mailer.host'],
                'mailPort' => ['SMTP 端口', 'int', 587, 'MAIL_PORT', 'mailer.port', ['min' => 1, 'max' => 65535]],
                'mailUser' => ['SMTP 用户名', 'text', '', 'MAIL_USER', 'mailer.username'],
                'mailPass' => ['SMTP 密码', 'secret', '', 'MAIL_PASS', 'mailer.password'],
                'mailFromName' => ['发件人名称', 'text', '', 'MAIL_FROM_NAME', 'mailer.from.name'],
                'mailFromEmail' => ['发件人邮箱', 'email', '', 'MAIL_FROM_EMAIL', 'mailer.from.address'],
                'mailUseSocks5' => ['邮件使用 SOCKS5', 'bool', false, 'MAIL_USE_SOCKS5', 'mailer.use_socks5', ['disabled' => true, 'help' => '当前邮件传输仅支持 SMTP 直连；旧邮件代理选项暂不支持']],
            ],
            'Telegram' => [
                'tgBotToken' => ['机器人 Token', 'secret', '', 'TG_BOT_TOKEN', 'telegram.botConfig.bots.randallanjie_bot.token'],
                'tgBotUsername' => ['机器人用户名', 'text', '', 'TG_BOT_USERNAME', 'telegram.botConfig.bots.randallanjie_bot.username'],
                'tgBotAdminId' => ['Telegram 管理员 ID', 'text', '', 'TG_BOT_ADMIN_ID', 'telegram.adminId'],
                'tgBotGroupId' => ['Telegram 群组 ID', 'text', '', 'TG_BOT_GROUP_ID', 'telegram.groupSetting.chat_id'],
                'tgBotGroupNotify' => ['启用群组通知', 'bool', false, 'TG_BOT_GROUP_NOTIFY', 'telegram.groupSetting.allow_notify'],
                'tgWebhookSecret' => ['Webhook 校验密钥', 'secret', '', 'TG_BOT_WEBHOOK_SECRET', 'telegram.webhookSecret'],
            ],
            '支付' => [
                'payUrl' => ['易支付地址', 'url', '', 'PAY_URL', 'payment.epay.urlBase'],
                'payMerchantId' => ['商户 ID', 'text', '', 'PAY_MCHID', 'payment.epay.id'],
                'payKey' => ['商户密钥', 'secret', '', 'PAY_KEY', 'payment.epay.key'],
                'payMethods' => ['支付方式', 'json', [], null, 'payment.epay.availablePayment', ['help' => '["alipay","wxpay"]；未配置时关闭支付']],
            ],
            'SOCKS5 代理' => [
                'socks5Enable' => ['启用 SOCKS5 代理', 'bool', false, 'SOCKS5_ENABLE', 'proxy.socks5.enable'],
                'socks5Host' => ['代理地址', 'text', '127.0.0.1', 'SOCKS5_HOST', 'proxy.socks5.host'],
                'socks5Port' => ['代理端口', 'int', 1080, 'SOCKS5_PORT', 'proxy.socks5.port', ['min' => 1, 'max' => 65535]],
                'socks5Username' => ['代理用户名', 'text', '', 'SOCKS5_USERNAME', 'proxy.socks5.username'],
                'socks5Password' => ['代理密码', 'secret', '', 'SOCKS5_PASSWORD', 'proxy.socks5.password'],
            ],
            '人机验证与地图' => [
                'turnstileNoninteractiveSiteKey' => ['Turnstile 登录 Site Key', 'text', '', 'CLOUDFLARE_TURNSTILE_NONINTERACTIVE_SITEKEY', 'apiinfo.cloudflareTurnstile.noninteractive.sitekey'],
                'turnstileNoninteractiveSecret' => ['Turnstile 登录 Secret', 'secret', '', 'CLOUDFLARE_TURNSTILE_NONINTERACTIVE_SECRET', 'apiinfo.cloudflareTurnstile.noninteractive.secret'],
                'turnstileInvisibleSiteKey' => ['Turnstile 隐形 Site Key', 'text', '', 'CLOUDFLARE_TURNSTILE_INVISIBLE_SITEKEY', 'apiinfo.cloudflareTurnstile.invisible.sitekey'],
                'turnstileInvisibleSecret' => ['Turnstile 隐形 Secret', 'secret', '', 'CLOUDFLARE_TURNSTILE_INVISIBLE_SECRET', 'apiinfo.cloudflareTurnstile.invisible.secret'],
                'tencentMapKey' => ['腾讯地图 Key', 'text', '', 'TENCENT_MAP_KEY', 'map.key'],
                'tencentMapSk' => ['腾讯地图签名密钥', 'secret', '', 'TENCENT_MAP_SK', 'map.sk'],
            ],
            'AI 服务' => [
                'aiApiKey' => ['兼容 OpenAI 的 API 密钥', 'secret', '', 'AI_API_KEY', 'ai.api_key'],
                'aiBaseUrl' => ['AI 接口地址', 'url', 'https://api.openai.com', 'AI_BASE_URL', 'ai.base_url'],
                'aiModel' => ['AI 模型', 'text', 'gpt-3.5-turbo', 'AI_MODEL', 'ai.model'],
                'geminiApiKey' => ['Gemini API 密钥', 'secret', '', 'GEMINI_API_KEY', 'gemini.api_key'],
                'xfyunList' => ['讯飞星火接口', 'json', [], null, 'apiinfo.xfyunList', ['secret' => true, 'help' => '{"接口ID":{"appid":"应用ID","apikey":"密钥","apisecret":"签名密钥"}}；留空保留已配置接口']],
            ],
            'MoviePilot' => [
                'moviepilotEnabled' => ['启用 MoviePilot', 'bool', false, null, 'media.moviepilot.enabled'],
                'moviepilotUrl' => ['MoviePilot 地址', 'url', '', 'MOVIEPILOT_URL', 'media.moviepilot.url'],
                'moviepilotUsername' => ['MoviePilot 用户名', 'text', '', null, 'media.moviepilot.username'],
                'moviepilotPassword' => ['MoviePilot 密码', 'secret', '', null, 'media.moviepilot.password'],
            ],
            '业务设置' => [
                'avableRegisterCount' => ['可注册人数', 'int', 0, null, null, ['min' => -1]],
                'chargeRate' => ['充值到账比', 'decimal', 1, null, null, ['min' => 0]],
                'sysnotificiations' => ['系统通知', 'text', '您有一条新消息：{Message}', null, null, ['maxLength' => 60000]],
                'findPasswordTemplate' => ['找回密码邮件模板', 'text', '您的找回密码链接是：<a href="{Url}">{Url}</a>', null, null, ['maxLength' => 60000]],
                'verifyCodeTemplate' => ['注册验证码邮件模板', 'text', '您的验证码是：{Code}', null, null, ['maxLength' => 60000]],
                'notificationTemplate' => ['通知邮件模板', 'text', '{Message}', null, null, ['maxLength' => 60000]],
                'clientList' => ['客户端白名单', 'json', [], null, null],
                'clientBlackList' => ['客户端黑名单', 'json', [], null, null],
                'maxActiveDeviceCount' => ['最大活跃设备数', 'int', 0, null, null, ['min' => 0]],
                'signInMaxAmount' => ['签到最大金额', 'decimal', 0, null, null, ['min' => 0, 'max' => 10]],
                'signInMinAmount' => ['签到最小金额', 'decimal', 0, null, null, ['min' => 0, 'max' => 10]],
                'telegramRules' => ['机器人回复规则', 'json', [], null, null],
                'privacyPolicy' => ['隐私政策', 'text', '', null, null, ['maxLength' => 60000]],
                'userAgreement' => ['用户协议', 'text', '', null, null, ['maxLength' => 60000]],
            ],
        ];

        $fields = [];
        foreach ($groups as $group => $items) {
            foreach ($items as $key => $item) {
                [$label, $type, $default, $legacy, $path] = $item;
                $fields[$key] = array_merge([
                    'key' => $key, 'label' => $label, 'group' => $group,
                    'type' => $type, 'default' => $default, 'legacy' => $legacy,
                    'path' => $path, 'secret' => $type === 'secret', 'help' => '',
                    'maxLength' => 2048,
                ], $item[5] ?? []);
            }
        }
        return $fields;
    }
}
