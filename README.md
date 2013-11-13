Brodev Tool
===========
Group of helper tools for developers and system admin

## Installation
1. Clone repo
2. Run ``php composer.phar install`` to install vendor

## Remote database getter
This tool help you quickly grab a copy of database from a remote server

### Usage
1. Create config/config.yml base on sample file config/config.sample.yml
2. Run ``php run.php brodev:tool:get-remote-db %remote% %database%``
3. Your remote database will be saved at /downloads/%remote%/%database%
4. You can configure your config.yml so backup will be uploaded to amazon s3 instead of store in local

You can also use these commands with cron jobs to setup auto back up remote database

### Requirements
php-ssh2

## Spam Watch
This tool will watch apache (or nginx) log file, find spam IP using www.stopforumspam.com API and block the IP with IPTABLES

### Usage

#### Setup apache2 log

Create this file ``/etc/apache2/conf.d`` with content
```conf
# Define an access log for VirtualHosts dedicated for spam watch
LogFormat "%v:%p %a \"%{User-Agent}i\" %h %l %u %t \"%r\" %>s %O \"%{Referer}i\"" spam_combined
CustomLog ${APACHE_LOG_DIR}/bot_watch_access.log spam_combined
```
Then you need to setup log for all virtual hosts, you want to watch

#### Setup cron jobs
**All below commands require root account (because iptables access)**

1. Chmod cache folder ``chmod 777 cache``
2. Create block log ``touch cache/block.log && chmod 777 cache/block.log``
3. Run ``php run.php brodev:tool:watch-spam /var/log/apache2/bot_watch_access.log``
4. Setup cron job to run above command every minutes ``* * * * * cd /path/to/brodev-tool/ &&  php run.php brodev:tool:watch-spam /var/log/apache2/bot_watch_access.log``

### Requirements
root access, iptables

## Credit
Truong Manh Quan - Brodev Software
http://quaninte.com