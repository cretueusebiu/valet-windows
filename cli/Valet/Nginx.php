<?php

namespace Valet;

class Nginx
{
    public $cli;
    public $files;
    public $configuration;
    public $site;
    public $winsw;

    const SERVICE = 'nginxservice';

    /**
     * Create a new Nginx instance.
     *
     * @param CommandLine   $cli
     * @param Filesystem    $files
     * @param Configuration $configuration
     * @param Site          $site
     * @param WinSW         $winsw
     *
     * @return void
     */
    public function __construct(CommandLine $cli, Filesystem $files,
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
    public function install()
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
    public function installConfiguration()
    {
        info('Installing nginx configuration...');

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
    public function installServer()
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
     * Install the Nginx configuration directory to the ~/.config/valet directory.
     *
     * This directory contains all site-specific Nginx servers.
     *
     * @return void
     */
    public function installNginxDirectory()
    {
        info('Installing nginx directory...');

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
    public function rewriteSecureNginxFiles()
    {
        if(is_array($config = $this->configuration->read())) {
            $tld = $config['tld'];

            $this->site->resecureForNewTld($tld, $tld);
        }
    }

    /**
     * Install the Windows service.
     *
     * @return void
     */
    public function installService()
    {
        $this->uninstall();

        $this->winsw->install(static::SERVICE, [
            'NGINX_PATH' => realpath(__DIR__.'/../../bin/nginx'),
        ]);
    }

    /**
     * Restart the Nginx service.
     *
     * @return void
     */
    public function restart()
    {
        $this->stop();

        $this->winsw->restart(static::SERVICE);
    }

    /**
     * Stop the Nginx service.
     *
     * @return void
     */
    public function stop()
    {
        info('Stopping nginx...');

        $this->winsw->stop(static::SERVICE);

        $this->cli->run('cmd "/C taskkill /IM nginx.exe /F"');
    }

    /**
     * Prepare Nginx for uninstallation.
     *
     * @return void
     */
    public function uninstall()
    {
        $this->winsw->uninstall(static::SERVICE);
    }

    /**
     * Get the Nginx path.
     *
     * @return string
     */
    public function path()
    {
        return realpath(__DIR__.'/../../bin/nginx');
    }
}
