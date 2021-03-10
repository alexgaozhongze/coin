#!/bin/sh
ps -ef | grep mix | grep sell | grep -v grep
if [ $? -ne 0 ]
then
/usr/bin/php /data/www/coin/bin/mix.php sell
else
echo "runing....."
fi
