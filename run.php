<?php

define('ROOT', __DIR__);

if (!$loader = include ROOT . '/vendor/autoload.php') {
    die('You must set up the project dependencies.');
}

$app = new \Cilex\Application('Remote DB Getter');
$app->register(new \Cilex\Provider\ConfigServiceProvider(), array(
    'config.path' => ROOT . '/config/config.yml',
    'downloads_path' => ROOT . '/downloads',
));

$app->command(new \Brodev\Tool\Command\GetRemoteDatabaseCommand());
$app->command(new \Brodev\Tool\Command\GetCrontabCommand());
$app->command(new \Brodev\Tool\Command\WatchSpamCommand());
$app->run();