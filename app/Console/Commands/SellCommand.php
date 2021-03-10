<?php

namespace App\Console\Commands;

use App\Console\Models\CoinModel;
use GuzzleHttp\Client;
use Mix\Coroutine\Channel;

/**
 * Class SellCommand
 * @package App\Console\Commands
 * @author alex <alexgaozhongze@gmail.com>
 */
class SellCommand
{
    /**
     * 主函数
     */
    public function main()
    {
        while (true) {
            $redis = context()->get('redis');
            $sells = $redis->keys('sell*');
    
            $conn = $redis->borrow();
            $conn = null;

            $chan = new Channel();
            foreach ($sells as $sellKey) {
                xgo([$this, 'handle'], $chan, $sellKey);
            }
    
            foreach ($sells as $sellKey) {
                $chan->pop(6);
            }
        }
    }

    public function handle(Channel $chan, $sellKey)
    {
        list($sell, $symbol, $orderId) = explode(':', $sellKey);

        $redis = context()->get('redis');
        $amount = $redis->get($sellKey);

        $client = new Client();
        $response = $client->get("https://api.huobi.pro/market/history/kline?period=5min&size=1&symbol=$symbol")->getBody();
        $data = json_decode($response, true);

        $currentData = reset($data['data']);
        $down = $currentData['high'] / $currentData['close'];
        if (1.03 <= $down) {
            $symbolInfo = $redis->hget('symbol', $symbol);
            $symbolInfo = unserialize($symbolInfo);

            list($int, $float) = explode('.', $amount);
            $float = substr($float, 0, $symbolInfo['amount-precision']);
            $amount = "$int.$float";

            echo $symbol, ' ', $currentData['close'], ' ', $down, PHP_EOL;
            $coin = new CoinModel();
            $res = $coin->place_order($amount, 0, $symbol, 'sell-market');
            var_dump($res);
            if ('ok' == $res->status) {
                $redis->del($sellKey);
            }
        }

        $conn = $redis->borrow();
        $conn = null;

        $chan->push([]);
    }
}
