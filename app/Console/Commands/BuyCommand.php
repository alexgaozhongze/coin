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
        $ticker = Time::newTicker(666);
        while (true) {
            $ticker->channel()->pop();

            $redis = context()->get('redis');
            $symbols = $redis->get('symbol:btc');

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
        $symbolRes = $coin->get_history_kline($symbol, '1min', 36);
        $symbolList = $symbolRes->data;

        $symbolList = array_reverse($symbolList);
        foreach ($symbolList as $key => $value) {
            if ($key) {
                $value->ema3 = 2 / (3 + 1) * $value->close + (3 - 1) / (3 + 1) * $symbolList[$key - 1]->ema3;
                $value->ema6 = 2 / (6 + 1) * $value->close + (6 - 1) / (6 + 1) * $symbolList[$key - 1]->ema6;
                $value->ema9 = 2 / (9 + 1) * $value->close + (9 - 1) / (9 + 1) * $symbolList[$key - 1]->ema9;
            } else {
                $value->ema3 = $value->close;
                $value->ema6 = $value->close;
                $value->ema9 = $value->close;
            }
        }
        $currentData = end($symbolList);
        for ($i = 0; $i < 3; $i ++) {
            $prevData = prev($symbolList);
            if ($prevData->ema3 >= $currentData->ema3) return;
            $currentData = current($symbolList);
        }
        $currentData = end($symbolList);
        if (!($currentData->ema3 > $currentData->ema6 && $currentData->ema3 > $currentData->ema9)) return;
        
        $redis = context()->get('redis');
        if (!$redis->setnx("buy:symbol:test:$symbol", null)) {
            $conn = $redis->borrow();
            $conn = null;
            return;
        }

        $symbolInfo = $redis->hget('symbol', $symbol);
        $symbolInfo = unserialize($symbolInfo);

        list($int, $float) = explode('.', $currentData->ema3);
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
        $redis->setex("buy:symbol:test:$symbol", 36, null);
        return;
        $buyRes = $coin->place_order($amount, $price, $symbol, 'buy-limit');
        if ('ok' == $buyRes->status) {
            $orderId = $buyRes->data;

            $redis->setex("buy:symbol:$symbol", 666, $price);

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
            $redis->setex("buy:symbol:$symbol", 333, $price);

            echo $buyRes->{"err-msg"}, PHP_EOL;
        }

        $conn = $redis->borrow();
        $conn = null;
    }

}
