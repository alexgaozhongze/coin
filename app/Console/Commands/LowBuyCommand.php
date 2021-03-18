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
        $symbolRes = $coin->get_history_kline($symbol, '1min', 63);
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

        $currentEma = end($emaList);
        for ($i = 0; $i < 6; $i ++) {
            $prevEma = prev($emaList);
            if ($currentEma['ema9'] <= $currentEma['ema36']) return;
            if ($currentEma['ema9'] <= $prevEma['ema9']) return;

            $currentEma = current($emaList);
        }
        $currentEma = end($emaList);

        $redis = context()->get('redis');
        if (!$redis->setnx("buy:symbol:$symbol", null)) {
            $conn = $redis->borrow();
            $conn = null;
            return false;
        }

        $symbolInfo = $redis->hget('symbol', $symbol);
        $symbolInfo = unserialize($symbolInfo);

        list($int, $float) = explode('.', $currentEma['ema9']);
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

        echo $symbol, ' ', number_format($price, 10, '.', ''), ' ', $amount, ' ', date('Y-m-d H:i:s', strtotime("+8 hours")), PHP_EOL;
        $buyRes = $coin->place_order($amount, $price, $symbol, 'buy-limit');
        if ('ok' == $buyRes->status) {
            $orderId = $buyRes->data;
            $redis->setex("buy:symbol:$symbol", 666, $price);

            $ticker = Time::newTicker(6 * Time::SECOND);
            $timer = Time::newTimer(180 * Time::SECOND);
            xgo(function () use ($timer, $ticker, $orderId, $coin) {
                $ts = $timer->channel()->pop();
                if (!$ts) return;
                
                $ticker->stop();
                $cancelRes = $coin->cancel_order($orderId);
                echo 'cancel: ', $cancelRes->data, PHP_EOL;
            });

            xgo(function () use ($ticker, $timer, $coin, $orderId, $symbolInfo, $currentEma, $symbol) {
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
    
                        $price = $currentEma['ema9'] * 1.03;
                        list($int, $float) = explode('.', $price);
                        $float = substr($float, 0, $symbolInfo['price-precision']);
                        $price = "$int.$float";
    
                        $sellRes = $coin->place_order($amount, $price, $symbol, 'sell-limit');
                        $orderId = $sellRes->data;
                        echo 'sell: ' . $sellRes->data, PHP_EOL;
                        $ticker->stop();
                        $timer->stop();
                        
                        $timer = Time::newTimer(360 * Time::SECOND);
                        xgo(function () use ($timer, $orderId, $coin, $amount, $symbol) {
                            $timer->channel()->pop();

                            $order = $coin->get_order($orderId);
                            $orderInfo = $order->data;
                            var_dump($orderInfo);
                            if ('filled' != $orderInfo->state) {
                                $cancelRes = $coin->cancel_order($orderId);
                                var_dump($cancelRes);

                                // echo 'cancel:sell: ', $cancelRes->data, PHP_EOL;

                                $sellRes = $coin->place_order($amount, 0, $symbol, 'sell-market');
                                var_dump($sellRes);
                                // $orderId = $sellRes->data;
                                // echo 'sell: ' . $sellRes->data, PHP_EOL;
                            }
                        });

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
