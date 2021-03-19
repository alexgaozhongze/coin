<?php

namespace App\Console\Commands;

use App\Console\Models\CoinModel;
use Mix\Coroutine\Channel;
use Mix\Signal\SignalNotify;
use Mix\Time\Time;

/**
 * Class BuyCommand
 * @package App\Console\Commands
 * @author alex <alexgaozhongze@gmail.com>
 */
class BuyCommand
{

    /**
     * 主函数
     */
    public function main()
    {
        $notify = new SignalNotify(SIGHUP, SIGINT, SIGTERM);

        $ticker = Time::newTicker(666);

        xgo(function () use ($notify, &$ticker) {
            $notify->channel()->pop();
            $ticker->stop();
            $notify->stop();
            return;
        });

        xgo(function () use (&$ticker) {
            while (true) {
                $ts = $ticker->channel()->pop();
                if (!$ts) return;
    
                $redis = context()->get('redis');
                $symbols = $redis->get('symbol:usdt');
    
                $conn = $redis->borrow();
                $conn = null;
    
                $symbols = unserialize($symbols);
    
                $chan = new Channel();
                foreach ($symbols as $symbol) {
                    xgo([$this, 'handle'], $chan, $symbol);
                }
        
                foreach ($symbols as $symbol) {
                    $chan->pop(6);
                }
            }
        });
    }

    public function handle(Channel $chan, $symbol)
    {
        $coin = new CoinModel();
        $symbolRes = $coin->get_history_kline($symbol, '1min', 63);
        $symbolList = $symbolRes->data;

        $emaList = [];
        $symbolList = array_reverse($symbolList);
        foreach ($symbolList as $value) {
            $preEma = end($emaList);
            if ($preEma) {
                $emaInfo = [
                    'ema6'  => 2 / (6  + 1) * $value->close + (6  - 1) / (6  + 1) * $preEma['ema6'],
                    'ema9'  => 2 / (9  + 1) * $value->close + (9  - 1) / (9  + 1) * $preEma['ema9'],
                    'ema36' => 2 / (36 + 1) * $value->close + (36 - 1) / (36 + 1) * $preEma['ema36']
                ];
            } else {
                $emaInfo = [
                    'ema6'  => $value->close,
                    'ema9'  => $value->close,
                    'ema36' => $value->close
                ];
            }
            $emaList[] = $emaInfo;
        }

        $currentEma = end($emaList);
        for ($i = 0; $i < 6; $i ++) {
            $prevEma = prev($emaList);
            if ($currentEma['ema9'] / $currentEma['ema36'] < $prevEma['ema9'] / $prevEma['ema36']) return;

            $currentEma = current($emaList);
        }
        $currentEma = end($emaList);

        unset($symbolRes, $symbolList, $emaList);

        $redis = context()->get('redis');
        if (!$redis->setnx("buy:symbol:$symbol", null)) {
            $conn = $redis->borrow();
            $conn = null;
            return;
        }

        $symbolInfo = $redis->hget('symbol', $symbol);
        $symbolInfo = unserialize($symbolInfo);

        list($int, $float) = explode('.', $currentEma['ema6']);
        $float = substr($float, 0, $symbolInfo['price-precision']);
        $price = "$int.$float";

        $amount = $symbolInfo['min-order-value'] / $price;
        $mul = 1;
        for ($i = 0; $i < $symbolInfo['amount-precision']; $i ++) {
            $mul *= 10;
        }
        $amount *= $mul;
        $amount = ceil($amount);
        $amount /= $mul;

        echo 'buy: ', $symbol, ' ', $price, ' ', $amount, ' ', date('H:i:s', strtotime("+8 hours")), PHP_EOL;
        $redis->setex("buy:symbol:$symbol", 666, $price);
        $buyRes = $coin->place_order($amount, $price, $symbol, 'buy-limit');
        if ('ok' == $buyRes->status) {
            echo 'buy: ', $symbol, ' ', $buyRes->data, ' ', date('H:i:s', strtotime("+8 hours")), PHP_EOL;
            $orderId = $buyRes->data;

            $ticker = Time::newTicker(666);
            $timer = Time::newTimer(180000);
            xgo(function () use ($timer, $ticker, $orderId, $coin, $symbol) {
                $ts = $timer->channel()->pop();
                if (!$ts) return;
                
                $ticker->stop();
                $cancelRes = $coin->cancel_order($orderId);
                echo 'cancel: ', $symbol, ' ', $cancelRes->data, PHP_EOL;
            });

            xgo(function () use ($ticker, $timer, $coin, $orderId, $symbolInfo, $currentEma, $symbol) {
                while (true) {
                    $ts = $ticker->channel()->pop();
                    !$ts && $ticker->stop();
    
                    $order = $coin->get_order($orderId);
                    $orderInfo = $order->data;
    
                    if ('filled' == $orderInfo->state) {    
                        $redis = context()->get('redis');
                        $redis->lpush("buy:order", $orderId);
        
                        $conn = $redis->borrow();
                        $conn = null;

                        $timer->stop();
                        return;
                    }
                }
            });
        } else {
            $redis->setex("buy:symbol:high:$symbol", 66, $price);

            echo $buyRes->{"err-msg"}, PHP_EOL;
        }

        $conn = $redis->borrow();
        $conn = null;

        $chan->push([]);
    }

}
