#!/bin/sh
ps -ef | grep mix | grep ethusdt | grep -v grep
if [ $? -ne 0 ]
then
/usr/bin/php /data/www/coin/bin/mix.php ethusdt >> /tmp/ethusdt.log
else
echo "runing......"
fi
