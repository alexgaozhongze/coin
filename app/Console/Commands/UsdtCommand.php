<?php

namespace App\Console\Commands;

use App\Console\Models\CoinModel;
use Mix\Coroutine\Channel;
use Mix\Signal\SignalNotify;
use Mix\Time\Time;

/**
 * Class UsdtCommand
 * @package App\Console\Commands
 * @author alex <alexgaozhongze@gmail.com>
 */
class UsdtCommand
{

    /**
     * 主函数
     */
    public function main()
    {
        $notify = new SignalNotify(SIGHUP, SIGINT, SIGTERM);

        $ticker = Time::newTicker(3 * Time::SECOND);

        xgo(function () use ($notify, $ticker) {
            $notify->channel()->pop();
            $ticker->stop();
            $notify->stop();
            return;
        });

        xgo(function () use ($ticker) {
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
        $klineRes = $coin->get_history_kline($symbol, '1min', 63);
        $klineList = $klineRes->data;

        $emaList = [];
        $klineList = array_reverse($klineList);
        foreach ($klineList as $value) {
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

        $currentKline = end($klineList);
        $currentEma = end($emaList);
        if (1.01 > $currentEma['ema3'] / $currentKline->close) goto chanPush;
        unset($klineRes, $klineList, $emaList);

        $redis = context()->get('redis');
        if (!$redis->setnx("buy:symbol:$symbol", null)) {
            $conn = $redis->borrow();
            $conn = null;
            goto chanPush;
        }

        $symbolInfo = $redis->hget('symbol', $symbol);
        $symbolInfo = unserialize($symbolInfo);

        $buyPrice = $currentKline->close;
        $sellPrice = $currentKline->high;
        list($int, $float) = explode('.', $buyPrice);
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
        $redis->setex("buy:symbol:$symbol", 36, $price);
        $buyRes = $coin->place_order($amount, $price, $symbol, 'buy-limit');
        if ('ok' == $buyRes->status) {
            echo 'buy: ', $symbol, ' ', $buyRes->data, ' ', date('H:i:s', strtotime("+8 hours")), PHP_EOL;
            $orderId = $buyRes->data;

            $ticker = Time::newTicker(666);
            $timer = Time::newTimer(3666);
            xgo(function () use ($timer, $ticker, $orderId, $coin, $symbol) {
                $ts = $timer->channel()->pop();
                if (!$ts) return;
                
                $redis = context()->get('redis');
                $redis->setex("buy:symbol:$symbol", 36, null);

                $conn = $redis->borrow();
                $conn = null;

                $ticker->stop();
                $timer->stop();
                $cancelRes = $coin->cancel_order($orderId);
                echo 'cancel: ', $symbol, ' ', $cancelRes->data, PHP_EOL;
                return;
            });

            xgo(function () use ($ticker, $timer, $coin, $orderId, $symbolInfo, $symbol, $sellPrice) {
                while (true) {
                    $ts = $ticker->channel()->pop();
                    if (!$ts) return;
    
                    $order = $coin->get_order($orderId);
                    $orderInfo = $order->data;
    
                    if ('filled' == $orderInfo->state) {   
                        $amount = $orderInfo->{"field-amount"} - $orderInfo->{"field-fees"};

                        list($int, $float) = explode('.', $amount);
                        $float = substr($float, 0, $symbolInfo['amount-precision']);
                        $amount = "$int.$float";
                
                        $mul = 1;
                        for ($i = 0; $i < $symbolInfo['price-precision']; $i ++) {
                            $mul *= 10;
                        }
                        $sellPrice *= $mul;
                        $sellPrice = ceil($sellPrice);
                        $sellPrice /= $mul;
                
                        $sellRes = $coin->place_order($amount, $sellPrice, $symbol, 'sell-limit');
                        $orderId = $sellRes->data;

                        echo 'sell: ', $symbol, ' ', $orderId, ' ', date('H:i:s', strtotime("+8 hours")), PHP_EOL;
                        // $redis = context()->get('redis');
                        // $redis->lpush("buy:order", $orderId);
        
                        // $conn = $redis->borrow();
                        // $conn = null;

                        $timer->stop();
                        $ticker->stop();
                        return;
                    }
                }
                return;
            });
        } else {
            $redis->setex("buy:symbol:$symbol", 36, $price);

            echo $buyRes->{"err-msg"}, PHP_EOL;
        }

        $conn = $redis->borrow();
        $conn = null;

        chanPush:
        $chan->push([]);
    }
}
