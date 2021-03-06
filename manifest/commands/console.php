<?php

return [

    'g' => [
        \App\Console\Commands\GobeyondCommand::class,
        'usage'   => "\tgobeyond",
    ],

    'htusdt' => [
        \App\Console\Commands\HtusdtCommand::class,
        'usage'   => "\thtusdt",
    ],

    'ethusdt' => [
        \App\Console\Commands\EthusdtCommand::class,
        'usage'   => "\tethusdt",
    ],

    'btcusdt' => [
        \App\Console\Commands\BtcusdtCommand::class,
        'usage'   => "\tbtcusdt",
    ],

    'usdt' => [
        \App\Console\Commands\UsdtCommand::class,
        'usage'   => "\tUsdt",
    ],

    'buy' => [
        \App\Console\Commands\BuyCommand::class,
        'usage'   => "\tBuy",
    ],

    'highbuy' => [
        \App\Console\Commands\HighBuyCommand::class,
        'usage'   => "\tHighBuy",
    ],

    'lowbuy' => [
        \App\Console\Commands\LowBuyCommand::class,
        'usage'   => "\tLowBuy",
    ],

    'sell' => [
        \App\Console\Commands\SellCommand::class,
        'usage'   => "\tSell",
    ],

    'symbol' => [
        \App\Console\Commands\SymbolCommand::class,
        'usage'   => "\tSymbol",
    ],

    'he' => [
        \App\Console\Commands\HelloCommand::class,
        'usage'   => "\tEcho demo",
        'options' => [
            [['n', 'name'], 'usage' => 'Your name'],
            ['say', 'usage' => "\tSay ..."],
        ],
    ],

    'account' => [
        \App\Console\Commands\AccountCommand::class,
        'usage'   => "\tAccount",
    ],

    'kline' => [
        \App\Console\Commands\KlineCommand::class,
        'usage'   => "\tKline",
        'options' => [
            [['s', 'symbol'], 'usage' => 'Symbol'],
        ],
    ],

    'ticker' => [
        \App\Console\Commands\TickerCommand::class,
        'usage'   => "\tTicker",
    ],

    'co' => [
        \App\Console\Commands\CoroutineCommand::class,
        'usage' => "\tCoroutine demo",
    ],

    'wg' => [
        \App\Console\Commands\WaitGroupCommand::class,
        'usage' => "\tWaitGroup demo",
    ],

    'cp' => [
        \App\Console\Commands\WorkerPoolCommand::class,
        'usage' => "\tWorker pool demo",
    ],

    'cpd' => [
        \App\Console\Commands\WorkerPoolDaemonCommand::class,
        'usage'   => "\tWorker pool daemon demo",
        'options' => [
            [['d', 'daemon'], 'usage' => 'Run in the background'],
        ],
    ],

    'ti' => [
        \App\Console\Commands\TimeCommand::class,
        'usage' => "\tTimer and Ticker demo",
    ],

];
