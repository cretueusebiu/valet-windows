<?php

namespace Valet;

use Exception;
use DomainException;
use Symfony\Component\Process\Process;

class PhpFpm
{
    var $cli, $files, $winsw;

    const SERVICE = 'phpfpmservice';

    /**
     * Create a new PHP FPM class instance.
     *
     * @param  CommandLine  $cli
     * @param  Filesystem  $files
     * @param  WinSW $winsw
     * @return void
     */
    function __construct(CommandLine $cli, Filesystem $files, WinSW $winsw)
    {
        $this->cli = $cli;
        $this->files = $files;
        $this->winsw = $winsw;
    }

    /**
     * Install and configure DnsMasq.
     *
     * @return void
     */
    function install()
    {
        $this->uninstall();

        $this->winsw->install(static::SERVICE, ['PHP_PATH' => $this->findPhpPath()]);

        $this->restart();
    }

    /**
     * Restart the PHP FPM process.
     *
     * @return void
     */
    function restart()
    {
        $this->winsw->restart(static::SERVICE);
    }

    /**
     * Stop the PHP FPM process.
     *
     * @return void
     */
    function stop()
    {
        $this->winsw->stop(static::SERVICE);
    }

    /**
     * Prepare PHP FPM for uninstallation.
     *
     * @return void
     */
    function uninstall()
    {
        $this->winsw->uninstall(static::SERVICE);
    }

    /**
     * Find the PHP path.
     *
     * @return string
     */
    function findPhpPath()
    {
        $php = $this->cli->runOrDie('where php', function ($code, $output) {
            warning('Could not find PHP. Make sure it\'s added to the environment variables.');
        });

        return pathinfo($php, PATHINFO_DIRNAME);
    }
}
