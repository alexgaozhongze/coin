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
        $redis = context()->get('redis');
        $redis->del('symbol:eth');
        $redis->del('symbol:usdt');

        $client = new Client();
        $response = $client->get("https://api.huobi.pro/market/tickers")->getBody();
        
        $data = json_decode($response, true);
        foreach ($data['data'] as $value) {
            if ($value['count']) {
                'eth' == substr($value['symbol'], -3, 3) && $redis->sadd('symbol:eth', $value['symbol']);
                'usdt' == substr($value['symbol'], -4, 4) && $redis->sadd('symbol:usdt', $value['symbol']);
            }
        }
        
        $conn = $redis->borrow();
        $conn = null;
    }

}
