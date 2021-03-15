<?php

namespace App\Console\Commands;

use App\Console\Models\CoinModel;
use Mix\Coroutine\Channel;
use Mix\Time\Time;

/**
 * Class LowBuyCommand
 * @package App\Console\Commands
 * @author alex <alexgaozhongze@gmail.com>
 */
class LowBuyCommand
{

    /**
     * 主函数
     */
    public function main()
    {
        $ticker = Time::newTicker(666);
        while (true) {
            $ticker->channel()->pop();

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
    }

    public function handle(Channel $chan, $symbol)
    {
        $chan->push([]);

        $coin = new CoinModel();
        $symbolRes = $coin->get_history_kline($symbol, '1min', 6);
        $symbolList = $symbolRes->data;

        $currentData = reset($symbolList);
        if ($currentData->close != $currentData->low) return false;

        $prevData = next($symbolList);
        $prevMax = $prevData->high;
        $prevMin = $prevData->low;
        foreach ($symbolList as $key => $value) {
            if (!$key) continue;

            $value->low < $prevMin && $prevMin = $value->low;
            $value->high > $prevMax && $prevMax = $value->high;
        }

        $currZf = $currentData->high / $currentData->low;
        $prevZf = $prevMax / $prevMin;
        if ($currZf < $prevZf) return false;

        $redis = context()->get('redis');
        if (!$redis->setnx("buy:symbol:$symbol", null)) {
            $conn = $redis->borrow();
            $conn = null;
            return false;
        }

        $symbolInfo = $redis->hget('symbol', $symbol);
        $symbolInfo = unserialize($symbolInfo);

        list($int, $float) = explode('.', $currentData->close);
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

        echo $symbol, ' ', $price, ' ', $amount, ' ', date('Y-m-d H:i:s', strtotime("+8 hours")), PHP_EOL;
        $buyRes = $coin->place_order($amount, $price, $symbol, 'buy-limit');
        if ('ok' == $buyRes->status) {
            $orderId = $buyRes->data;
            $redis->setex("buy:symbol:$symbol", 666, $price);

            $ticker = Time::newTicker(6 * Time::SECOND);
            $timer = Time::newTimer(666 * Time::SECOND);
            xgo(function () use ($timer, $ticker, $orderId, $coin) {
                $ts = $timer->channel()->pop();
                if (!$ts) return;
                
                $ticker->stop();
                $cancelRes = $coin->cancel_order($orderId);
                echo 'channel', PHP_EOL;
                var_dump($cancelRes);
            });

            xgo(function () use ($ticker, $timer, $coin, $orderId, $symbolInfo, $currentData, $symbol) {
                while (true) {
                    $ts = $ticker->channel()->pop();
                    !$ts && $ticker->stop();
    
                    $order = $coin->get_order($orderId);
                    $orderInfo = $order->data;
    
                    if ('filled' == $orderInfo->state) {    
                        $amount = $orderInfo->{"field-amount"} - $orderInfo->{"field-fees"};
    
                        list($int, $float) = explode('.', $amount);
                        $float = substr($float, 0, $symbolInfo['amount-precision']);
                        $amount = "$int.$float";
    
                        $price = $currentData->high;
                        list($int, $float) = explode('.', $price);
                        $float = substr($float, 0, $symbolInfo['price-precision']);
                        $price = "$int.$float";
    
                        $sellRes = $coin->place_order($amount, $price, $symbol, 'sell-limit');
                        echo 'sell: ' . $sellRes->data, PHP_EOL;
                        $ticker->stop();
                        $timer->stop();
                        return;
                    }
                }
            });
        } else {
            $redis->setex("buy:symbol:$symbol", 333, $price);

            echo $buyRes->{"err-msg"}, PHP_EOL;
        }

        $conn = $redis->borrow();
        $conn = null;
    }

}
