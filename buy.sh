#!/bin/sh
ps -ef | grep mix | grep buy | grep -v grep
if [ $? -ne 0 ]
then
/usr/bin/php /data/www/coin/bin/mix.php buy >> /tmp/buy.log
else
echo "runing......"
fi
