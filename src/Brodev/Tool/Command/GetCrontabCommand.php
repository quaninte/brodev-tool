<?php
/**
 * Copyright Brodev Software.
 * (c) Quan MT <quanmt@brodev.com>
 */


namespace Brodev\Tool\Command;


use Cilex\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

class GetCrontabCommand extends Command
{
    /**
     * @var array
     */
    protected $config;

    protected function configure()
    {
        $this
            ->setName('brodev:tool:get-crontab')
            ->setDescription('Get crontab configuration')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->config = $this->getContainer()['config']->getArrayCopy();
        $this->config['downloads_path'] = $this->getContainer()['downloads_path'];
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $commandTemplate = '%cron% php ' . $_SERVER['PWD'] . '/run.php brodev:tool:get-remote-db %remote% %database%';

        $output->writeln('############## BRODEV TOOL ##############');
        foreach ($this->config['remotes'] as $remoteIndex => $remote) {
            foreach ($remote['databases'] as $databaseIndex => $database) {
                $cmd = str_replace(array(
                    '%cron%', '%remote%', '%database%',
                ), array(
                    $database['cron'], $remoteIndex, $databaseIndex,
                ), $commandTemplate);

                $output->writeln($cmd);
            }
        }
        $output->writeln('#########################################');
    }

}