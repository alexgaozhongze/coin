<?php

/**
 * 用户助手函数
 */

/**
 * 获取全局配置对象
 * @return \Noodlehaus\Config
 */
function config()
{
    return $GLOBALS['config'];
}

function shellPrint($datas)
{
    $arrayKeys = [];
    $maxLens = [];
    $list = [];

    foreach ($datas as $key => $value) {
        if (!$arrayKeys) {
            $arrayKeys = array_keys($value);
            foreach ($arrayKeys as $aValue) {
                $list[$aValue] = [$aValue];
            }
        }

        foreach ($arrayKeys as $aValue) {
            $list[$aValue][] = $value[$aValue];
        }
    }

    foreach ($arrayKeys as $aValue) {
        $list[$aValue][] = $aValue;
    }

    foreach ($list as $key => $value) {
        $maxLens[$key] = max(array_map('strlen', $value));
    }

    $count = count($datas);
    for ($i=0; $i <= $count; $i++) { 
        foreach ($arrayKeys as $value) {
            $len = $maxLens[$value] + 6;
            // printf("% -{$len}s", $list[$value][$i]);
            printf("% {$len}s", $list[$value][$i]);
        }
        echo PHP_EOL;
    }

    foreach ($arrayKeys as $value) {
        $len = $maxLens[$value] + 6;
        printf("% {$len}s", $value);
        // printf("% -{$len}s", $value);
    }
    echo PHP_EOL, PHP_EOL;
}