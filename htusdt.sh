#!/bin/sh
ps -ef | grep mix | grep htusdt | grep -v grep
if [ $? -ne 0 ]
then
/usr/bin/php /data/www/coin/bin/mix.php htusdt >> /tmp/htusdt.log
else
echo "runing......"
fi
