<?php

namespace App\Console\Workers;

use App\Console\Models\CoinModel;
use GuzzleHttp\Client;
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
        $coin = new CoinModel();
        $order = $coin->get_order($orderId);
        if ('ok' == $order->status) {
            $orderInfo = $order->data;
            while ('filled' != $orderInfo->state) {
                sleep(1);
                $order = $coin->get_order($orderId);
                $orderInfo = $order->data;
            }

            $symbol = $orderInfo->symbol;

            $sell = false;
            while (!$sell) {
                $symbolRes = $coin->get_history_kline($symbol, '1min', 6);
                $symbolList = $symbolRes->data;

                $symbolList = array_reverse($symbolList);
                foreach ($symbolList as $key => $value) {
                    if ($key) {
                        $value->ema3  = 2 / (3  + 1) * $value->close + (3  - 1) / (3  + 1) * $symbolList[$key - 1]->ema3;
                    } else {
                        $value->ema3 = $value->close;
                    }
        
                    $symbolList[$key] = $value;
                }
        
                $currentData = end($symbolList);
                $prevData = prev($symbolList);
                if ($currentData->ema3 < $prevData->ema3) {
                    echo $symbol, ' ', $currentData->close, ' ', date('Y-m-d H:i:s'), PHP_EOL;

                    $amount = $orderInfo->{"field-amount"} - $orderInfo->{"field-fees"};

                    $redis = context()->get('redis');
                    $symbolInfo = $redis->hget('symbol', $symbol);
                    $symbolInfo = unserialize($symbolInfo);
        
                    list($int, $float) = explode('.', $amount);
                    $float = substr($float, 0, $symbolInfo['amount-precision']);
                    $amount = "$int.$float";

                    list($int, $float) = explode('.', $currentData->ema3);
                    $float = substr($float, 0, $symbolInfo['price-precision']);
                    $price = "$int.$float";
        
                    $sellRes = $coin->place_order($amount, $price, $symbol, 'sell-limit');
                    if ('ok' == $sellRes->status) {
                        echo 'limit', ' ', $sellRes->data, PHP_EOL;

                        $redis->del("$symbol:lock");
                        $sell = true;
                    } elseif ('order-value-min-error' == $sellRes->{"err-code"}) {
                        $sellRes = $coin->place_order($amount, 0, $symbol, 'sell-market');
                        if ('ok' == $sellRes->status) {
                            echo 'market', ' ', $sellRes->data, PHP_EOL;
    
                            $redis->del("$symbol:lock");
                            $sell = true;
                        } else {
                            echo $sellRes->{"err-msg"}, PHP_EOL;
                        }
                    } else {
                        echo $sellRes->{"err-msg"}, PHP_EOL;
                    }
    
                    $conn = $redis->borrow();
                    $conn = null;
                }
            }
        }
    }

}
