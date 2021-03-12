<?php

namespace App\Console\Commands;

use App\Console\Models\CoinModel;
use GuzzleHttp\Client;
use Mix\Coroutine\Channel;

/**
 * Class BuyCommand
 * @package App\Console\Commands
 * @author alex <alexgaozhongze@gmail.com>
 */
class BuyCommand
{

    /**
     * 主函数
     */
    public function main()
    {
        while (true) {
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
        $client = new Client();
        $response = $client->get("https://api.huobi.pro/market/history/kline?period=1min&size=6&symbol=$symbol")->getBody();
        $symbolRes = json_decode($response, true);
        $symbolList = $symbolRes['data'];

        $symbolList = array_reverse($symbolList);
        foreach ($symbolList as $key => $value) {
            if ($key) {
                $value['ema3']  = 2 / (3  + 1) * $value['close'] + (3  - 1) / (3  + 1) * $symbolList[$key - 1]['ema3'];
            } else {
                $value['ema3'] = $value['close'];
            }

            $symbolList[$key] = $value;
        }

        $currentData = end($symbolList);
        $prevData = prev($symbolList);
        if (1.01 <= $currentData['ema3'] / $prevData['ema3']) {
            $redis = context()->get('redis');
            if ($redis->setnx("$symbol:lock", null)) {
                echo $symbol, ' ';

                $symbolInfo = $redis->hget('symbol', $symbol);
                $symbolInfo = unserialize($symbolInfo);

                list($int, $float) = explode('.', $currentData['ema3']);
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

                echo $price, ' ', $amount, ' ', date('Y-m-d H:i:s', strtotime("+8 hours")), PHP_EOL;

                $coin = new CoinModel();
                $buyRes = $coin->place_order($amount, $price, $symbol, 'buy-limit');
                if ('ok' == $buyRes->status) {
                    $orderId = $buyRes->data;

                    $redis->lpush("buy:order", $orderId);
                    $redis->setex("$symbol:lock", 666, null);

                    echo $buyRes->data, PHP_EOL;
                } else {
                    $redis->setex("$symbol:lock", 333, null);

                    echo $buyRes->{"err-msg"}, PHP_EOL;
                }
            }
            $conn = $redis->borrow();
            $conn = null;
        }

        $chan->push([]);
    }

}
