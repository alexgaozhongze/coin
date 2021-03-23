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
        echo $orderId , " start", ' ', date('H:i:s'), PHP_EOL;

        $coin = new CoinModel();

        $order = $coin->get_order($orderId);
        $orderInfo = $order->data;
        $symbol = $orderInfo->symbol;

        $redis = context()->get('redis');

        $symbolInfo = $redis->hget('symbol', $symbol);
        $symbolInfo = unserialize($symbolInfo);

        $conn = $redis->borrow();
        $conn = null;

        $order = $coin->get_order($orderId);
        $orderInfo = $order->data;

        $amount = $orderInfo->{"field-amount"} - $orderInfo->{"field-fees"};

        list($int, $float) = explode('.', $amount);
        $float = substr($float, 0, $symbolInfo['amount-precision']);
        $amount = "$int.$float";

        $price = $symbolInfo['min-order-value'] / $amount * 1.01;
        $mul = 1;
        for ($i = 0; $i < $symbolInfo['price-precision']; $i ++) {
            $mul *= 10;
        }
        $price *= $mul;
        $price = ceil($price);
        $price /= $mul;

        $sellRes = $coin->place_order($amount, $price, $symbol, 'sell-limit');
        $orderId = $sellRes->data;
        echo "sell:limit:$symbol $price " . $sellRes->data, ' ', date('H:i:s', strtotime("+8 hours")), PHP_EOL;

        // $timer = Time::newTimer(999999);
        // xgo(function () use ($timer, $orderId, $coin, $amount, $symbol) {
        //     $timer->channel()->pop();

        //     $order = $coin->get_order($orderId);
        //     $orderInfo = $order->data;
        //     if ('filled' != $orderInfo->state) {
        //         $cancelRes = $coin->cancel_order($orderId);
        //         echo "sell:cancel:$symbol ", $cancelRes->data, ' ', date('H:i:s', strtotime("+8 hours")), PHP_EOL;

        //         $sellRes = $coin->place_order($amount, 0, $symbol, 'sell-market');
        //         echo "sell:market:$symbol " . $sellRes->data, ' ', date('H:i:s', strtotime("+8 hours")), PHP_EOL;
        //     }

        //     return;
        // });
    }

}
