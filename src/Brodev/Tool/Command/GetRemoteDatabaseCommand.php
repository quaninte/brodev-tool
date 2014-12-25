<?php
/**
 * Copyright Brodev Software.
 * (c) Quan MT <quanmt@brodev.com>
 */

namespace Brodev\Tool\Command;

use Cilex\Command\Command;
use Ssh\Authentication\PublicKeyFile;
use Ssh\Configuration;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Ssh\Authentication\Password;
use Ssh\Session;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

class GetRemoteDatabaseCommand extends Command
{
    /**
     * @var array
     */
    protected $config;

    /**
     * Debug?
     * @var bool
     */
    protected $debug = false;

    protected function configure()
    {
        $this
            ->setName('brodev:tool:get-remote-db')
            ->setAliases(array(
                'get-remote-db',
            ))
            ->setDescription('Get database of remote')
            ->addArgument('remote', InputArgument::REQUIRED, 'What remote do you want to get from?')
            ->addArgument('database', InputArgument::REQUIRED, 'What database do you want to get?')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->config = $this->getContainer()['config']->getArrayCopy();
        $this->config['downloads_path'] = $this->getContainer()['downloads_path'];
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Disable debug by default
        if (isset($this->config['debug'])) {
            $this->debug = $this->config['debug'];
        }
        $output->writeln('Debug:' . ($this->debug ? 'yes' : 'no'));

        // make sure remote existed
        if (!isset($this->config['remotes'][$input->getArgument('remote')])) {
            $output->writeln('Remote "' . $input->getArgument('remote') . '" not found');
            die;
        }

        // make sure database existed
        if (!isset($this->config['remotes'][$input->getArgument('remote')]['databases'][$input->getArgument('database')])) {
            $output->writeln('Database "' . $input->getArgument('remote') . '.' . $input->getArgument('database') . '" not found');
            die;
        }

        // get final params
        $remote = $this->config['remotes'][$input->getArgument('remote')];
        $remote['port'] = isset($remote['port'])? $remote['port']: 22;

        $database = $this->config['remotes'][$input->getArgument('remote')]['databases'][$input->getArgument('database')];

        // configuration
        $configuration = new Configuration($remote['host'], $remote['port']);

        // auth type
        if ($remote['type'] == 'password') {
            $authentication = new Password($remote['params']['username'], $remote['params']['password']);
        } else {
            $homeDir = trim(`cd ~ && pwd`);
            // public key params
            $remote['params']['public_key'] = isset($remote['params']['public_key'])? $remote['params']['public_key']: $homeDir . '/.ssh/id_rsa.pub';
            $remote['params']['private_key'] = isset($remote['params']['private_key'])? $remote['params']['private_key']: $homeDir . '/.ssh/id_rsa';
            $remote['params']['pass_phrase'] = isset($remote['params']['pass_phrase'])? $remote['params']['pass_phrase']: NULL;


            $authentication = new PublicKeyFile($remote['params']['username'], $remote['params']['public_key'], $remote['params']['private_key'], $remote['params']['pass_phrase']);
        }

        $output->writeln('Connecting to ' . $remote['params']['username'] . '@' . $configuration->getHost() . ':' . $configuration->getPort() . ' using ' . $remote['type'] . ' ... ');

        $session = new Session($configuration, $authentication);
        $exec = $session->getExec();

        $currentPath = trim($exec->run('pwd'));

        $output->writeln('Connected to server, current location is ' . $currentPath);

        $versionName = $input->getArgument('remote') . '.' . $input->getArgument('database') . date('.Y-m-d-h-s');

        // default host = local
        if (!isset($database['host'])) {
            $database['host'] = '127.0.0.1';
        }
        switch ($database['type']) {
            case 'mongodb':
                // default port
                if (!isset($database['port'])) {
                    $database['port'] = 27017;
                }

                // dump database
                $tmpDb = $versionName;
                $cmdTemplate = 'mongodump --host %host% --port %port% --db %dbname% --out "%outputPath%"';
                $cmd = str_replace(array(
                    '%host%', '%port%', '%dbname%', '%outputPath%',
                ), array(
                    $database['host'], $database['port'], $database['name'], $tmpDb,
                ), $cmdTemplate);

                if ($this->debug) {
                    $output->writeln('run ' . $cmd);
                }

                $exec->run($cmd);
                $output->writeln('Database is dumped to ' . $tmpDb);

                break;
            case 'mysql':
                default:
                // default port
                if (!isset($database['port'])) {
                    $database['port'] = 3306;
                }

                // dump database
                $tmpDb = $versionName . '.sql';
                $cmdTemplate = 'mysqldump -u %username% -p%password% -h %host% --port=%port% %dbname% > "%tmpFile%"';
                $cmd = str_replace(array(
                    '%username%', '%password%', '%host%', '%port%', '%dbname%', '%tmpFile%',
                ), array(
                    $database['username'], $database['password'], $database['host'], $database['port'], $database['name'], $tmpDb,
                ), $cmdTemplate);

                if ($this->debug) {
                    $output->writeln('run ' . $cmd);
                }

                $exec->run($cmd);
                $output->writeln('Database is dumped to ' . $tmpDb);
        }

        // compress file
        $tmpCompressedFileName = $tmpDb . '.tar.gz';
        $tmpCompressedFile = $currentPath . '/' . $tmpCompressedFileName;
        $cmd = 'tar -zcvf "' . $tmpCompressedFileName . '" "' . $tmpDb . '"';
        $exec->run($cmd);
        $output->writeln('Database is compressed to ' . $tmpCompressedFile);

        // download file to local machine
        $dir = $this->config['downloads_path'] . '/' . $input->getArgument('remote') . '/' . $input->getArgument('database');
        exec('mkdir -p "' . $dir . '"');
        $localPath = $dir . '/' . $tmpCompressedFileName;
        $output->writeln('Downloading compressed file to ' . $localPath);
        $sftp = $session->getSftp();
        $sftp->receive($tmpCompressedFile, $localPath);

        // remove tmp files
        $exec->run('rm -rf ' . $tmpDb);
        $exec->run('rm -rf ' . $tmpCompressedFile);

        // do we need upload files to s3?
        if (!isset($this->config['storage'])) {
            $storage = array(
                'type' => 'local',
            );
        } else {
            $storage = $this->config['storage'];
        }

        if ($storage['type'] == 's3') {

            $output->writeln('Uploading file to s3');

            // Instantiate the S3 client with your AWS credentials and desired AWS region
            $client = S3Client::factory(array(
                'key'    => $storage['params']['key'],
                'secret' => $storage['params']['secret'],
            ));

            // Upload a publicly accessible file. The file size, file type, and MD5 hash are automatically calculated by the SDK
            try {
                $filePath = $input->getArgument('remote') . '/' . $input->getArgument('database') . '/' . $tmpCompressedFileName;
                $client->putObject(array(
                    'Bucket' => $storage['params']['bucket'],
                    'Key'    => $filePath,
                    'Body'   => fopen($localPath, 'r'),
                    'ACL'    => 'private',
                ));


                $output->writeln('File is successful uploaded to s3');

                // remove local file
                exec('rm -rf "' . $localPath . '"');
            } catch (S3Exception $e) {
                $output->writeln("There was an error uploading the file.");
            }
        }

        $output->writeln('Done');
    }

}