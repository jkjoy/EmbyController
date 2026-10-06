<?php

namespace app\service;

use InvalidArgumentException;
use think\App;
use think\facade\Db;
use Dotenv\Dotenv;

/** 直接从数据库读取，避免在取得 Redis 配置前依赖 Redis 缓存。 */
class SystemSettings
{
    private static ?array $snapshot = null;
    private static float $loadedAt = 0;

    public static function definitions(): array
    {
        return SettingsSchema::fields();
    }

    public static function all(bool $refresh = false): array
    {
        if ($refresh || self::$snapshot === null || microtime(true) - self::$loadedAt >= 5) {
            self::$snapshot = self::initialize();
            self::$loadedAt = microtime(true);
        }
        return self::$snapshot;
    }

    public static function get(string $key, $default = null)
    {
        return self::all()[$key] ?? $default;
    }

    private static function rows(): array
    {
        $values = [];
        // 兼容历史重复行；读取最早一行，保存时同步该键的所有行。
        foreach (Db::name('config')->order('id')->select() as $row) {
            if (!array_key_exists($row['key'], $values)) {
                $values[$row['key']] = $row['value'];
            }
        }
        return $values;
    }

    /** 缺失项才导入/初始化；0、false 和空字符串都是已保存的值。 */
    public static function initialize(): array
    {
        $rows = self::rows();
        $fields = self::definitions();
        if (array_diff_key($fields, $rows)) {
            self::importLegacy();
            $rows = self::rows();
            Db::transaction(function () use ($fields, &$rows) {
                foreach ($fields as $key => $field) {
                    if (!array_key_exists($key, $rows)) {
                        $value = $key === 'crontabKey' ? bin2hex(random_bytes(32)) : $field['default'];
                        self::write($key, $value, $field);
                        $rows[$key] = self::encode($value, $field);
                    }
                }
            });
        }
        $values = [];
        foreach ($fields as $key => $field) {
            try {
                $values[$key] = self::normalize($rows[$key], $field);
            } catch (InvalidArgumentException $e) {
                // 历史配置不合法时使用安全默认值，允许管理员进入后台修正。
                $values[$key] = $field['default'];
            }
        }
        return $values;
    }

    /** 迁移旧环境配置；运行时仅使用数据库。返回键名，不返回密钥。 */
    public static function importLegacy(?string $path = null): array
    {
        $path = $path ?? app()->getRootPath() . '.env';
        $environment = array_merge(getenv() ?: [], $_ENV);
        if (is_file($path)) {
            $environment = array_merge($environment, Dotenv::parse(file_get_contents($path)));
        }
        $environment = array_change_key_case($environment, CASE_UPPER);
        $candidates = [];
        foreach (self::definitions() as $key => $field) {
            if ($field['legacy'] && array_key_exists($field['legacy'], $environment)) {
                $value = $environment[$field['legacy']];
                if ($key === 'tgBotToken' && $value === 'notgbot') {
                    $value = '';
                }
                if ($key === 'appHost' && $value !== '') {
                    if (!preg_match('~^https?://~i', $value)) {
                        $value = 'http://' . $value;
                    }
                    $value = preg_replace('~/media/?$~', '', rtrim($value, '/'));
                }
                if ($key !== 'crontabKey' || $value !== '') {
                    $candidates[$key] = $value;
                }
            }
        }
        $lines = $payments = $xfyun = [];
        foreach ($environment as $name => $value) {
            if (preg_match('/^EMBY_LINE_LIST_(\d+)_(NAME|URL)$/', $name, $match)) {
                $lines[$match[1]][strtolower($match[2])] = $value;
            } elseif (preg_match('/^AVAILABLE_PAYMENT_(\d+)$/', $name, $match)) {
                $payments[$match[1]] = $value;
            } elseif (preg_match('/^XFYUNLIST_(.+)_(APPID|APIKEY|APISECRET)$/', $name, $match)) {
                $xfyun[strtolower($match[1])][strtolower($match[2])] = $value;
            }
        }
        if ($lines) {
            ksort($lines, SORT_NUMERIC);
            $candidates['embyLineList'] = array_values($lines);
        }
        if ($payments) {
            ksort($payments, SORT_NUMERIC);
            $candidates['payMethods'] = array_values($payments);
        }
        if ($xfyun) {
            $candidates['xfyunList'] = $xfyun;
        }
        $rows = self::rows();
        $fields = self::definitions();
        $imported = [];
        Db::transaction(function () use ($candidates, $rows, $fields, &$imported) {
            foreach ($candidates as $key => $value) {
                if (array_key_exists($key, $rows)) {
                    continue;
                }
                try {
                    $value = self::normalize($value, $fields[$key]);
                } catch (InvalidArgumentException $e) {
                    continue;
                }
                self::write($key, $value, $fields[$key]);
                $imported[] = $key;
            }
        });
        self::$snapshot = null;
        return $imported;
    }

    public static function save(array $data, array $clearSecrets = []): void
    {
        $fields = self::definitions();
        $values = self::all(true);
        $updates = [];
        foreach ($clearSecrets as $key) {
            if (!is_string($key) || !isset($fields[$key]) || !$fields[$key]['secret']) {
                throw new InvalidArgumentException('无效的密钥清除项');
            }
            $updates[$key] = $key === 'crontabKey' ? bin2hex(random_bytes(32)) : $fields[$key]['default'];
        }
        foreach ($data as $key => $value) {
            if (!isset($fields[$key])) {
                throw new InvalidArgumentException('包含不支持的设置项');
            }
            if (in_array($key, $clearSecrets, true)) {
                continue;
            }
            $field = $fields[$key];
            if ($field['secret'] && $value === '') {
                continue;
            }
            $updates[$key] = self::normalize($value, $field);
        }
        $values = array_replace($values, $updates);
        if ($values['signInMinAmount'] > $values['signInMaxAmount']) {
            throw new InvalidArgumentException('签到最小金额不能大于最大金额');
        }
        Db::transaction(function () use ($updates, $fields) {
            foreach ($updates as $key => $value) {
                self::write($key, $value, $fields[$key]);
            }
        });
        self::$snapshot = null;
    }

    private static function encode($value, array $field): string
    {
        if ($field['type'] === 'json') {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }

    private static function write(string $key, $value, array $field): void
    {
        $data = ['value' => self::encode($value, $field), 'type' => 0, 'status' => 1, 'updatedAt' => date('Y-m-d H:i:s')];
        if (Db::name('config')->where('key', $key)->count()) {
            Db::name('config')->where('key', $key)->update($data);
        } else {
            Db::name('config')->insert(array_merge($data, ['key' => $key, 'appName' => 'media', 'createdAt' => date('Y-m-d H:i:s')]));
        }
    }

    private static function normalize($value, array $field)
    {
        $error = function (string $message) use ($field) {
            throw new InvalidArgumentException($field['label'] . '：' . $message);
        };
        $type = $field['type'];
        if ($type === 'json') {
            if (is_string($value)) {
                if (strlen($value) > 60000) {
                    $error('内容过长');
                }
                try {
                    $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    $error('需要有效的 JSON 数组或对象');
                }
            }
            if (!is_array($value) || strlen(json_encode($value, JSON_THROW_ON_ERROR)) > 60000) {
                $error('需要 JSON 数组或对象，且不超过 60000 字节');
            }
            if ($field['key'] !== 'xfyunList' && array_values($value) !== $value) {
                $error('需要 JSON 数组');
            }
            if ($field['key'] === 'embyLineList') {
                foreach ($value as $line) {
                    if (!is_array($line) || !isset($line['name'], $line['url']) || !is_string($line['name']) || !self::httpUrl($line['url'])) {
                        $error('每条线路需要 name 和有效的 HTTP/HTTPS url');
                    }
                }
            }
            if (in_array($field['key'], ['payMethods', 'clientList', 'clientBlackList'], true)) {
                foreach ($value as $item) {
                    if (!is_string($item) || trim($item) === '') {
                        $error('数组项需要非空字符串');
                    }
                }
            }
            if ($field['key'] === 'xfyunList') {
                foreach ($value as $entry) {
                    if (!is_array($entry) || !isset($entry['appid'], $entry['apikey'], $entry['apisecret'])) {
                        $error('每个接口需要 appid、apikey 和 apisecret');
                    }
                    foreach ($entry as $item) {
                        if (!is_string($item)) {
                            $error('接口配置值需要字符串');
                        }
                    }
                }
            }
            return $value;
        }
        if (!is_scalar($value)) {
            $error('格式不正确');
        }
        if ($type === 'bool') {
            $result = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($result === null) {
                $error('需要布尔值');
            }
            if (!empty($field['disabled']) && $result) {
                $error('当前邮件传输仅支持 SMTP 直连');
            }
            return $result;
        }
        if ($type === 'int' || $type === 'decimal') {
            $result = $type === 'int' ? filter_var($value, FILTER_VALIDATE_INT) : (is_numeric($value) ? (float) $value : false);
            if ($result === false || !is_finite((float) $result) || (isset($field['min']) && $result < $field['min']) || (isset($field['max']) && $result > $field['max'])) {
                $error('数值超出允许范围或格式不正确');
            }
            return $result;
        }
        $value = $field['secret'] ? (string) $value : trim((string) $value);
        if (strlen($value) > $field['maxLength'] || strpos($value, "\0") !== false) {
            $error('内容过长或包含无效字符');
        }
        if (!empty($field['required']) && $value === '') {
            $error('不能为空');
        }
        if ($type === 'select' && !array_key_exists($value, $field['options'])) {
            $error('请选择有效选项');
        }
        if ($value !== '' && $type === 'url' && !self::httpUrl($value)) {
            $error('需要完整的 HTTP/HTTPS 地址');
        }
        if ($value !== '' && $type === 'asset' && !self::httpUrl($value) && !preg_match('~^/(?!/)[^\x00-\x20\\\\]*$~', $value)) {
            $error('需要 HTTP/HTTPS 图片地址或以 / 开头的站内路径');
        }
        if ($value !== '' && $type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $error('邮箱格式不正确');
        }
        if ($field['key'] === 'tgWebhookSecret' && $value !== '' && !preg_match('/^[A-Za-z0-9_-]{1,256}$/', $value)) {
            $error('仅允许字母、数字、下划线和短横线，最长 256 位');
        }
        if ($value !== '' && in_array($field['key'], ['payUrl', 'embyUrlBase'], true)) {
            $value = rtrim($value, '/') . '/';
        }
        return $value;
    }

    private static function httpUrl($value): bool
    {
        return is_string($value) && preg_match('~^https?://~i', $value) && filter_var($value, FILTER_VALIDATE_URL)
            && !preg_match('/[\x00-\x20\\\\]/', $value);
    }

    public static function formData(): array
    {
        $values = self::all(true);
        $settings = $secretStates = $sections = [];
        foreach (self::definitions() as $key => $field) {
            if ($field['group'] !== '业务设置' && !in_array($key, ['siteName', 'siteSubtitle', 'poweredBy'], true)) {
                $sections[$field['group']][] = $field;
            }
            if ($field['secret']) {
                $secretStates[$key] = $values[$key] !== '' && $values[$key] !== [];
                $settings[$key] = '';
            } else {
                $settings[$key] = $field['type'] === 'json' ? self::encode($values[$key], $field) : $values[$key];
            }
        }
        return ['settings' => $settings, 'secretStates' => $secretStates, 'settingSections' => $sections];
    }

    public static function apply(?App $app = null, bool $refresh = false): array
    {
        $app = $app ?? app();
        $values = self::all($refresh);
        $modules = [];
        foreach (self::definitions() as $key => $field) {
            if (!$field['path']) {
                continue;
            }
            $parts = explode('.', $field['path']);
            $module = array_shift($parts);
            $modules[$module] ??= $app->config->get($module, []);
            $target = &$modules[$module];
            foreach ($parts as $part) {
                $target[$part] ??= [];
                $target = &$target[$part];
            }
            $target = $values[$key];
            unset($target);
        }
        $modules['app']['app_host'] = rtrim($values['appHost'], '/');
        $modules['app']['app_debug'] = $values['appDebug'];
        $modules['telegram']['botConfig']['bots']['randallanjie_bot']['token'] = $values['tgBotToken'] ?: 'notgbot';
        $modules['mailer']['from']['name'] = $values['mailFromName'] ?: $values['siteName'];
        $modules['mailer']['enable'] = $values['mailHost'] !== '' && $values['mailUser'] !== '' && $values['mailPass'] !== '' && $values['mailFromEmail'] !== '';
        $modules['payment']['epay']['enable'] = $values['payUrl'] !== '' && $values['payMerchantId'] !== '' && $values['payKey'] !== '' && $values['payMethods'] !== [];
        $modules['map']['enable'] = $values['tencentMapKey'] !== '' && $values['tencentMapSk'] !== '';
        $modules['queue'] = $app->config->get('queue', []);
        $modules['queue']['default'] = $values['cacheType'] === 'redis' ? 'redis' : 'sync';
        $redis = ['host' => $values['redisHost'], 'port' => $values['redisPort'], 'password' => $values['redisPass'], 'db' => $values['redisDb'], 'select' => $values['redisDb'], 'timeout' => 3];
        $modules['cache']['stores']['redis'] = array_replace($modules['cache']['stores']['redis'] ?? [], $redis);
        $modules['queue']['connections']['redis'] = array_replace($modules['queue']['connections']['redis'] ?? [], $redis, ['block_for' => null]);
        $cacheChanged = $app->config->get('cache') !== $modules['cache'];
        $queueChanged = $app->config->get('queue') !== $modules['queue'];
        $langChanged = $app->config->get('lang.default_lang') !== $values['defaultLang'];
        foreach ($modules as $module => $config) {
            $app->config->set($config, $module);
        }
        $app->debug($values['appDebug']);
        if ($cacheChanged && $app->exists('cache')) {
            $app->cache->forgetDriver(['file', 'redis']);
        }
        if ($queueChanged && $app->exists('queue')) {
            $app->queue->forgetDriver(['sync', 'redis']);
        }
        if ($langChanged) {
            $app->delete('lang');
            $app->lang->switchLangSet($values['defaultLang']);
        }
        return $values;
    }
}
