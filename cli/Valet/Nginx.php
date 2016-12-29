<?php

namespace Valet;

use ZipArchive;
use DomainException;

class Nginx
{
    var $cli;
    var $files;
    var $configuration;
    var $site;
    var $winsw;

    const SERVICE = 'nginxservice';

    /**
     * Create a new Nginx instance.
     *
     * @param  CommandLine  $cli
     * @param  Filesystem  $files
     * @param  Configuration  $configuration
     * @param  Site  $site
     * @param  WinSW  $winsw
     * @return void
     */
    function __construct(CommandLine $cli, Filesystem $files,
                         Configuration $configuration, Site $site, WinSW $winsw)
    {
        $this->cli = $cli;
        $this->site = $site;
        $this->files = $files;
        $this->winsw = $winsw;
        $this->configuration = $configuration;
    }

    /**
     * Install the configuration files for Nginx.
     *
     * @return void
     */
    function install()
    {
        $this->installConfiguration();
        $this->installServer();
        $this->installNginxDirectory();
        $this->installService();
    }

    /**
     * Install the Nginx configuration file.
     *
     * @return void
     */
    function installConfiguration()
    {
        $contents = $this->files->get(__DIR__.'/../stubs/nginx.conf');

        $this->files->putAsUser(
            $this->path().'/conf/nginx.conf',
            str_replace(['VALET_USER', 'VALET_HOME_PATH'], [user(), VALET_HOME_PATH], $contents)
        );
    }

    /**
     * Install the Valet Nginx server configuration file.
     *
     * @return void
     */
    function installServer()
    {
        $this->files->ensureDirExists($this->path().'/valet');

        $this->files->putAsUser(
            $this->path().'/valet/valet.conf',
            str_replace(
                ['VALET_HOME_PATH', 'VALET_SERVER_PATH', 'VALET_STATIC_PREFIX', 'HOME_PATH'],
                [VALET_HOME_PATH, VALET_SERVER_PATH, VALET_STATIC_PREFIX, $_SERVER['HOME']],
                $this->files->get(__DIR__.'/../stubs/valet.conf')
            )
        );

        $this->files->putAsUser(
            $this->path().'/conf/fastcgi_params',
            $this->files->get(__DIR__.'/../stubs/fastcgi_params')
        );
    }

    /**
     * Install the Nginx configuration directory to the ~/.valet directory.
     *
     * This directory contains all site-specific Nginx servers.
     *
     * @return void
     */
    function installNginxDirectory()
    {
        if (! $this->files->isDir($nginxDirectory = VALET_HOME_PATH.'/Nginx')) {
            $this->files->mkdirAsUser($nginxDirectory);
        }

        $this->files->putAsUser($nginxDirectory.'/.keep', "\n");

        $this->rewriteSecureNginxFiles();
    }

    /**
     * Generate fresh Nginx servers for existing secure sites.
     *
     * @return void
     */
    function rewriteSecureNginxFiles()
    {
        $domain = $this->configuration->read()['domain'];

        $this->site->resecureForNewDomain($domain, $domain);
    }

    /**
     * Install the Windows service.
     *
     * @return void
     */
    function installService()
    {
        $this->uninstall();

        $this->winsw->install(static::SERVICE, [
            'NGINX_PATH' => realpath(__DIR__."/../../bin/nginx"),
        ]);
    }

    /**
     * Restart the Nginx service.
     *
     * @return void
     */
    function restart()
    {
        $this->winsw->restart(static::SERVICE);
    }

    /**
     * Stop the Nginx service.
     *
     * @return void
     */
    function stop()
    {
        $this->winsw->stop(static::SERVICE);
    }

    /**
     * Prepare Nginx for uninstallation.
     *
     * @return void
     */
    function uninstall()
    {
        $this->winsw->uninstall(static::SERVICE);
    }

    /**
     * Get the Nginx path.
     *
     * @return string
     */
    function path()
    {
        return realpath(__DIR__.'/../../bin/nginx');
    }
}
