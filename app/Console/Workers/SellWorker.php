<?php

namespace App\Console\Workers;

use App\Console\Models\CoinModel;
use Mix\Time\Time;
use Mix\WorkerPool\AbstractWorker;

/**
 * Class SellWorker
 * @package App\Console\Workers
 */
class SellWorker extends AbstractWorker
{

    /**
     * FooWorker constructor.
     */
    public function __construct()
    {
        // 实例化一些需重用的对象
        // ...
    }

    /**
     * 处理
     * @param $data
     */
    public function do($orderId)
    {
        echo $orderId , " start", PHP_EOL;

        $coin = new CoinModel();
        $ticker = Time::newTicker(666);
        xgo(function () use ($ticker, $coin, $orderId) {
            while (true) {
                $ticker->channel()->pop();

                $order = $coin->get_order($orderId);
                $orderInfo = $order->data;
                $symbol = $orderInfo->symbol;
                $minPrice = $orderInfo->price * 1.03;
        
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
                $prevEma = prev($emaList);
                if ($currentEma['ema9'] / $currentEma['ema36'] < $prevEma['ema9'] / $prevEma['ema36']) {
                    $tickerSell = Time::newTicker(666);
                    $timerSell = Time::newTimer(666666);
                    xgo(function () use ($timerSell, $tickerSell, $orderId, $coin) {
                        $ts = $timerSell->channel()->pop();
                        if (!$ts) return;
                        
                        $tickerSell->stop();
                        $cancelRes = $coin->cancel_order($orderId);
                        echo 'cancel: ', $cancelRes->data, PHP_EOL;
                    });
        
                    xgo(function () use ($tickerSell, $timerSell, $coin, $orderId, $currentEma, $symbol, $minPrice) {
                        $redis = context()->get('redis');

                        $symbolInfo = $redis->hget('symbol', $symbol);
                        $symbolInfo = unserialize($symbolInfo);

                        $conn = $redis->borrow();
                        $conn = null;

                        while (true) {
                            $ts = $tickerSell->channel()->pop();
                            !$ts && $tickerSell->stop();
            
                            $order = $coin->get_order($orderId);
                            $orderInfo = $order->data;
            
                            $amount = $orderInfo->{"field-amount"} - $orderInfo->{"field-fees"};
        
                            list($int, $float) = explode('.', $amount);
                            $float = substr($float, 0, $symbolInfo['amount-precision']);
                            $amount = "$int.$float";
        
                            $price = $currentEma['ema9'];
                            $price < $minPrice && $price = $minPrice;
                            list($int, $float) = explode('.', $price);
                            $float = substr($float, 0, $symbolInfo['price-precision']);
                            $price = "$int.$float";
        
                            $sellRes = $coin->place_order($amount, $price, $symbol, 'sell-limit');
                            $orderId = $sellRes->data;
                            echo 'sell: ' . $sellRes->data, PHP_EOL;
                            $tickerSell->stop();
                            $timerSell->stop();
                            
                            $timerSell = Time::newTimer(666666);
                            xgo(function () use ($timerSell, $orderId, $coin, $amount, $symbol) {
                                $timerSell->channel()->pop();
    
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
                    });

                    $ticker->stop();
                    return;
                }
            }
        });
    }

}
