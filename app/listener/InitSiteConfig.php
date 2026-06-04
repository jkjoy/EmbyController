<?php

namespace app\listener;

use app\media\model\SysConfigModel;
use think\facade\Cache;
use think\facade\Config;

/**
 * 应用初始化时，将后台设置的网站名称 / 副标题 / 技术支持署名（数据库 rc_config 表的
 * siteName / siteSubtitle / poweredBy）注入到 config('app.*')，
 * 使全站模板（及 siteTitle() helper）自动显示后台配置的内容，无需逐个修改模板。
 */
class InitSiteConfig
{
    public function handle($event): void
    {
        try {
            // 带缓存读取，避免每个请求都查询数据库；后台保存设置时会清除该缓存
            $map = Cache::get('site_config');
            if ($map === null) {
                $rows = (new SysConfigModel())
                    ->whereIn('key', ['siteName', 'siteSubtitle', 'poweredBy'])
                    ->column('value', 'key');

                $map = [
                    // 网站名称为空时回落到默认值（站名不应为空）
                    'app_name' => (isset($rows['siteName']) && $rows['siteName'] !== '')
                        ? $rows['siteName']
                        : Config::get('app.app_name', '算艺轩'),
                    // 副标题尊重用户设置：已设置（含空字符串）则使用，未设置才回落默认值
                    'app_subtitle' => array_key_exists('siteSubtitle', $rows)
                        ? $rows['siteSubtitle']
                        : Config::get('app.app_subtitle', '影视管理站'),
                    // 技术支持署名为空时回落默认值（署名为空会导致"由提供技术支持"这类文案不通顺）
                    'powered_by' => (isset($rows['poweredBy']) && $rows['poweredBy'] !== '')
                        ? $rows['poweredBy']
                        : Config::get('app.powered_by', 'RandallAnjie.com'),
                ];

                Cache::set('site_config', $map, 3600);
            }

            Config::set($map, 'app');
        } catch (\Throwable $e) {
            // 数据库或 config 表尚未就绪（如尚未安装/迁移）时，保持 config/app.php 中的默认值，不影响启动
        }
    }
}
