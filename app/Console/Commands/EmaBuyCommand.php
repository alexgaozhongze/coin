<?php

namespace App\Console\Commands;

use App\Console\Models\CoinModel;
use Mix\Coroutine\Channel;
use Mix\Time\Time;

/**
 * Class EmaBuyCommand
 * @package App\Console\Commands
 * @author alex <alexgaozhongze@gmail.com>
 */
class EmaBuyCommand
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

            $symbols = ['kncusdt'];

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
        $symbolRes = $coin->get_history_kline($symbol, '1min', 666);
        $symbolList = $symbolRes->data;

        $emaList = [];
        $symbolList = array_reverse($symbolList);
        foreach ($symbolList as $value) {
            $preEma = end($emaList);
            if ($preEma) {
                $emaInfo = [
                    'ema9'  => 2 / (9  + 1) * $value->close + (9  - 1) / (9  + 1) * $preEma['ema9'],
                    'ema36' => 2 / (36 + 1) * $value->close + (36 - 1) / (36 + 1) * $preEma['ema36']
                ];
            } else {
                $emaInfo = [
                    'ema9'  => $value->close,
                    'ema36' => $value->close
                ];
            }
            $emaList[] = $emaInfo;
        }

        var_dump($emaList);





        return;

        $resetData = reset($symbolList);
        if ($resetData->ema3 <= $resetData->ema6 || $resetData->ema6 <= $resetData->ema9 || $resetData->ema9 <= $resetData->ema36) return;
        
        $redis = context()->get('redis');
        if (!$redis->setnx("buy:symbol:$symbol", null)) {
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
        $redis->setex("buy:symbol:$symbol", 1, null);
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
            $redis->setex("buy:symbol:high:$symbol", 333, $price);

            echo $buyRes->{"err-msg"}, PHP_EOL;
        }

        $conn = $redis->borrow();
        $conn = null;
    }

}
