<?php

namespace App\Console\Commands;

use App\Console\Workers\SellWorker;
use Mix\Coroutine\Channel;
use Mix\Signal\SignalNotify;
use Mix\WorkerPool\WorkerDispatcher;

/**
 * Class SellCommand
 * @package App\Console\Commands
 * @author alex <alexgaozhongze@gmail.com>
 */
class SellCommand
{
    /**
     * 主函数
     */
    public function main()
    {
        $notify = new SignalNotify(SIGHUP, SIGINT, SIGTERM);

        $maxWorkers = 6; 
        $maxQueue   = 3;
        $jobQueue   = new Channel($maxQueue);
        $dispatcher = new WorkerDispatcher($jobQueue, $maxWorkers, SellWorker::class);

        xgo(function () use ($notify, $dispatcher) {
            $notify->channel()->pop();
            $dispatcher->stop();
            $notify->stop();
        });

        xgo(function () use ($jobQueue, $dispatcher) {
            // 投放任务
            $redis = context()->get('redis');
            // $jobQueue->push(235204257757838);
            while ($orderId = $redis->brpoplpush('buy:order', 'buy:order:check', 0)) {
                $jobQueue->push($orderId);
            }
            // 停止
            $dispatcher->stop();
        });

        $dispatcher->run(); // 阻塞代码，直到任务全部执行完成并且全部 Worker 停止
    }
}
