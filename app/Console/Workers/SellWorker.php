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
        $ticker = Time::newTicker(666);
        xgo(function () use ($ticker, $coin, $orderId) {
            while (true) {
                $ticker->channel()->pop();

                $order = $coin->get_order($orderId);
                $orderInfo = $order->data;
                $symbol = $orderInfo->symbol;
        
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
                prev($emaList);prev($emaList);
                $prevEma = prev($emaList);
                // foreach ($emaList as $value) {
                //     echo $value['ema9'] / $value['ema36'], PHP_EOL;
                // }
                unset($symbolList ,$emaList);

                if ($currentEma['ema9'] / $currentEma['ema36'] < $prevEma['ema9'] / $prevEma['ema36']) {
                    $ticker->stop();
        
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

                    $price = $currentEma['ema6'];
                    $minPrice = $symbolInfo['min-order-value'] / $amount;

                    $price < $minPrice && $price = $minPrice;
                    $mul = 1;
                    for ($i = 0; $i < $symbolInfo['price-precision']; $i ++) {
                        $mul *= 10;
                    }
                    $price *= $mul;
                    $price = ceil($price);
                    $price /= $mul;

                    $sellRes = $coin->place_order($amount, $price, $symbol, 'sell-limit');
                    $orderId = $sellRes->data;
                    echo "sell:limit:$symbol " . $sellRes->data, ' ', date('H:i:s', strtotime("+8 hours")), PHP_EOL;

                    $timer = Time::newTimer(666666);
                    xgo(function () use ($timer, $orderId, $coin, $amount, $symbol) {
                        $timer->channel()->pop();

                        $order = $coin->get_order($orderId);
                        $orderInfo = $order->data;
                        if ('filled' != $orderInfo->state) {
                            $cancelRes = $coin->cancel_order($orderId);
                            echo "sell:cancel:$symbol ", $cancelRes->data, ' ', date('H:i:s', strtotime("+8 hours")), PHP_EOL;

                            $sellRes = $coin->place_order($amount, 0, $symbol, 'sell-market');
                            echo "sell:market:$symbol " . $sellRes->data, ' ', date('H:i:s', strtotime("+8 hours")), PHP_EOL;
                        }
                    });

                    return;
                }
            }
        });
    }

}
