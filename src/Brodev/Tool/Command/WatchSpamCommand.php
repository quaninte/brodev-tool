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
use Gregwar\Cache\Cache;

class WatchSpamCommand extends Command
{
    protected $whiteListKeywords = array(
        'YandexBot',
        'YahooSeeker',
        'Yahoo! Slurp',
        'Googlebot',
        'bingbot',
        'Baiduspider',
        'Mail.RU_Bot',
    );

    protected $whiteListIPCacheCode = 'white_list_ips';

    /**
     * @var Cache
     */
    protected $cache;

    protected function configure()
    {
        $this
            ->setName('brodev:tool:watch-spam')
            ->setDescription('Watch spam bot using stopfroumspam.com')
            ->addArgument('log_path', InputArgument::REQUIRED, 'Path to log file')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->cache = new Cache;
        $this->cache->setCacheDirectory(ROOT . '/cache');

        if ($this->cache->check($this->whiteListIPCacheCode)) {
            $data = unserialize($this->cache->get($this->whiteListIPCacheCode));

            if (!isset($data['date']) || $data['date'] != date('Y-m-d')) {
                $data = array(
                    'date' => date('Y-m-d'),
                );
                $this->cache->write($this->whiteListIPCacheCode, serialize($data));
            }
        }
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $logPath = $input->getArgument('log_path');

        // get all ips from log
        $ips = array();

        // read log file
        $handle = @fopen($logPath, "r");
        if ($handle) {
            while (($buffer = fgets($handle, 4096)) !== false) {
                list($ip, $agent) = $this->getDataFromLogLine($buffer);
                if (!isset($ips[$ip])) {
                    $ips[$ip] = strtolower($agent);
                }


            }
            if (!feof($handle)) {
                echo "Error: unexpected fgets() fail\n";
            }
            fclose($handle);
        }
        // clear
        file_put_contents($logPath, "");

        // request to check spam
        foreach ($ips as $ip => $agent) {
            // if is spammer
            if ($this->isSpamIp($ip, $agent)) {
                // block this ip
                $this->blockIP($ip);
                $output->writeln('Blocked ' . $ip);
            } else {
                // save this ip to whiteListIPs
                $this->addWhiteList($ip);
                $output->writeln('White listed ' . $ip);
            }
        }

        // save iptables rules
        $cmd = 'sudo sh -c "iptables-save > /etc/iptables.rules"';
        exec($cmd);
    }

    /**
     * Get Data (ip, agent) from log line
     * @param $line
     * @return String
     */
    protected function getDataFromLogLine($line)
    {
        $data = explode(' ', $line, 3);
        $array = explode('"', $data[2]);
        return array($data[1], $array[1]);
    }

    /**
     * Check if an IP is spam or clean
     * return true if is spam
     *        false if not spam
     * @param $ip
     * @param $agent
     * @return bool
     */
    protected function isSpamIp($ip, $agent)
    {
        // check whitelist
        // keyword
        foreach ($this->whiteListKeywords as $keyword) {
            if (strpos($agent, strtolower($keyword)) !== false) {
                return false;
            }
        }
        // ip
        if ($this->isInWhiteList($ip)) {
            return false;
        }

        $url = 'http://www.stopforumspam.com/api?ip=' . $ip;
        $content = $this->getContent($url);

        if (strpos($content, '<appears>yes</appears>') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Get content via http
     * @param $url
     * @return string
     */
    protected function getContent($url)
    {
        // send request
        $ch = curl_init();

        // Set query data here with the URL
        curl_setopt($ch, CURLOPT_URL, $url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, '3');
        $content = trim(curl_exec($ch));
        curl_close($ch);

        return $content;

    }

    /**
     * Block an IP
     * @param $ip
     */
    protected function blockIP($ip)
    {
        $cmd = 'iptables -A INPUT -s %ip% -j DROP';
        $cmd = str_replace('%ip%', $ip, $cmd);
        exec($cmd);

        // log
        $file = fopen(ROOT . '/cache/block.log', 'a');
        fwrite($file, $ip . "\n");
        fclose($file);
    }

    /**
     * Add ip to white list
     * @param $ip
     */
    protected function addWhiteList($ip)
    {
        // only add to white list once
        if ($this->isInWhiteList($ip)) {
            return;
        }

        $data = unserialize($this->cache->get($this->whiteListIPCacheCode));
        if (!isset($data['ips'])) {
            $data['ips'] = array();
        }
        if (!isset($data['date'])) {
            $data['date'] = date('Y-m-d');
        }

        $data['ips'][] = $ip;
        $this->cache->write($this->whiteListIPCacheCode, serialize($data));
    }

    /**
     * Is provided ip in white list?
     * @param $ip
     * @return bool
     */
    protected function isInWhiteList($ip)
    {
        $data = unserialize($this->cache->get($this->whiteListIPCacheCode));
        if (isset($data['ips']) && in_array($ip, $data['ips'])) {
            return true;
        }

        return false;
    }

} 