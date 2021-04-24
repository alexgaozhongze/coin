#!/bin/sh
ps -ef | grep mix | grep btcusdt | grep -v grep
if [ $? -ne 0 ]
then
/usr/bin/php /data/www/coin/bin/mix.php btcusdt >> /tmp/btcusdt.log
else
echo "runing......"
fi
