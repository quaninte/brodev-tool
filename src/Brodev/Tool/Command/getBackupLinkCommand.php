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
use Aws\S3\S3Client;

class GetBackupLinkCommand extends Command
{
    /**
     * @var array
     */
    protected $config;

    protected function configure()
    {
        $this
            ->setName('brodev:tool:get-backup-link')
            ->setAliases(array(
                'get-backup-link',
            ))
            ->setDescription('Get back up link')
            ->addArgument('remote', InputArgument::REQUIRED, 'What remote do you want to get from?')
            ->addArgument('database', InputArgument::REQUIRED, 'What database do you want to get?')
            ->addArgument('index', InputArgument::OPTIONAL, 'What index you want to get? (0 = latest)', 0)
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->config = $this->getContainer()['config']->getArrayCopy();
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $remoteCode = $input->getArgument('remote');
        $databaseCode = $input->getArgument('database');
        $bucket = $this->config['storage']['params']['bucket'];
        $dirPath = $remoteCode . '/' . $databaseCode . '/';

        // s3
        $client = S3Client::factory(array(
            'key'    => $this->config['storage']['params']['key'],
            'secret' => $this->config['storage']['params']['secret'],
        ));

        // find the files
        // s3
        $files = array_reverse($client->getIterator('ListObjects', array(
            'Bucket' => $bucket,
            'Prefix' => $dirPath,
        ))->toArray());

        $file = $files[$input->getArgument('index')];

        // get link
        $link = $client->getObjectUrl($bucket, $file['Key'], '+30 minutes');

        // return to screen
        $output->writeln($link);
    }

} 