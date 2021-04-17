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
        sleep(36);

        $client = new Client();
        $response = $client->get("https://api.huobi.pro/market/tickers")->getBody();

        $btc = [];
        $usdt = [];
        $data = json_decode($response, true);
        foreach ($data['data'] as $value) {
            if (!$value['high'] || !$value['low']) continue;
            if (1.11 > $value['high'] / $value['low']) continue;
            if (!$value['count']) continue;

            $pattern = '/.*?([\d])[l|s].*?$/';
            preg_match($pattern, $value['symbol'], $matches);
            if ($matches) continue;

            'usdt' == substr($value['symbol'], -4, 4) && $usdt[] = [
                'symbol' => $value['symbol'],
                'up' => $value['close'] / $value['open']
            ];

            'btc' == substr($value['symbol'], -3, 3) && $btc[] = [
                'symbol' => $value['symbol'],
                'up' => $value['close'] / $value['open']
            ];
        }

        $sort = array_column($btc, 'up');
        array_multisort($sort, SORT_DESC, $btc);
        $btc = array_slice($btc, 0, 6);
        $btcSymbols = array_column($btc, 'symbol');

        $sort = array_column($usdt, 'up');
        array_multisort($sort, SORT_DESC, $usdt);
        // $usdt = array_slice($usdt, 0, 6);
        $usdtSymbols = array_column($usdt, 'symbol');

        $redis = context()->get('redis');
        $redis->set('symbol:btc', serialize($btcSymbols));
        $redis->set('symbol:usdt', serialize($usdtSymbols));

        $conn = $redis->borrow();
        $conn = null;
    }

}
