<?php

namespace App\Console\Commands;

use App\Console\Models\CoinModel;
use GuzzleHttp\Client;

/**
 * Class GobeyondCommand
 * @package App\Console\Commands
 * @author alex <alexgaozhongze@gmail.com>
 */
class GobeyondCommand
{

    /**
     * 主函数
     */
    public function main()
    {
        $coin = new CoinModel();
        $balance = $coin->get_balance();
        foreach ($balance->data->list as $value) {
            if ('usdt' == $value->currency && 'trade' == $value->type) {
                var_dump($value);
            }
        }
        // $balance = array_slice($balance, 3);
        // var_dump($balance->data->list);die;
        die;

        $client = new Client();
        $response = $client->get("https://api.huobi.pro/market/tickers")->getBody();
        $data = json_decode($response, true);

        $usdt = [];
        foreach ($data['data'] as $value) {
            $pattern = '/.*?([\d])[l|s].*?$/';
            preg_match($pattern, $value['symbol'], $matches);
            if ($matches) continue;

            'usdt' == substr($value['symbol'], -4, 4) && $usdt[] = array_merge($value, [
                'up' => $value['close'] / $value['open']
            ]);
        }

        $sort = array_column($usdt, 'up');
        array_multisort($sort, SORT_DESC, $usdt);
        $usdt = array_slice($usdt, 0, 1);

        $coin = new CoinModel();
        foreach ($usdt as $value) {
            $klineRes = $coin->get_history_kline($value['symbol'], '1min', 63);
            $klineList = $klineRes->data;
            foreach ($klineList as $key => $kValue) {
                unset($kValue->amount, $kValue->vol);
                $kValue->up = bcdiv($kValue->close, $kValue->open, 3);
                $kValue->zf = bcdiv($kValue->high, $kValue->low, 3);
                $klineList[$key] = (array) $kValue;
            }
            $klineList = array_reverse($klineList);
            shellPrint($klineList);
        }
    }

}
