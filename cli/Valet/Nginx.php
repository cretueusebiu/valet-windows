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

    const DOWNLOAD_URL = 'http://nginx.org/download/nginx-1.11.7.zip';

    /**
     * Create a new Nginx instance.
     *
     * @param  CommandLine  $cli
     * @param  Filesystem  $files
     * @param  Configuration  $configuration
     * @param  Site  $site
     * @return void
     */
    function __construct(CommandLine $cli, Filesystem $files,
                         Configuration $configuration, Site $site)
    {
        $this->cli = $cli;
        $this->site = $site;
        $this->files = $files;
        $this->configuration = $configuration;
    }

    /**
     * Install the configuration files for Nginx.
     *
     * @return void
     */
    function install()
    {
        $this->ensureInstalled();

        $this->installConfiguration();
        $this->installServer();
        $this->installNginxDirectory();
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
                ['VALET_HOME_PATH', 'VALET_SERVER_PATH', 'VALET_STATIC_PREFIX'],
                [VALET_HOME_PATH, VALET_SERVER_PATH, VALET_STATIC_PREFIX],
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
     * Restart the Nginx service.
     *
     * @return void
     */
    function restart()
    {
        $this->stop();

        $this->cli->quietly('cmd "/C cd '.$this->path().' && start nginx"');
    }

    /**
     * Stop the Nginx service.
     *
     * @return void
     */
    function stop()
    {
        $this->cli->quietly('cmd "/C cd '.$this->path().' && nginx -s stop"');
    }

    /**
     * Prepare Nginx for uninstallation.
     *
     * @return void
     */
    function uninstall()
    {
        $this->stop();
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

    /**
     * Delete the Windows service.
     *
     * @return void
     */
    function deleteService()
    {
        $this->stop();

        $this->cli->run('cmd "/C sc delete VALET_NGINX"');
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

        $this->cli->quietly('cmd "/C cd '.$this->path().' && start nginx"');

        return 'cmd "/C sc create VALET_NGINX binPath= "'.$service.' \"'.$fpm.' -b 127.0.0.1:9000 -c '.
                $ini.'"" type= own start= auto error= ignore DisplayName= VALET_NGINX"';
    }

    /**
     * Ensure that Nginx is installed.
     *
     * @return void
     */
    function ensureInstalled()
    {
        if (is_dir($this->path())) {
            return;
        }

        output('<info>[Nginx] is not installed, installing it now...</info>');

        $binPath = realpath(__DIR__.'/../../bin');

        $zipPath = $binPath.'/nginx.zip';

        $contents = $this->files->get(static::DOWNLOAD_URL);

        if ($contents === false || file_put_contents($zipPath, $contents) === false) {
            throw new DomainException('Unable to download [Nginx].');
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath)) {
            $zip->extractTo($binPath);
            $zip->close();
        } else {
            throw new DomainException('Unable to unzip [Nginx].');
        }

        rename($binPath.'/'.pathinfo(static::DOWNLOAD_URL, PATHINFO_FILENAME), $binPath.'/nginx');

        $this->files->unlink($zipPath);
    }
}
