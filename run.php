<?php
if (!$loader = include __DIR__.'/vendor/autoload.php') {
    die('You must set up the project dependencies.');
}

$app = new \Cilex\Application('Remote DB Getter');
$app->register(new \Cilex\Provider\ConfigServiceProvider(), array(
    'config.path' => __DIR__ . '/config/config.yml',
    'downloads_path' => __DIR__ . '/downloads',
));

$app->command(new \Brodev\Tool\Command\GetRemoteDatabaseCommand());
$app->command(new \Brodev\Tool\Command\GetCrontabCommand());
$app->run();