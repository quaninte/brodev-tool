DATABASE GETTER
===============

This tool help you quickly grab a copy of database from a remote server

## Usage
1. Run ``php composer.phar install`` to install vendor
2. Create config/config.yml base on sample file config/config.sample.yml
3. Run ``php run.php brodev:tool:get-remote-db %remote% %database%``
4. Your remote database will be saved at /downloads/%remote%/%database%, you can use these commands to setup auto back up remote database

### Requirements
php-ssh2

## Credit
Truong Manh Quan - Brodev Software
http://quaninte.com