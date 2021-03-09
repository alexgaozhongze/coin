<?php

namespace App\Console\Commands;

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

            $list = [];
            foreach ($symbols as $symbol) {
                $result = $chan->pop();
                $result && $list[] = $result;
            }

            $sort = array_column($list, 'up');
            array_multisort($sort, SORT_ASC, $list);

            if ($list) {
                shellPrint($list);
            }
        }
    }

    public function handle(Channel $chan, $symbol)
    {
        $client = new Client();
        $response = $client->get("https://api.huobi.pro/market/history/kline?period=1min&size=1&symbol=$symbol")->getBody();
        $data = json_decode($response, true);

        $currentData = reset($data['data']);
        if ($currentData['close'] == $currentData['high']) {
            $up = $currentData['close'] / $currentData['open'];
            if (1.03 <= $up) {
                $chan->push([
                    'symbol' => $symbol,
                    'up' => $up
                ]);
            } else {
                $chan->push([]);
            }
        } else {
            $chan->push([]);
        }

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
