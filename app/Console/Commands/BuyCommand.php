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
            $symbols = unserialize($symbols);
    
            $conn = $redis->borrow();
            $conn = null;

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
        $response = $client->get("https://api.huobi.pro/market/history/kline?period=5min&size=1&symbol=$symbol")->getBody();
        $data = json_decode($response, true);

        $currentData = reset($data['data']);
        if ($currentData['close'] == $currentData['high']) {
            $up = $currentData['close'] / $currentData['open'];
            if (1.06 <= $up) {
                echo $symbol, ' ', $currentData['close'], ' ', $up, PHP_EOL;
                $coin = new CoinModel();
                $buyRes = $coin->place_order(5, 0, $symbol, 'buy-market');
                var_dump($buyRes);
                if ('ok' == $buyRes->status) {
                    $orderId = $buyRes->data;
                    $res = $coin->get_order($orderId);
                    if ('ok' == $res['status']) {
                        $amount = $res['data']['field-amount'] - $res['data']['field-fees'];
                        
                        $redis = context()->get('redis');
                        $redis->set("sell:$symbol:$orderId", $amount);
                
                        $conn = $redis->borrow();
                        $conn = null;
                    }
                }
            }
        }

        $chan->push([]);
    }
}
