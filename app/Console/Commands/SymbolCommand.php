<?php

namespace App\Console\Commands;

use App\Console\Models\CoinModel;
use GuzzleHttp\Client;
use Mix\Coroutine\Channel;

/**
 * Class SymbolCommand
 * @package App\Console\Commands
 * @author alex <alexgaozhongze@gmail.com>
 */
class SymbolCommand
{
    /**
     * 主函数
     */
    public function main()
    {
        $coin = new CoinModel();
        $symbols = $coin->get_common_symbols();

        $redis = context()->get('redis');

        foreach ($symbols['data'] as $value) {
            $redis->hset('symbol', $value['symbol'], serialize($value));
        }

        $redis = context()->get('redis');

        $conn = $redis->borrow();
        $conn = null;
    }

}
