#!/bin/sh
ps -ef | grep mix | grep lowbuy | grep -v grep
if [ $? -ne 0 ]
then
/usr/bin/php /data/www/coin/bin/mix.php lowbuy >> /tmp/lowbuy.log
else
echo "runing......"
fi
