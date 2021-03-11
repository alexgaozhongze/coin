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
        $response = $client->get("https://api.huobi.pro/market/history/kline?period=1min&size=3&symbol=$symbol")->getBody();
        $data = json_decode($response, true);

        $currentData = reset($data['data']);
        if ($currentData['close'] == $currentData['high']) {
            $low = $currentData['open'];
            foreach ($data['data'] as $value) {
                $low >= $value['open'] && $low = $value['open'];
                $low >= $value['close'] && $low = $value['close'];
            }

            $up = $currentData['close'] / $low;
            if (1.03 <= $up) {
                echo date('Y-m-d H:i:s'), PHP_EOL;
                echo $symbol, ' ', $low, ' ', $currentData['close'], ' ', $up, PHP_EOL;
                $coin = new CoinModel();
                $buyRes = $coin->place_order(5, 0, $symbol, 'buy-market');
                if ('ok' == $buyRes->status) {
                    $orderId = $buyRes->data;

                    $redis = context()->get('redis');
                    $redis->lpush("buy:order", $orderId);
            
                    $conn = $redis->borrow();
                    $conn = null;

                    echo $buyRes->data, PHP_EOL;
                } else {
                    echo $buyRes->{"err-msg"}, PHP_EOL;
                }
            }
        }

        $chan->push([]);
    }
}
