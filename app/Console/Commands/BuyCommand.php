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
        $symbolRes = $coin->get_history_kline($symbol, '1min', 9);
        $symbolList = $symbolRes->data;

        $beforeGroup = array_slice($symbolList, -6, 6);
        $nowGroup = array_slice($symbolList, 0, 3);

        $current = reset($nowGroup);
        $beforeFirst = end($beforeGroup);
        $beforeEnd = reset($beforeGroup);
        $nowFirst = end($nowGroup);

        $beforeFirstHighest = true;
        foreach ($beforeGroup as $value) {
            $value->high > $beforeFirst->high && $beforeFirstHighest = false;
        }
        if (!$beforeFirstHighest) return;

        $currentHighest = true;
        foreach ($nowGroup as $value) {
            $value->high > $current->high && $currentHighest = false;
        }
        if (!$currentHighest) return;

        $beforeZf = $beforeFirst->open / $beforeEnd->close;
        if (1.09 > $beforeZf) return;

        $currZf = $current->close / $nowFirst->open;
        if (1.03 > $currZf) return;

        $redis = context()->get('redis');
        if (!$redis->setnx("buy:symbol:$symbol", null)) {
            $conn = $redis->borrow();
            $conn = null;
            return;
        }

        $symbolInfo = $redis->hget('symbol', $symbol);
        $symbolInfo = unserialize($symbolInfo);

        list($int, $float) = explode('.', $current->close);
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
