<?php

/**
 * Load correct autoloader depending on install location.
 */
if (file_exists(__DIR__.'/../vendor/autoload.php')) {
    require_once __DIR__.'/../vendor/autoload.php';
} else {
    require_once __DIR__.'/../../../autoload.php';
}

use Illuminate\Container\Container;
use Silly\Application;
use Symfony\Component\Console\Question\ConfirmationQuestion;

use function Valet\info;
use function Valet\output;
use function Valet\table;
use function Valet\warning;

/**
 * Relocate config dir to ~/.config/valet/ if found in old location.
 */
if (is_dir(VALET_LEGACY_HOME_PATH) && ! is_dir(VALET_HOME_PATH)) {
    Configuration::createConfigurationDirectory();
}

/**
 * Create the application.
 */
Container::setInstance(new Container);

$version = '2.5.4';

$app = new Application('Laravel Valet', $version);

/**
 * Prune missing directories and symbolic links on every command.
 */
if (is_dir(VALET_HOME_PATH)) {
    Configuration::prune();

    Site::pruneLinks();
}

/**
 * Install Valet and any required services.
 */
$app->command('install', function () {
    Configuration::install();
    Nginx::install();
    PhpCgi::install();
    PhpCgiXdebug::install();
    Acrylic::install(Configuration::read()['tld']);

    output(PHP_EOL.'<info>Valet installed successfully!</info>');
})->descriptions('Install the Valet services');

/**
 * Most commands are available only if valet is installed.
 */
if (is_dir(VALET_HOME_PATH)) {
    /**
     * Upgrade helper: ensure the tld config exists.
     */
    if (empty(Configuration::read()['tld'])) {
        Configuration::writeBaseConfiguration();
    }

    /**
     * Get or set the TLD currently being used by Valet.
     */
    $app->command('tld [tld]', function ($tld = null) {
        if ($tld === null) {
            return info(Configuration::read()['tld']);
        }

        $oldTld = Configuration::read()['tld'];

        Acrylic::updateTld($tld = trim($tld, '.'));

        Configuration::updateKey('tld', $tld);

        Site::resecureForNewTld($oldTld, $tld);
        PhpCgi::restart();
        PhpCgiXdebug::restart();
        Nginx::restart();

        info('Your Valet TLD has been updated to ['.$tld.'].');
    }, ['domain'])->descriptions('Get or set the TLD used for Valet sites.');

    /**
     * Add the current working directory to the paths configuration.
     */
    $app->command('park [path]', function ($path = null) {
        Configuration::addPath($path ?: getcwd());

        info(($path === null ? 'This' : "The [{$path}]")." directory has been added to Valet's paths.");
    })->descriptions('Register the current working (or specified) directory with Valet');

    /**
     * Get all the current sites within paths parked with 'park {path}'.
     */
    $app->command('parked', function () {
        $parked = Site::parked();

        table(['Site', 'SSL', 'URL', 'Path'], $parked->all());
    })->descriptions('Display all the current sites within parked paths');

    /**
     * Remove the current working directory from the paths configuration.
     */
    $app->command('forget [path]', function ($path = null) {
        Configuration::removePath($path ?: getcwd());

        info(($path === null ? 'This' : "The [{$path}]")." directory has been removed from Valet's paths.");
    }, ['unpark'])->descriptions('Remove the current working (or specified) directory from Valet\'s list of paths');

    /**
     * Register a symbolic link with Valet.
     */
    $app->command('link [name] [--secure]', function ($name, $secure) {
        $linkPath = Site::link(getcwd(), $name = $name ?: basename(getcwd()));

        info('A ['.$name.'] symbolic link has been created in ['.$linkPath.'].');

        if ($secure) {
            $this->runCommand('secure '.$name);
        }
    })->descriptions('Link the current working directory to Valet');

    /**
     * Display all of the registered symbolic links.
     */
    $app->command('links', function () {
        $links = Site::links();

        table(['Site', 'SSL', 'URL', 'Path'], $links->all());
    })->descriptions('Display all of the registered Valet links');

    /**
     * Unlink a link from the Valet links directory.
     */
    $app->command('unlink [name]', function ($name) {
        info('The ['.Site::unlink($name).'] symbolic link has been removed.');
    })->descriptions('Remove the specified Valet link');

    /**
     * Secure the given domain with a trusted TLS certificate.
     */
    $app->command('secure [domain]', function ($domain = null) {
        $url = ($domain ?: Site::host(getcwd())).'.'.Configuration::read()['tld'];

        Site::secure($url);

        Nginx::restart();

        info('The ['.$url.'] site has been secured with a fresh TLS certificate.');
    })->descriptions('Secure the given domain with a trusted TLS certificate');

    /**
     * Stop serving the given domain over HTTPS and remove the trusted TLS certificate.
     */
    $app->command('unsecure [domain] [--all]', function ($domain = null, $all = null) {
        if ($all) {
            Site::unsecureAll();
            Nginx::restart();

            return;
        }

        $url = ($domain ?: Site::host(getcwd())).'.'.Configuration::read()['tld'];

        Site::unsecure($url);
        Nginx::restart();

        info('The ['.$url.'] site will now serve traffic over HTTP.');
    })->descriptions('Stop serving the given domain over HTTPS and remove the trusted TLS certificate');

    /**
     * Create an Nginx proxy config for the specified domain.
     */
    $app->command('proxy domain host', function ($domain, $host) {
        Site::proxyCreate($domain, $host);
        Nginx::restart();
    })->descriptions('Create an Nginx proxy site for the specified host. Useful for docker, mailhog etc.');

    /**
     * Delete an Nginx proxy config.
     */
    $app->command('unproxy domain', function ($domain) {
        Site::proxyDelete($domain);
        Nginx::restart();
    })->descriptions('Delete an Nginx proxy config.');

    /**
     * Display all of the sites that are proxies.
     */
    $app->command('proxies', function () {
        $proxies = Site::proxies();

        table(['Site', 'SSL', 'URL', 'Host'], $proxies->all());
    })->descriptions('Display all of the proxy sites');

    /**
     * Determine which Valet driver the current directory is using.
     */
    $app->command('which', function () {
        require __DIR__.'/drivers/require.php';

        $driver = ValetDriver::assign(getcwd(), basename(getcwd()), '/');

        if ($driver) {
            info('This site is served by ['.get_class($driver).'].');
        } else {
            warning('Valet could not determine which driver to use for this site.');
        }
    })->descriptions('Determine which Valet driver serves the current working directory');

    /**
     * Display all of the registered paths.
     */
    $app->command('paths', function () {
        $paths = Configuration::read()['paths'];

        if (count($paths) > 0) {
            output(json_encode($paths, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            info('No paths have been registered.');
        }
    })->descriptions('Get all of the paths registered with Valet');

    /**
     * Open the current or given directory in the browser.
     */
    $app->command('open [domain]', function ($domain = null) {
        $url = 'http://'.($domain ?: Site::host(getcwd())).'.'.Configuration::read()['tld'];
        CommandLine::passthru("start $url");
    })->descriptions('Open the site for the current (or specified) directory in your browser');

    /**
     * Generate a publicly accessible URL for your project.
     */
    // TODO: custom domain and ngrok params
    $app->command('share [domain] [--authtoken=] [--host-header=] [--hostname=] [--region=] [--subdomain=]',
        function ($domain = null, $authtoken = null, $hostheader = null, $hostname = null, $region = null, $subdomain = null) {
            $url = ($domain ?: strtolower(Site::host(getcwd()))).'.'.Configuration::read()['tld'];

            Ngrok::start($url, Site::port($url), array_filter([
                'authtoken' => $authtoken,
                'host-header' => $hostheader,
                'hostname' => $hostname,
                'region' => $region,
                'subdomain' => $subdomain,
            ]));
        })->defaults([
            'host-header' => 'rewrite',
        ])->descriptions('Generate a publicly accessible URL for your project');

    /**
     * Echo the currently tunneled URL.
     */
    $app->command('fetch-share-url [domain]', function ($domain = null) {
        output(Ngrok::currentTunnelUrl($domain ?: Site::host(getcwd()).'.'.Configuration::read()['tld']));
    })->descriptions('Get the URL to the current Ngrok tunnel');

    /**
     * Run ngrok commands.
     */
    $app->command('ngrok [args]*', function ($args) {
        Ngrok::run(implode(' ', $args));
    })->descriptions('Run ngrok commands');

    /**
     * Start the daemon services.
     */
    $app->command('start [service]', function ($service) {
        switch ($service) {
            case '':
                Acrylic::restart();
                PhpCgi::restart();
                PhpCgiXdebug::restart();
                Nginx::restart();

                return info('Valet services have been started.');
            case 'acrylic':
                Acrylic::restart();

                return info('Acrylic DNS has been started.');
            case 'nginx':
                Nginx::restart();

                return info('Nginx has been started.');
            case 'php':
                PhpCgi::restart();

                return info('PHP has been started.');
            case 'php-xdebug':
                PhpCgiXdebug::restart();

                return info('PHP Xdebug has been started.');
        }

        return warning(sprintf('Invalid valet service name [%s]', $service));
    })->descriptions('Start the Valet services');

    /**
     * Restart the daemon services.
     */
    $app->command('restart [service]', function ($service) {
        switch ($service) {
            case '':
                Acrylic::restart();
                PhpCgi::restart();
                PhpCgiXdebug::restart();
                Nginx::restart();

                return info('Valet services have been restarted.');
            case 'acrylic':
                Acrylic::restart();

                return info('Acrylic DNS has been restarted.');
            case 'nginx':
                Nginx::restart();

                return info('Nginx has been restarted.');
            case 'php':
                PhpCgi::restart();

                return info('PHP has been restarted.');
            case 'php-xdebug':
                PhpCgiXdebug::restart();

                return info('PHP Xdebug has been restarted.');
        }

        return warning(sprintf('Invalid valet service name [%s]', $service));
    })->descriptions('Restart the Valet services');

    /**
     * Stop the daemon services.
     */
    $app->command('stop [service]', function ($service) {
        switch ($service) {
            case '':
                Acrylic::stop();
                Nginx::stop();
                PhpCgi::stop();
                PhpCgiXdebug::stop();

                return info('Valet services have been stopped.');
            case 'acrylic':
                Acrylic::stop();

                return info('Acrylic DNS has been stopped.');
            case 'nginx':
                Nginx::stop();

                return info('Nginx has been stopped.');
            case 'php':
                PhpCgi::stop();

                return info('PHP has been stopped.');
            case 'php-xdebug':
                PhpCgiXdebug::stop();

                return info('PHP Xdebug has been stopped.');
        }

        return warning(sprintf('Invalid valet service name [%s]', $service));
    })->descriptions('Stop the Valet services');

    /**
     * Uninstall Valet entirely. Requires --force to actually remove; otherwise manual instructions are displayed.
     */
    $app->command('uninstall [--force] [--purge-config]', function ($input, $output, $force, $purgeConfig) {
        warning('YOU ARE ABOUT TO UNINSTALL Nginx, PHP-CGI, Acrylic DNS and all Valet configs and logs.');

        $helper = $this->getHelperSet()->get('question');
        $question = new ConfirmationQuestion("Are you sure you want to proceed? yes/no\n", false);
        if (! $force && ! $helper->ask($input, $output, $question)) {
            return warning('Uninstall aborted.');
        }

        info('Stopping services...');

        Acrylic::stop();
        Nginx::stop();
        PhpCgi::stop();
        PhpCgiXdebug::stop();

        if ($purgeConfig) {
            info('Removing certificates for all secured sites...');
            Site::unsecureAll();
        } else {
            Site::untrustCertificates();
        }

        info('Removing Nginx...');
        Nginx::uninstall();

        info('Removing Acrylic DNS...');
        Acrylic::uninstall();

        info('Removing PHP-CGI...');
        PhpCgi::uninstall();

        info('Removing PHP-CGI Xdebug...');
        PhpCgiXdebug::uninstall();

        if ($purgeConfig) {
            info('Removing Valet configs...');
            Configuration::uninstall();
        }

        info("\nValet has been removed from your system.");

        output(
            "\n<fg=yellow>NOTE:</>".
            "\nRemove composer dependency with: <info>composer global remove cretueusebiu/valet-windows</info>".
            ($purgeConfig ? '' : "\nDelete the config files from: <info>~/.config/valet</info>").
            "\nDelete PHP from: <info>C:/php</info>"
        );
    })->descriptions('Uninstall the Valet services', ['--force' => 'Do a forceful uninstall of Valet']);

    /**
     * Determine if this is the latest release of Valet.
     */
    $app->command('on-latest-version', function () use ($version) {
        if (Valet::onLatestVersion($version)) {
            output('Yes');
        } else {
            output(sprintf('Your version of Valet (%s) is not the latest version available.', $version));
            output('Upgrade instructions can be found in the docs: https://github.com/cretueusebiu/valet-windows#upgrading');
        }
    })->descriptions('Determine if this is the latest version of Valet');

    /**
     * Install the sudoers.d entries so password is no longer required.
     */
    $app->command('trust [--off]', function ($off) {
        warning('This command is not required for Windows.');
    })->descriptions('This command is not required for Windows.');

    /**
     * Allow the user to change the version of php valet uses.
     */
    $app->command('use [phpVersion] [--force]', function ($phpVersion, $force) {
        warning('This command is not available for Windows.');
    })->descriptions('This command is not available for Windows.');

    /**
     * Tail log file.
     */
    $app->command('log [-f|--follow] [-l|--lines=] [key]', function ($follow, $lines, $key = null) {
        $defaultLogs = [
            'nginx' => VALET_HOME_PATH.'/Log/nginx-error.log',
            'nginxservice.err' => VALET_HOME_PATH.'/Log/nginxservice.err.log',
            'nginxservice.out' => VALET_HOME_PATH.'/Log/nginxservice.out.log',
            'nginxservice.wrapper' => VALET_HOME_PATH.'/Log/nginxservice.wrapper.log',
            'phpcgiservice.err' => VALET_HOME_PATH.'/Log/phpcgiservice.err.log',
            'phpcgiservice.out' => VALET_HOME_PATH.'/Log/phpcgiservice.out.log',
            'phpcgiservice.wrapper' => VALET_HOME_PATH.'/Log/phpcgiservice.wrapper.log',
        ];

        $configLogs = data_get(Configuration::read(), 'logs');
        if (! is_array($configLogs)) {
            $configLogs = [];
        }

        $logs = array_merge($defaultLogs, $configLogs);
        ksort($logs);

        if (! $key) {
            info(implode(PHP_EOL, [
                'In order to tail a log, pass the relevant log key (e.g. "nginx")',
                'along with any optional tail parameters (e.g. "-f" for follow).',
                null,
                'For example: "valet log nginx -f --lines=3"',
                null,
                'Here are the logs you might be interested in.',
                null,
            ]));

            table(
                ['Key', 'File'],
                collect($logs)->map(function ($file, $key) {
                    return [$key, $file];
                })->toArray()
            );

            info(implode(PHP_EOL, [
                null,
                'Tip: Set custom logs by adding a "logs" key/file object',
                'to your "'.Configuration::path().'" file.',
            ]));

            exit;
        }

        if (! isset($logs[$key])) {
            return warning('No logs found for ['.$key.'].');
        }

        $file = $logs[$key];
        if (! file_exists($file)) {
            return warning('Log path ['.$file.'] does not (yet) exist.');
        }

        $options = [];
        if ($follow) {
            $options[] = '-f';
        }
        if ((int) $lines) {
            $options[] = '-n '.(int) $lines;
        }

        $command = implode(' ', array_merge(['tail'], $options, [$file]));

        passthru($command);
    })->descriptions('Tail log file');

    /**
     * List the installed Valet services.
     */
    $app->command('services', function () {
        table(['Service', 'Windows Name', 'Status'], Valet::services());
        info('Use valet start/stop/restart [service] to change status (eg: valet restart nginx).');
    })->descriptions('List the installed Windows services.');

    /**
     * Configure or display the directory-listing setting.
     */
    $app->command('directory-listing [status]', function ($status = null) {
        $key = 'directory-listing';
        $config = Configuration::read();

        if (in_array($status, ['on', 'off'])) {
            $config[$key] = $status;
            Configuration::write($config);

            return output('Directory listing setting is now: '.$status);
        }

        $current = isset($config[$key]) ? $config[$key] : 'off';
        output('Directory listing is '.$current);
    })->descriptions('Determine directory-listing behavior. Default is off, which means a 404 will display.', [
        'status' => 'on or off. (default=off) will show a 404 page; [on] will display a listing if project folder exists but requested URI not found',
    ]);

    /**
     * Output diagnostics to aid in debugging Valet.
     */
    $app->command('diagnose [-p|--print] [--plain]', function ($print, $plain) {
        info('Running diagnostics... (this may take a while)');

        Diagnose::run($print, $plain);
    })->descriptions('Output diagnostics to aid in debugging Valet.', [
        '--print' => 'print diagnostics output while running',
        '--plain' => 'format clipboard output as plain text',
    ]);
}

/**
 * Load all of the Valet extensions.
 */
foreach (Valet::extensions() as $extension) {
    include $extension;
}

/**
 * Run the application.
 */
$app->run();
