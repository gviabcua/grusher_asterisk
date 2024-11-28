# Grusher Asterisk AMI
## This is for test now
## Installation
- unpack to /opt (or other folder)
- rename config.ini-sample to config.ini
- set up config.ini
- add to cron
*/1 * * * *  /usr/bin/php /opt/grusher_ami/run.php start -d >> /tmp/asterisk-`/bin/date +\%Y\%m\%d`.log  2>&1
