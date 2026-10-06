<?php

namespace app\listener;

use app\service\SystemSettings;

/**
 * 网站与服务设置统一从数据库读取。
 */
class InitSiteConfig
{
    public function handle($event): void
    {
        // 导入命令须先读取指定旧文件，再初始化默认值，避免默认值抢占缺失键。
        if (PHP_SAPI === 'cli' && ($_SERVER['argv'][1] ?? '') === 'settings:import-env') {
            return;
        }
        try {
            SystemSettings::apply(app(), true);
        } catch (\Throwable $e) {
            // 首次执行数据库迁移时配置表尚不存在，允许控制台继续初始化。
            app()->debug(false);
        }
    }
}
