#!/bin/sh
ps -ef | grep mix | grep kline | grep -v grep
if [ $? -ne 0 ]
then
/usr/bin/php /data/www/coin/bin/mix.php kline
else
echo "runing....."
fi
