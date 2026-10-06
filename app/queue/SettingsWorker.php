<?php
namespace app\queue;

use think\queue\Worker;

/** 单轮拉取也保留常驻队列的任务超时保护。 */
class SettingsWorker extends Worker
{
    protected function getNextJob($connector, $queue)
    {
        $job = parent::getNextJob($connector, $queue);
        if ($this->supportsAsyncSignals()) {
            $this->registerTimeoutHandler($job, 60);
        }
        return $job;
    }

    public function runNextJob($connection, $queue, $delay = 0, $sleep = 3, $maxTries = 0)
    {
        try {
            parent::runNextJob($connection, $queue, $delay, $sleep, $maxTries);
        } finally {
            if ($this->supportsAsyncSignals()) {
                pcntl_alarm(0);
            }
        }
    }
}
