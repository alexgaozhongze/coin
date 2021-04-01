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

        $ticker = Time::newTicker(999);

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

                // $symbols = ['stnusdt'];
                // $symbols = ['insurusdt'];

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

        xgo(function () use ($symbol, $klineList) {
            $klineList = array_reverse($klineList);
            foreach ($klineList as $key => $value) {
                if ($key) {
                    $preEma = $klineList[$key - 1];
                } else {
                    $preEma = (object) [
                        'ema3'  => $value->close,
                        'ema6'  => $value->close,
                        'ema9'  => $value->close,
                        'ema36' => $value->close
                    ];
                }
    
                $value->ema3  = 2 / (3  + 1) * $value->close + (3  - 1) / (3  + 1) * $preEma->ema3;
                $value->ema6  = 2 / (6  + 1) * $value->close + (6  - 1) / (6  + 1) * $preEma->ema6;
                $value->ema9  = 2 / (9  + 1) * $value->close + (9  - 1) / (9  + 1) * $preEma->ema9;
                $value->ema36 = 2 / (36 + 1) * $value->close + (36 - 1) / (36 + 1) * $preEma->ema36;
    
                $klineList[$key] = $value;
            }

            xgo([$this, 'buy'], $symbol, $klineList);
            unset($klineList);
        });
        
        $chan->push([]);
    }

    public function buy($symbol, $klineList)
    {
        $klineCount = count($klineList);
        if (6 >= $klineCount) {
            $redis = context()->get('redis');
            if (!$redis->setnx("buy:symbol:$symbol", null)) {
                $conn = $redis->borrow();
                $conn = null;
                return;
            }
            $redis->expire("buy:symbol:$symbol", 666666);
            $conn = $redis->borrow();
            $conn = null;

            $coin = new CoinModel();
            $buyRes = $coin->place_order(12, 0, $symbol, 'buy-market');
            echo "buy:new:$symbol " . $buyRes->data, ' ', date('H:i:s', strtotime("+8 hours")), PHP_EOL;

        } elseif (63 == $klineCount) {
            $triggerNextId = 0;
            $triggerKline = (object) [];
            foreach ($klineList as $key => $kline) {
                $str = '';
                if ($key && 1.02 <= $klineList[$key - 1]->ema3 / $kline->low) {
                    if (!isset($klineList[$key + 1])) continue;
                    $triggerNextId = $klineList[$key + 1]->id;
                    $triggerKline = $kline;
                }
            }

            $currentKline = end($klineList);
            if ($triggerNextId != $currentKline->id || $triggerKline->low <= $currentKline->close) return;

            echo $symbol, PHP_EOL;
        }

        return;

        if (1.02 < $value->ema3 / $value->low) {
            $str = date('H:i:s', $value->id + 3600 * 8);
            echo $str, PHP_EOL;
            $kline = $value;
            $nextKey = $key;
            do {
                $nextKey ++;
                if (!isset($klineList[$nextKey])) break;

                $kline = $klineList[$nextKey];
                if ($value->close >= $kline->open) continue;

                $str .= ' ' . date('H:i:s', $kline->id + 3600 * 8);

                echo $str, PHP_EOL;
                break;
            } while (true);


            // var_dump($value);
        }
        die;
        var_dump($klineList);die;

        $currentKline = end($klineList);
        $currentEma = end($emaList);
        // if (1.01 > $currentEma['ema3'] / $currentKline->close) goto chanPush;
        unset($klineRes, $klineList, $emaList);

        $redis = context()->get('redis');
        if (!$redis->setnx("buy:symbol:$symbol", null)) {
            $conn = $redis->borrow();
            $conn = null;
            // goto chanPush;
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


    }

}
