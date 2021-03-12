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
            $symbol = $orderInfo->symbol;

            $sell = false;
            while (!$sell) {
                $client = new Client();
                $response = $client->get("https://api.huobi.pro/market/history/kline?period=1min&size=36&symbol=$symbol")->getBody();
                $symbolRes = json_decode($response, true);
                $symbolList = $symbolRes['data'];
        
                $symbolList = array_reverse($symbolList);
                foreach ($symbolList as $key => $value) {
                    if ($key) {
                        $value['ema3']  = 2 / (3  + 1) * $value['close'] + (3  - 1) / (3  + 1) * $symbolList[$key - 1]['ema3'];
                        $value['ema6']  = 2 / (6  + 1) * $value['close'] + (6  - 1) / (6  + 1) * $symbolList[$key - 1]['ema6'];
                        $value['ema9']  = 2 / (9  + 1) * $value['close'] + (9  - 1) / (9  + 1) * $symbolList[$key - 1]['ema9'];
                        $value['ema36'] = 2 / (36 + 1) * $value['close'] + (36 - 1) / (36 + 1) * $symbolList[$key - 1]['ema36'];
                    } else {
                        $value['ema3'] = $value['close'];
                        $value['ema6'] = $value['close'];
                        $value['ema9'] = $value['close'];
                        $value['ema36'] = $value['close'];
                    }
        
                    $symbolList[$key] = $value;
                }
        
                $currentData = end($symbolList);
                if ($currentData['ema3'] < $currentData['ema6'] && $currentData['ema3'] < $currentData['ema9']) {
                    echo $symbol, ' ', $currentData['close'], ' ', date('Y-m-d H:i:s'), PHP_EOL;

                    $amount = $orderInfo->{"field-amount"} - $orderInfo->{"field-fees"};

                    $redis = context()->get('redis');
                    $symbolInfo = $redis->hget('symbol', $symbol);
                    $symbolInfo = unserialize($symbolInfo);
        
                    list($int, $float) = explode('.', $amount);
                    $float = substr($float, 0, $symbolInfo['amount-precision']);
                    $amount = "$int.$float";
        
                    $sellRes = $coin->place_order($amount, 0, $symbol, 'sell-market');
                    if ('ok' == $sellRes->status) {
                        echo $sellRes->data, PHP_EOL;

                        $redis->del("$symbol:lock");
                        $sell = true;
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
