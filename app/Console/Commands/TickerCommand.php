<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;

/**
 * Class TickerCommand
 * @package App\Console\Commands
 * @author alex <alexgaozhongze@gmail.com>
 */
class TickerCommand
{

    /**
     * 主函数
     */
    public function main()
    {
        $client = new Client();
        $response = $client->get("https://api.huobi.pro/market/tickers")->getBody();

        $usdt = [];
        $data = json_decode($response, true);
        foreach ($data['data'] as $value) {
            if (!$value['high'] || !$value['low']) continue;
            if (1.09 > $value['high'] / $value['low']) continue;
            if (!$value['count']) continue;

            'usdt' == substr($value['symbol'], -4, 4) && $usdt[] = [
                'symbol' => $value['symbol'],
                'up' => $value['close'] / $value['open']
            ];
        }

        $sort = array_column($usdt, 'up');
        array_multisort($sort, SORT_DESC, $usdt);

        $usdt = array_slice($usdt, 0, 9);
        $symbols = array_column($usdt, 'symbol');

        $redis = context()->get('redis');
        $redis->set('symbol:usdt', serialize($symbols));
        
        $conn = $redis->borrow();
        $conn = null;
    }

}
