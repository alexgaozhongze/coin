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
        $response = $client->get("https://api.huobi.pro/market/history/kline?period=1min&size=36&symbol=$symbol")->getBody();
        $symbolRes = json_decode($response, true);
        $symbolList = $symbolRes['data'];

        $symbolList = array_reverse($symbolList);
        foreach ($symbolList as $key => $value) {
            if ($key) {
                $value['ema3']  = 2 / (3  + 1) * $value['close'] + (3  - 1) / (3  + 1) * $symbolList[$key - 1]['ema3'];
                $value['ema6']  = 2 / (6  + 1) * $value['close'] + (6  - 1) / (6  + 1) * $symbolList[$key - 1]['ema6'];
                $value['ema9']  = 2 / (9  + 1) * $value['close'] + (9  - 1) / (9  + 1) * $symbolList[$key - 1]['ema9'];
            } else {
                $value['ema3'] = $value['close'];
                $value['ema6'] = $value['close'];
                $value['ema9'] = $value['close'];
            }

            $symbolList[$key] = $value;
        }

        $allUp = true;
        $currentData = end($symbolList);
        for ($i = 0; $i < 6; $i ++) {
            $current = current($symbolList);
            $prev = prev($symbolList);
            if ($current['open'] >= $prev['open'] || $current['close'] >= $prev['close'] || $current['high'] >= $prev['high'] || $current['low'] >= $prev['low']) {
                continue;
            }

            $allUp = false;
        }

        $macdAllUp = false;
        $currentData['ema3'] >= $currentData['ema6'] && $currentData['ema6'] >= $currentData['ema9'] && $macdAllUp = true;

        if ($allUp && $macdAllUp) {
            $redis = context()->get('redis');
            if ($redis->setnx("$symbol:lock", null)) {
                echo $symbol, ' ', $currentData['close'], ' ', date('Y-m-d H:i:s'), PHP_EOL;

                $coin = new CoinModel();
                $buyRes = $coin->place_order(5, 0, $symbol, 'buy-market');
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
