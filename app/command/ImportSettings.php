<?php

namespace app\command;

use app\service\SystemSettings;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

class ImportSettings extends Command
{
    protected function configure()
    {
        $this->setName('settings:import-env')
            ->setDescription('Import legacy environment settings without overwriting database values')
            ->addOption('file', null, Option::VALUE_OPTIONAL, 'Path to the legacy .env file');
    }

    protected function execute(Input $input, Output $output)
    {
        try {
            $path = $input->getOption('file');
            if ($path !== null && !is_file($path)) {
                $output->writeln('旧配置文件不存在。');
                return 1;
            }
            $imported = SystemSettings::importLegacy($path);
            SystemSettings::apply($this->app, true);
            $output->writeln('配置初始化完成，本次导入 ' . count($imported) . ' 项；已有数据库配置保持不变。');
            $output->writeln('请在管理后台核对设置，然后从 .env 移除非 DB_ 配置。');
            return 0;
        } catch (\Throwable $e) {
            $output->writeln('配置导入失败，请检查数据库连接并先执行 migrate:run。');
            return 1;
        }
    }
}
