<?php

namespace App\Console\Commands;

use App\Console\Models\CoinModel;
use Mix\Console\CommandLine\Flag;

/**
 * Class HelloCommand
 * @package App\Console\Commands
 * @author liu,jian <coder.keda@gmail.com>
 */
class HelloCommand
{

    /**
     * 主函数
     */
    public function main()
    {
        $name = Flag::string(['n', 'name'], 'Xiao Ming');
        $say  = Flag::string('say', 'Hello, World!');
        println("{$name}: {$say}");

        $coin = new CoinModel();
        $res = $coin->get_account_balance();
        foreach ($res->data->list as $value) {
            if ('usdt' == $value->currency) {
                var_dump($value);
            }
        }
    }

}
