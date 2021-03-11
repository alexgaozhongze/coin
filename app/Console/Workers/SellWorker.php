<?php

namespace App\Console\Workers;

use App\Console\Models\CoinModel;
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
        echo date('Y-m-d H:i:s'), PHP_EOL;
        if ('ok' == $order->status) {
            $orderInfo = $order->data;
            $symbol = $orderInfo->symbol;
            $amount = $orderInfo->{"field-amount"} - $orderInfo->{"field-fees"};
            $createAt = $orderInfo->{"created-at"};

            $sell = false;
            while (!$sell) {
                $kLine = $coin->get_history_kline($symbol, '1min', 2);
                $prevKLine = end($kLine->data);
                $curKLine = reset($kLine->data);
                if ($prevKLine->open > $prevKLine->close && $curKLine->close >= $amount) {
                    $redis = context()->get('redis');
                    $symbolInfo = $redis->hget('symbol', $symbol);
    
                    $conn = $redis->borrow();
                    $conn = null;
    
                    $symbolInfo = unserialize($symbolInfo);
        
                    list($int, $float) = explode('.', $amount);
                    $float = substr($float, 0, $symbolInfo['amount-precision']);
                    $amount = "$int.$float";
        
                    echo 'more', ' ', $symbol, ' ', $curKLine->close, PHP_EOL;
                    $res = $coin->place_order($amount, 0, $symbol, 'sell-market');
                    var_dump($res);
                    $sell = true;
                } elseif (666 <= time() - $createAt / 1000 && $curKLine->close == $curKLine->high) {
                    $redis = context()->get('redis');
                    $symbolInfo = $redis->hget('symbol', $symbol);

                    $conn = $redis->borrow();
                    $conn = null;
    
                    $symbolInfo = unserialize($symbolInfo);

                    list($int, $float) = explode('.', $amount);
                    $float = substr($float, 0, $symbolInfo['amount-precision']);
                    $amount = "$int.$float";

                    echo 'timeout', ' ', $symbol, ' ', $curKLine->close, PHP_EOL;
                    $res = $coin->place_order($amount, 0, $symbol, 'sell-market');
                    var_dump($res);
                    $sell = true;
                }
            }
        } else {
            echo $order->{"err-msg"}, PHP_EOL;
        }
    }

}
