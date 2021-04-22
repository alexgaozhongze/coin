<?php

namespace App\Console\Commands;

use App\Console\Models\CoinModel;
use Mix\Signal\SignalNotify;
use Mix\Time\Time;

/**
 * Class HtusdtCommand
 * @package App\Console\Commands
 * @author alex <alexgaozhongze@gmail.com>
 */
class HtusdtCommand
{

    /**
     * 主函数
     */
    public function main()
    {
        $notify = new SignalNotify(SIGHUP, SIGINT, SIGTERM);
        $ticker = Time::newTicker(999);
        xgo(function () use ($notify, $ticker) {
            $notify->channel()->pop();
            $ticker->stop();
            $notify->stop();
            return;
        });

        $symbol = 'htusdt';

        $redis = context()->get('redis');
        $symbolInfo = $redis->hget('symbol', $symbol);
        $symbolInfo = unserialize($symbolInfo);

        $conn = $redis->borrow();
        $conn = null;

        xgo(function () use ($ticker, $symbol, $symbolInfo) {
            while (true) {
                $ts = $ticker->channel()->pop();
                if (!$ts) return;
                $this->handle($symbol, $symbolInfo);
            }
        });
    }

    public function handle($symbol, $symbolInfo)
    {
        $orderNum = 5;

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
        } elseif ($currentEma['ema3'] < $currentEma['ema6'] && $currentEma['ema6'] < $currentEma['ema9'] && $currentEma['ema9'] < $currentEma['ema36'] && $currentKline->low == $currentKline->close && $lowest == $currentKline->close) {
            $type = 'buy';
        } else {
            return;
        }
        unset($klineRes, $klineList, $emaList);

        $redis = context()->get('redis');
        if (!$redis->setnx("htusdt:$currentKline->id", null)) {
            $conn = $redis->borrow();
            $conn = null;
            return;
        }
        $redis->expire("htusdt:$currentKline->id", 63);
        $conn = $redis->borrow();
        $conn = null;

        $amount = $orderNum / $currentKline->close;
        $mul = 1;
        for ($i = 0; $i < $symbolInfo['amount-precision']; $i ++) {
            $mul *= 10;
        }
        $amount *= $mul;
        $amount = ceil($amount);
        $amount /= $mul;

        if ('buy' == $type) {
            echo 'buy: ', $currentKline->close, ' ', $amount, ' ', date('H:i:s', strtotime("+8 hours")), PHP_EOL;
            $buyRes = $coin->place_order($amount, $currentKline->close, $symbol, 'buy-limit');
            $orderId = $buyRes->data;
        } elseif ('sell' == $type) {
            echo 'sell: ', $currentKline->close, ' ', $amount, ' ', date('H:i:s', strtotime("+8 hours")), PHP_EOL;
            $sellRes = $coin->place_order($amount, $currentKline->close, $symbol, 'sell-limit');
            $orderId = $sellRes->data;
        }
        if (!$orderId) return;

        $timer = Time::newTimer(36.9 * Time::SECOND);
        xgo(function () use ($timer, $orderId, $coin, $symbol, $symbolInfo) {
            $ts = $timer->channel()->pop();
            if (!$ts) return;
            
            $order = $coin->get_order($orderId);
            $orderInfo = $order->data;
            if ('filled' != $orderInfo->state) {
                $cancelRes = $coin->cancel_order($orderId);
                echo 'cancel: ', $symbol, ' ', $cancelRes->data, PHP_EOL;
            }
            return;
        });

        return;
    }
}
