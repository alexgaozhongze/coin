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
        echo $orderId , " start", PHP_EOL;

        $coin = new CoinModel();
        $order = $coin->get_order($orderId);
        while (!$order) {
            sleep(6);
            $order = $coin->get_order($orderId);
        }
        if ('ok' == $order->status) {
            $orderInfo = $order->data;
            while ('filled' != $orderInfo->state) {
                sleep(6);
                $order = $coin->get_order($orderId);
                $orderInfo = $order->data;
            }

            $symbol = $orderInfo->symbol;

            $sell = false;
            while (!$sell) {
                $symbolRes = $coin->get_history_kline($symbol, '1min', 3);
                while (!$symbolRes || 3 != count($symbolRes->data)) {
                    sleep(6);
                    $symbolRes = $coin->get_history_kline($symbol, '1min', 3);
                }
                $symbolList = $symbolRes->data;

                $currentData = reset($symbolList);

                $allDown = true;
                $isMinLow = true;
                foreach ($symbolList as $key => $value) {
                    isset($symbolList[$key + 1]) && $symbolList[$key + 1]->close < $value->close && $allDown = false;
                    $key && $value->low < $currentData->low && $isMinLow = false;
                }

                if ($allDown && $isMinLow) {
                    echo $symbol, ' ', $currentData->close, ' ', date('Y-m-d H:i:s', strtotime("+8 hours")), PHP_EOL;
                    echo "order $orderId", PHP_EOL;

                    $amount = $orderInfo->{"field-amount"} - $orderInfo->{"field-fees"};

                    $redis = context()->get('redis');
                    $symbolInfo = $redis->hget('symbol', $symbol);
                    $symbolInfo = unserialize($symbolInfo);
        
                    list($int, $float) = explode('.', $amount);
                    $float = substr($float, 0, $symbolInfo['amount-precision']);
                    $amount = "$int.$float";

                    $price = $currentData->close;
                    $price < $orderInfo->price * 1.03 && $price = $orderInfo->price * 1.03;
                    list($int, $float) = explode('.', $price);
                    $float = substr($float, 0, $symbolInfo['price-precision']);
                    $price = "$int.$float";
        
                    $sellRes = $coin->place_order($amount, $price, $symbol, 'sell-limit');
                    if ('ok' == $sellRes->status) {
                        echo 'limit', ' ', $sellRes->data, PHP_EOL;

                        $redis->setex("$symbol:lock", 6, null);
                        $sell = true;
                    } elseif ('order-value-min-error' == $sellRes->{"err-code"}) {
                        $sellRes = $coin->place_order($amount, 0, $symbol, 'sell-market');
                        if ('ok' == $sellRes->status) {
                            echo 'market', ' ', $sellRes->data, PHP_EOL;
    
                            $redis->setex("$symbol:lock", 6, null);
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
