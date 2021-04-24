<?php

namespace App\Console\Commands;

use App\Console\Models\CoinModel;
use Swoole\Coroutine\Channel;
use Mix\Signal\SignalNotify;
use Mix\Redis\Connection;
use Mix\Redis\Redis;
use Mix\Time\Time;


/**
 * Class BtcusdtCommand
 * @package App\Console\Commands
 * @author alex <alexgaozhongze@gmail.com>
 */
class BtcusdtCommand
{

    /**
     * @var Channel
     */
    public $quit;

    /**
     * @var Redis
     */
    public $redis;

    /**
     * @var Connection
     */
    public $conn;

    /**
     * CoroutinePoolDaemonCommand constructor.
     */
    public function __construct()
    {
        $this->quit  = new Channel();
        $this->redis = context()->get('redis');
        $this->conn  = $this->redis->borrow();
    }

    /**
     * 主函数
     */
    public function main()
    {
        // 捕获信号
        $notify = new SignalNotify(SIGHUP, SIGINT, SIGTERM);
        xgo(function () use ($notify) {
            $notify->channel()->pop();
            $this->quit->push(true);
            $notify->stop();
        });

        $symbol = 'btcusdt';

        $symbolInfo = $this->conn->hget('symbol', $symbol);
        $symbolInfo = unserialize($symbolInfo);

        xgo(function () use ($symbol, $symbolInfo) {
            while (true) {
                usleep(rand(666, 666666));

                if (!$this->quit->isEmpty()) return;

                $this->handle($symbol, $symbolInfo);
            }
        });
    }

    public function handle($symbol, $symbolInfo)
    {
        $coin = new CoinModel();
        $klineRes = $coin->get_history_kline($symbol, '1min', 63);
        $klineList = $klineRes->data;

        $emaList = [];
        $klineList = array_reverse($klineList);
        $currentKline = end($klineList);
        
        if ($currentKline->high != $currentKline->close && $currentKline->low != $currentKline->close) return;

        $lowest = $currentKline->low;
        $highest = $currentKline->high;
        foreach ($klineList as $value) {
            $value->high > $highest && $highest = $value->high;
            $value->low < $lowest && $lowest = $value->low;

            $preEma = end($emaList);
            if ($preEma) {
                $emaInfo = [
                    'ema3'  => 2 / (3  + 1) * $value->close + (3  - 1) / (3  + 1) * $preEma['ema3'],
                    'ema6'  => 2 / (6  + 1) * $value->close + (6  - 1) / (6  + 1) * $preEma['ema6'],
                    'ema9'  => 2 / (9  + 1) * $value->close + (9  - 1) / (9  + 1) * $preEma['ema9'],
                    'ema36' => 2 / (36 + 1) * $value->close + (36 - 1) / (36 + 1) * $preEma['ema36']
                ];
            } else {
                $emaInfo = [
                    'ema3'  => $value->close,
                    'ema6'  => $value->close,
                    'ema9'  => $value->close,
                    'ema36' => $value->close
                ];
            }
            $emaList[] = $emaInfo;
        }

        $type = '';
        $currentEma = end($emaList);
        $preEma = prev($emaList);
        if ($currentEma['ema3'] > $currentEma['ema6'] && $currentEma['ema6'] > $currentEma['ema9'] && $currentEma['ema9'] > $currentEma['ema36'] && $currentKline->high == $currentKline->close && $highest == $currentKline->close) {
            $type = 'sell';
            $orderNum = 6;
        } elseif ($currentEma['ema3'] < $currentEma['ema6'] && $currentEma['ema6'] < $currentEma['ema9'] && $currentEma['ema9'] < $currentEma['ema36'] && $currentKline->low == $currentKline->close && $lowest == $currentKline->close) {
            $type = 'buy';
            $orderNum = 9;
        } else {
            return;
        }
        unset($klineRes, $klineList, $emaList);

        if (!$this->conn->setnx("htusdt:$currentKline->id", null)) return;
        $this->conn->expire("htusdt:$currentKline->id", 63);

        $amount = $orderNum / $currentKline->close;
        $mul = 1;
        for ($i = 0; $i < $symbolInfo['amount-precision']; $i ++) {
            $mul *= 10;
        }
        $amount *= $mul;
        $amount = ceil($amount);
        $amount /= $mul;

        if ('buy' == $type) {
            echo date('H:i:s', strtotime("+8 hours")), "   buy   $symbol   $currentKline->close" .  PHP_EOL;
            $buyRes = $coin->place_order($amount, $currentKline->close, $symbol, 'buy-limit');
            $orderId = $buyRes->data;
        } elseif ('sell' == $type) {
            echo date('H:i:s', strtotime("+8 hours")), "   sel   $symbol   $currentKline->close" .  PHP_EOL;
            $sellRes = $coin->place_order($amount, $currentKline->close, $symbol, 'sell-limit');
            $orderId = $sellRes->data;
        }
        if (!$orderId) return;

        $timer = Time::newTimer(36.9 * Time::SECOND);
        xgo(function () use ($timer, $orderId, $coin) {
            $ts = $timer->channel()->pop();
            if (!$ts) return;
            
            $order = $coin->get_order($orderId);
            $orderInfo = $order->data;
            if ('filled' != $orderInfo->state) {
                $coin->cancel_order($orderId);
            }
            return;
        });

        return;
    }
}
