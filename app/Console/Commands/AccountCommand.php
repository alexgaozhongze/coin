<?php

namespace App\Console\Commands;

use App\Console\Models\CoinModel;

/**
 * Class AccountCommand
 * @package App\Console\Commands
 * @author alex <alexgaozhongze@gmail.com>
 * 
 */
class AccountCommand
{

    /**
     * 主函数
     */
    public function main()
    {
        $coin = new CoinModel();

        $res = $coin->get_account_balance();

        foreach ($res->data->list as $value) {
            if ('usdt' == $value->currency) {
                var_dump($value);
            }
        }
        
    }

}
