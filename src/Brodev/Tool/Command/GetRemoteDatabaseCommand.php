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
use Ssh\Exec;

class GetRemoteDatabaseCommand extends Command
{
    /**
     * @var array
     */
    protected $config;

    protected function configure()
    {
        $this
            ->setName('brodev:tool:get-remote-db')
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
        if (!isset($database['host'])) {
            $database['host'] = 'localhost';
        }
        if (!isset($database['port'])) {
            $database['port'] = 3306;
        }

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

        switch ($database['type']) {
            case 'mongodb':
                // dump database
                $tmpDb = $versionName;
                $cmdTemplate = 'mongodump -o "%outputPath%"';
                $cmd = str_replace(array(
                    '%outputPath%',
                ), array(
                    $tmpDb,
                ), $cmdTemplate);

                $exec->run($cmd);
                $output->writeln('Database is dumped to ' . $tmpDb);

                break;
            case 'mysql':
                default:
                // dump database
                $tmpDb = $versionName . '.sql';
                $cmdTemplate = 'mysqldump -u %username% -p%password% -h %host% --port=%port% %dbname% > "%tmpFile%"';
                $cmd = str_replace(array(
                    '%username%', '%password%', '%host%', '%port%', '%dbname%', '%tmpFile%',
                ), array(
                    $database['username'], $database['password'], $database['host'], $database['port'], $database['name'], $tmpDb,
                ), $cmdTemplate);
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

        $output->writeln('Done');
    }


}