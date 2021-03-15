<?php

namespace App\Console\Commands;

use App\Console\Models\CoinModel;
use Mix\Coroutine\Channel;
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
        while (true) {
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
        $coin = new CoinModel();
        $symbolRes = $coin->get_history_kline($symbol, '1min', 9);
        $symbolList = $symbolRes->data;

        $allHasUp = true;
        foreach ($symbolList as $key => $value) {
            if (!isset($symbolList[$key + 1])) break;
            $prev = $symbolList[$key + 1];
            $hasUp = false;
            if ($value->close >= $prev->low) {
                $hasUp = true;
            }
            !$hasUp && $allHasUp = false;
        }

        $currentData = reset($symbolList);
        
        if ($allHasUp) {
            $redis = context()->get('redis');
            if ($redis->setnx("$symbol:lock", null)) {
                echo $symbol, ' ';

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

                echo $price, ' ', $amount, ' ', date('Y-m-d H:i:s', strtotime("+8 hours")), PHP_EOL;

                $coin = new CoinModel();
                $buyRes = $coin->place_order($amount, $price, $symbol, 'buy-limit');
                if ('ok' == $buyRes->status) {
                    $orderId = $buyRes->data;

                    $redis->setex("$symbol:lock", 666, $price);

                    $timer = Time::newTimer(63 * Time::SECOND);
                    xgo(function () use ($timer, $orderId) {
                        $timer->channel()->pop();

                        $redis = context()->get('redis');
                        $redis->lpush("buy:order", $orderId);

                        $conn = $redis->borrow();
                        $conn = null;
                    });

                    echo $buyRes->data, PHP_EOL;
                } else {
                    $redis->setex("$symbol:lock", 333, $price);

                    echo $buyRes->{"err-msg"}, PHP_EOL;
                }
            }
            $conn = $redis->borrow();
            $conn = null;
        }

        $chan->push([]);
    }

}
