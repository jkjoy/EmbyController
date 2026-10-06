<?php
namespace app\command;

use app\service\SystemSettings;
use app\queue\SettingsWorker;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\queue\event\JobProcessing;

/**
 * 每次拉取任务前刷新后台配置，使缓存模式和 Redis 连接变更无需重启。
 */
class SettingsQueueWorker extends Command
{
    private bool $running = true;
    private ?string $connectionSignature = null;

    protected function configure()
    {
        $this->setName('settings:queue-worker')
            ->setDescription('Process queued jobs using current database settings')
            ->addOption('queue', null, Option::VALUE_OPTIONAL, 'Queue name', 'main')
            ->addOption('tries', null, Option::VALUE_OPTIONAL, 'Maximum attempts', 3)
            ->addOption('sleep', null, Option::VALUE_OPTIONAL, 'Seconds between idle polls', 5)
            ->addOption('once', null, Option::VALUE_NONE, 'Poll once and exit');
    }

    protected function execute(Input $input, Output $output)
    {
        $sleep = max(1, (int) $input->getOption('sleep'));
        $tries = max(1, (int) $input->getOption('tries'));
        $queue = (string) $input->getOption('queue');
        $lastWarning = 0;
        $lastRestart = null;
        $restartInitialized = false;

        // JobProcessing 发生在任务 fire 前，邮件和 Telegram 使用最新配置。
        $this->app->event->listen(JobProcessing::class, function() {
            SystemSettings::apply($this->app, true);
        });

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function() { $this->running = false; });
            pcntl_signal(SIGINT, function() { $this->running = false; });
        }

        do {
            try {
                SystemSettings::apply($this->app, true);
                if (!$restartInitialized) {
                    $lastRestart = $this->app->cache->get('think:queue:restart');
                    $restartInitialized = true;
                }
                $this->processNextJob($queue, $sleep, $tries);
                if ($this->app->make(SettingsWorker::class)->memoryExceeded(128)) {
                    return 12;
                }
                if ($this->app->cache->get('think:queue:restart') != $lastRestart) {
                    return 0;
                }
            } catch (\Throwable $e) {
                if (time() - $lastWarning >= 60) {
                    $output->writeln('队列连接暂时不可用，将使用最新后台设置重试。');
                    $lastWarning = time();
                }
                sleep($sleep);
            }
        } while ($this->running && !$input->getOption('once'));

        return 0;
    }

    protected function processNextJob(string $queue, int $sleep, int $tries): void
    {
        $connection = (string) $this->app->config->get('queue.default', 'sync');
        $signature = serialize([
            $connection,
            $this->app->config->get('queue.connections.redis', []),
        ]);
        if ($signature !== $this->connectionSignature) {
            $this->app->queue->forgetDriver(['redis', 'sync']);
            $this->connectionSignature = $signature;
        }

        // 文件缓存使用同步发送；不创建 Redis 连接，也不拉取异步任务。
        if ($connection !== 'redis') {
            sleep($sleep);
            return;
        }

        $this->app->make(SettingsWorker::class)->runNextJob('redis', $queue, 60, $sleep, $tries);
    }
}
