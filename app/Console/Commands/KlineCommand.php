<?php

namespace App\Console\Commands;

use App\Console\Models\CoinModel;
use GuzzleHttp\Client;
use Mix\Coroutine\Channel;

/**
 * Class KlineCommand
 * @package App\Console\Commands
 * @author alex <alexgaozhongze@gmail.com>
 */
class KlineCommand
{

    private $formatColumns = [
        'open', 'close', 'low', 'high'
    ];

    /**
     * 主函数
     */
    public function main()
    {
        $redis = context()->get('redis');
        $symbols = $redis->smembers('symbol:usdt');

        $conn = $redis->borrow();
        $conn = null;

        while (true) {
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
                $res = $coin->place_order(5, 0, $symbol, 'buy-market');
                var_dump($res);
                if ('ok' == $res->status) {
                    $res = $coin->get_order($res->data);
                    var_dump($res);
                }
            }
        }

        $chan->push([]);
    }

    private function formatData($response)
    {
        $data = json_decode($response, true);
        foreach ($data['data'] as $key => $value) {
            foreach ($value as $lKey => $lValue) {
                in_array($lKey, $this->formatColumns) && $value[$lKey] = number_format($lValue, 10, '.', '');
            }
            $data['data'][$key] = $value;
        }

        return $data;
    }

}
