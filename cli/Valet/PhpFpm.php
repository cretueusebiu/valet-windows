<?php

namespace Valet;

use Exception;
use DomainException;
use Symfony\Component\Process\Process;

class PhpFpm
{
    var $cli, $files;

    /**
     * Create a new PHP FPM class instance.
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

    /**
     * Install and configure DnsMasq.
     *
     * @return void
     */
    function install()
    {
        // $this->files->ensureDirExists('/usr/local/var/log', user());

        // $this->updateConfiguration();

        $this->createService();

        $this->restart();
    }

    /**
     * Restart the PHP FPM process.
     *
     * @return void
     */
    function restart()
    {
        $this->stop();

        $this->cli->runOrDie('cmd "/C net start PHP_FPM"', function ($code, $output) {
            warning($output);
        });
    }

    /**
     * Stop the PHP FPM process.
     *
     * @return void
     */
    function stop()
    {
        $this->cli->run('cmd "/C net stop PHP_FPM"');
    }

    /**
     * Prepare PHP FPM for uninstallation.
     *
     * @return void
     */
    function uninstall()
    {
        $this->deleteService();
    }

    /**
     * Delete the Windows service.
     *
     * @return void
     */
    function deleteService()
    {
        $this->stop();

        $this->cli->run('cmd "/C sc delete PHP_FPM"');
    }

    /**
     * Create the Windows service.
     *
     * @return void
     */
    function createService()
    {
        $this->deleteService();

        $this->cli->runOrDie($this->serviceCommand(), function ($code, $output) {
            warning($output);
        });
    }

    /**
     * Get the command for creating the Windows service.
     *
     * @return string
     */
    function serviceCommand()
    {
        $service = realpath(__DIR__.'/../../bin/service.exe');

        $php = $this->cli->runOrDie('where php', function ($code, $output) {
            warning('Could not find PHP. Make sure it\'s added to the environment variables.');
        });

        $php = pathinfo($php, PATHINFO_DIRNAME);
        $fpm = $php.'/php-cgi.exe';
        $ini = $php.'/php.ini';

        return 'cmd "/C sc create PHP_FPM binPath= "'.$service.' \"'.$fpm.' -b 127.0.0.1:9000 -c '.
                $ini.'"" type= own start= auto error= ignore DisplayName= PHP_FPM"';
    }
}
