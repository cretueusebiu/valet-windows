<?php

namespace Valet;

class PhpCgiXdebug extends PhpCgi
{
    const PORT = 9100;

    /**
     * @inheritDoc
     */
    public function __construct(CommandLine $cli, Filesystem $files, WinSwFactory $winswFactory, Configuration $configuration)
    {
        parent::__construct($cli, $files, $winswFactory, $configuration);

        foreach ($this->phpWinSws as $phpVersion => $phpWinSw) {
            $phpServiceName = "php{$phpVersion}cgixdebugservice";

            $this->phpWinSws[$phpVersion]['phpServiceName'] = $phpServiceName;
            $this->phpWinSws[$phpVersion]['phpCgiName'] = "valet_php{$phpVersion}cgi_xdebug-{$phpWinSw['php']['xdebug_port']}";
            $this->phpWinSws[$phpVersion]['winsw'] = $this->winswFactory->make($phpServiceName);
        }
    }

    /**
     * Install and configure PHP CGI service.
     *
     * @return void
     */
    public function install($phpVersion = null)
    {
        $phps = $this->configuration->get('php', []);

        if ($phpVersion) {
            if (! isset($this->phpWinSws[$phpVersion])) {
                warning("PHP xDebug service for version {$phpVersion} not found");
            }

            $this->installService($phpVersion);

            return;
        }

        foreach ($phps as $php) {
            $this->installService($php['version']);
        }
    }

    /**
     * Install the Windows service.
     *
     * @return void
     */
    public function installService($phpVersion, $phpCgiServiceConfig = null, $installConfig = null)
    {
        $phpWinSw = $this->phpWinSws[$phpVersion];

        $phpCgiServiceConfig = $phpCgiServiceConfig ?? file_get_contents(__DIR__.'/../stubs/phpcgixdebugservice.xml');
        $installConfig = $installConfig ?? [
            'PHP_PATH' => $phpWinSw['php']['path'],
            'PHP_XDEBUG_PORT' => $phpWinSw['php']['xdebug_port'],
        ];

        parent::installService($phpVersion, $phpCgiServiceConfig, $installConfig);
    }
}
