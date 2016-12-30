<?php

namespace Valet;

use Exception;
use DomainException;

class Acrylic
{
    var $cli, $files;

    /**
     * Create a new Acrylic instance.
     *
     * @param  CommandLine  $cli
     * @param  Filesystem  $files
     * @return void
     */
    function __construct(CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->files = $files;
    }

    function install($domain = 'dev')
    {
        $this->cli->runOrDie('cmd "/C '.$this->path().'/AcrylicController InstallAcrylicService"', function ($code, $output) {
            warning($output);
        });

        $this->createConfigFile($domain);

        $this->restart();
    }

    function createConfigFile($domain)
    {
        $contents = $this->files->get(__DIR__.'/../stubs/AcrylicHosts.txt');

        $this->files->put(
            $this->path().'/AcrylicHosts.txt',
            str_replace(['DOMAIN', 'VALET_HOME_PATH'], [$domain, VALET_HOME_PATH], $contents)
        );

        $customConfigPath = VALET_HOME_PATH.'/AcrylicHosts.txt';

        if (! $this->files->exists($customConfigPath)) {
            $this->files->putAsUser($customConfigPath, PHP_EOL);
        }
    }

    /**
     * Update the domain used by Acrylic DNS.
     *
     * @param  string  $oldDomain
     * @param  string  $newDomain
     * @return void
     */
    function updateDomain($oldDomain, $newDomain)
    {
        $this->stop();

        $this->createConfigFile($newDomain);

        $this->restart();
    }

    function uninstall()
    {
        $this->stop();

        $this->cli->quietly('cmd "/C '.$this->path().'/AcrylicController UninstallAcrylicService"');
    }

    function start()
    {
        $this->cli->runOrDie('cmd "/C '.$this->path().'/AcrylicController StartAcrylicServiceSilently"', function ($code, $output) {
            warning($output);
        });

        $this->flushdns();
    }

    function stop()
    {
        $this->cli->run('cmd "/C '.$this->path().'/AcrylicController StopAcrylicServiceSilently"');

        $this->flushdns();
    }

    function restart()
    {
        $this->stop();

        $this->start();
    }

    function flushdns()
    {
        $this->cli->run('cmd "/C ipconfig /flushdns"');
    }

    /**
     * Get the Acrylic path.
     *
     * @return string
     */
    function path()
    {
        return realpath(__DIR__.'/../../bin/Acrylic/');
    }
}
