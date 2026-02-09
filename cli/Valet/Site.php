<?php

namespace Valet;

use DomainException;
use phpseclib3\Crypt\RSA;
use phpseclib3\File\X509;

class Site
{
    protected $config;
    protected $cli;
    protected $files;

    /**
     * Create a new Site instance.
     *
     * @param  Configuration  $config
     * @param  CommandLine  $cli
     * @param  Filesystem  $files
     * @return void
     */
    public function __construct(Configuration $config, CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->files = $files;
        $this->config = $config;
    }

    /**
     * Get the name of the site.
     *
     * @param  string|null  $name
     * @return string
     */
    private function getRealSiteName($name)
    {
        if (! is_null($name)) {
            return $name;
        }

        if (is_string($link = $this->getLinkNameByCurrentDir())) {
            return $link;
        }

        return basename(getcwd());
    }

    /**
     * Get link name based on the current directory.
     *
     * @return null|string
     */
    private function getLinkNameByCurrentDir()
    {
        $count = count($links = $this->links()->where('path', getcwd()));

        if ($count == 1) {
            return $links->shift()['site'];
        }

        if ($count > 1) {
            throw new DomainException("There are {$count} links related to the current directory, please specify the name: valet unlink <name>.");
        }
    }

    /**
     * Get the real hostname for the given path, checking links.
     *
     * @param  string  $path
     * @return string|null
     */
    public function host($path)
    {
        foreach ($this->files->scandir($this->sitesPath()) as $link) {
            if ($resolved = realpath($this->sitesPath($link)) === $path) {
                return $link;
            }
        }

        return basename($path);
    }

    /**
     * Link the current working directory with the given name.
     *
     * @param  string  $target
     * @param  string  $link
     * @return string
     */
    public function link($target, $link)
    {
        $this->files->ensureDirExists(
            $linkPath = $this->sitesPath(), user()
        );

        $this->config->prependPath($linkPath);

        $this->files->symlinkAsUser($target, $linkPath.'/'.$link);

        return $linkPath.'/'.$link;
    }

    /**
     * Pretty print out all links in Valet.
     *
     * @return \Illuminate\Support\Collection
     */
    public function links()
    {
        $certsPath = $this->certificatesPath();

        $this->files->ensureDirExists($certsPath, user());

        $certs = $this->getCertificates($certsPath);

        return $this->getSites($this->sitesPath(), $certs);
    }

    /**
     * Pretty print out all parked links in Valet.
     *
     * @return \Illuminate\Support\Collection
     */
    public function parked()
    {
        $certs = $this->getCertificates();

        $links = $this->getSites($this->sitesPath(), $certs);

        $config = $this->config->read();
        $parkedLinks = collect();
        foreach (array_reverse($config['paths']) as $path) {
            if ($path === $this->sitesPath()) {
                continue;
            }

            // Only merge on the parked sites that don't interfere with the linked sites
            $sites = $this->getSites($path, $certs)->filter(function ($site, $key) use ($links) {
                return ! $links->has($key);
            });

            $parkedLinks = $parkedLinks->merge($sites);
        }

        return $parkedLinks;
    }

    /**
     * Get all sites which are proxies (not Links, and contain proxy_pass directive).
     *
     * @return \Illuminate\Support\Collection
     */
    public function proxies()
    {
        $dir = $this->nginxPath();
        $tld = $this->config->read()['tld'];
        $links = $this->links();
        $certs = $this->getCertificates();

        if (! $this->files->exists($dir)) {
            return collect();
        }

        $proxies = collect($this->files->scandir($dir))
        ->filter(function ($site, $key) use ($tld) {
            // keep sites that match our TLD
            return ends_with($site, ".$tld.conf");
        })->map(function ($site, $key) use ($tld) {
            // remove the TLD suffix for consistency
            return str_replace(".$tld.conf", '', $site);
        })->reject(function ($site, $key) use ($links) {
            return $links->has($site);
        })->mapWithKeys(function ($site) {
            $host = $this->getProxyHostForSite($site) ?: '(other)';

            return [$site => $host];
        })->reject(function ($host, $site) {
            // If proxy host is null, it may be just a normal SSL stub, or something else; either way we exclude it from the list
            return $host === '(other)';
        })->map(function ($host, $site) use ($certs, $tld) {
            $secured = $certs->has($site);
            $url = ($secured ? 'https' : 'http').'://'.$site.'.'.$tld;

            return [
                'site' => $site,
                'secured' => $secured ? ' X' : '',
                'url' => $url,
                'path' => $host,
            ];
        });

        return $proxies;
    }

    /**
     * Identify whether a site is for a proxy by reading the host name from its config file.
     *
     * @param  string  $site  Site name without TLD
     * @param  string  $configContents  Config file contents
     * @return string|null
     */
    public function getProxyHostForSite($site, $configContents = null)
    {
        $siteConf = $configContents ?: $this->getSiteConfigFileContents($site);

        if (empty($siteConf)) {
            return null;
        }

        $host = null;
        if (preg_match('~proxy_pass\s+(?<host>https?://.*)\s*;~', $siteConf, $patterns)) {
            $host = trim($patterns['host']);
        }

        return $host;
    }

    public function getSiteConfigFileContents($site, $suffix = null)
    {
        $config = $this->config->read();
        $suffix = $suffix ?: '.'.$config['tld'];
        $file = str_replace($suffix, '', $site).$suffix;

        return $this->files->exists($this->nginxPath($file)) ? $this->files->get($this->nginxPath($file)) : null;
    }

    /**
     * Get all certificates from config folder.
     *
     * @param  string  $path
     * @return \Illuminate\Support\Collection
     */
    public function getCertificates($path = null)
    {
        $path = $path ?: $this->certificatesPath();

        $this->files->ensureDirExists($path, user());

        $config = $this->config->read();

        return collect($this->files->scandir($path))->filter(function ($value, $key) {
            return ends_with($value, '.crt');
        })->map(function ($cert) use ($config) {
            $certWithoutSuffix = substr($cert, 0, -4);
            $trimToString = '.';

            // If we have the cert ending in our tld strip that tld specifically
            // if not then just strip the last segment for  backwards compatibility.
            if (ends_with($certWithoutSuffix, $config['tld'])) {
                $trimToString .= $config['tld'];
            }

            return substr($certWithoutSuffix, 0, strrpos($certWithoutSuffix, $trimToString));
        })->flip();
    }

    /**
     * @deprecated Use getSites instead which works for both normal and symlinked paths.
     *
     * @param  string  $path
     * @param  \Illuminate\Support\Collection  $certs
     * @return \Illuminate\Support\Collection
     */
    public function getLinks($path, $certs)
    {
        return $this->getSites($path, $certs);
    }

    /**
     * Get list of sites and return them formatted
     * Will work for symlink and normal site paths.
     *
     * @param  string  $path
     * @param  \Illuminate\Support\Collection  $certs
     * @return \Illuminate\Support\Collection
     */
    public function getSites($path, $certs)
    {
        $config = $this->config->read();

        $this->files->ensureDirExists($path, user());

        return collect($this->files->scandir($path))->mapWithKeys(function ($site) use ($path) {
            $sitePath = $path.'/'.$site;

            if ($this->files->isLink($sitePath)) {
                $realPath = $this->files->readLink($sitePath);
            } else {
                $realPath = $this->files->realpath($sitePath);
            }

            return [$site => $realPath];
        })->filter(function ($path) {
            return $this->files->isDir($path);
        })->map(function ($path, $site) use ($certs, $config) {
            $secured = $certs->has($site);
            $url = ($secured ? 'https' : 'http').'://'.$site.'.'.$config['tld'];

            return [
                'site' => $site,
                'secured' => $secured ? ' X' : '',
                'url' => $url,
                'path' => $path,
            ];
        });
    }

    /**
     * Unlink the given symbolic link.
     *
     * @param  string  $name
     * @return void
     */
    public function unlink($name)
    {
        $name = $this->getRealSiteName($name);

        if ($this->files->exists($path = $this->sitesPath($name))) {
            $this->files->unlink($path);
        }

        return $name;
    }

    /**
     * Remove all broken symbolic links.
     *
     * @return void
     */
    public function pruneLinks()
    {
        $this->files->ensureDirExists($this->sitesPath(), user());

        $this->files->removeBrokenLinksAt($this->sitesPath());
    }

    /**
     * Resecure all currently secured sites with a fresh tld.
     *
     * @param  string  $oldTld
     * @param  string  $tld
     * @return void
     */
    public function resecureForNewTld($oldTld, $tld)
    {
        if (! $this->files->exists($this->certificatesPath())) {
            return;
        }

        $secured = $this->secured();

        foreach ($secured as $url) {
            $newUrl = str_replace('.'.$oldTld, '.'.$tld, $url);
            $siteConf = $this->getSiteConfigFileContents($url, '.'.$oldTld);

            if (! empty($siteConf) && strpos($siteConf, '# valet stub: proxy.valet.conf') === 0) {
                // proxy config
                $this->unsecure($url);
                $this->secure($newUrl, $this->replaceOldDomainWithNew($siteConf, $url, $newUrl));
            } else {
                // normal config
                $this->unsecure($url);
                $this->secure($newUrl);
            }
        }
    }

    /**
     * Parse Nginx site config file contents to swap old domain to new.
     *
     * @param  string  $siteConf  Nginx site config content
     * @param  string  $old  Old domain
     * @param  string  $new  New domain
     * @return string
     */
    public function replaceOldDomainWithNew($siteConf, $old, $new)
    {
        $lookups = [];
        $lookups[] = '~server_name .*;~';
        $lookups[] = '~error_log .*;~';
        $lookups[] = '~ssl_certificate_key .*;~';
        $lookups[] = '~ssl_certificate .*;~';

        foreach ($lookups as $lookup) {
            preg_match($lookup, $siteConf, $matches);
            foreach ($matches as $match) {
                $replaced = str_replace($old, $new, $match);
                $siteConf = str_replace($match, $replaced, $siteConf);
            }
        }

        return $siteConf;
    }

    /**
     * Get all of the URLs that are currently secured.
     *
     * @return array
     */
    public function publishParkedNginxConf($parkedPath)
    {
        $parked = $this->parked();

        // TODO
    }

    /**
     * Get the site URL from a directory if it's a valid Valet site.
     *
     * @param  string  $directory
     * @return string|false
     */
    public function usePhp($phpVersion, $directory)
    {
        $site = $this->getSiteUrl($directory);

        if (! $site) {
            throw new DomainException("The {$directory} site could not be found in Valet's site list.");
        }

        // Remove isolation for this site
        if ($phpVersion == 'default') {
            // Example output: "7.4"
            $oldCustomPhpVersion = $this->customPhpVersion($site);
            $this->removeIsolation($site);
            \Nginx::restart();
            info("The site [$site] is now using the default PHP version.");

            return;
        }

        $php = $this->config->getPhpByVersion($phpVersion);

        if (empty($php)) {
            warning("Cannot find PHP [$phpVersion] in the list.");
        }

        $this->installSiteConfig($site, $php['version']);

        info('Restarting Nginx...');
        \Nginx::stop();
        \Nginx::restart();
        info("The site [$site] is now using $phpVersion.");
    }

    /**
     * Get the site URL from a directory if it's a valid Valet site.
     *
     * @param  string  $directory
     * @return string|false
     */
    public function getSiteUrl($directory)
    {
        $tld = $this->config->read()['tld'];

        // Allow user to use dot as current dir's site `--site=.`
        if ($directory == '.' || $directory == './') {
            $directory = $this->host(getcwd());
        }

        // Remove .tld from sitename if it was provided
        $directory = str_replace('.'.$tld, '', $directory);

        if (! $this->parked()->merge($this->links())->where('site', $directory)->count() > 0) {
            // Invalid directory provided
            warning('Invalid site or directory given');

            return false;
        }

        return $directory.'.'.$tld;
    }

    /**
     * Extract PHP version of exising nginx conifg.
     *
     * @param  string  $url
     * @return string|void
     */
    public function customPhpVersion($url)
    {
        if ($this->files->exists($this->nginxPath($url))) {
            $siteConf = $this->files->get($this->nginxPath($url));

            if (starts_with($siteConf, '# Valet isolated PHP version')) {
                $firstLine = explode(PHP_EOL, $siteConf)[0];

                return trim(str_replace('# Valet isolated PHP version : ', '', $firstLine));
            }
        }
    }

    /**
     * Get all of the URLs that are currently secured.
     *
     * @return array
     */
    public function secured()
    {
        return collect($this->files->scandir($this->certificatesPath()))
                    ->map(function ($file) {
                        return str_replace(['.key', '.csr', '.crt', '.conf'], '', $file);
                    })->unique()->values()->all();
    }

    /**
     * Secure the given host with TLS.
     *
     * @param  string  $url
     * @param  string  $siteConf  pregenerated Nginx config file contents
     * @return void
     */
    public function secure($url, $siteConf = null)
    {
        // Extract in order to later preserve custom PHP version config when securing
        $phpVersion = $this->customPhpVersion($url);
        $this->unsecure($url);

        $this->files->ensureDirExists($this->certificatesPath(), user());

        $this->files->ensureDirExists($this->nginxPath(), user());

        $this->createCertificate($url);

        $siteConf = $this->buildSecureNginxServer($url, $siteConf);

        // If the user had isolated the PHP version for this site, swap out the PHP version
        if ($phpVersion) {
            $php = $this->config->getPhpByVersion($phpVersion);
            $siteConf = $this->replacePhpVersionInSiteConf($siteConf, $php['port'], $phpVersion);
        }

        $this->files->putAsUser(
            $this->nginxPath($url), $this->buildSecureNginxServer($url, $siteConf)
        );
    }

    /**
     * Get the port of the given host.
     *
     * @param  string  $url
     * @return int
     */
    public function port(string $url): int
    {
        if ($this->files->exists($path = $this->nginxPath($url))) {
            if (strpos($this->files->get($path), '443') !== false) {
                return 443;
            }
        }

        return 80;
    }

    /**
     * Create and trust a certificate for the given URL.
     *
     * @param  string  $url
     * @return void
     */
    public function createCertificate($url)
    {
        $keyPath = $this->certificatesPath($url, 'key');
        $csrPath = $this->certificatesPath($url, 'csr');
        $crtPath = $this->certificatesPath($url, 'crt');

        $this->createPrivateKey($keyPath);
        $this->createSigningRequest($url, $keyPath, $csrPath);
        $this->createSignedCertificate($keyPath, $csrPath, $crtPath);

        $this->trustCertificate($crtPath);
    }

    /**
     * Create the private key for the TLS certificate.
     *
     * @param  string  $keyPath
     * @return void
     */
    public function createPrivateKey(string $keyPath)
    {
        /** @var \phpseclib3\Crypt\RSA\PrivateKey */
        $key = RSA::createKey();

        $this->files->putAsUser($keyPath, $key->toString('PKCS1'));
    }

    /**
     * Create the signing request for the TLS certificate.
     *
     * @param  string  $url
     * @param  string  $keyPath
     * @param  string  $csrPath
     * @return void
     */
    public function createSigningRequest(string $url, string $keyPath, string $csrPath)
    {
        /** @var \phpseclib3\Crypt\RSA\PrivateKey */
        $privKey = RSA::load($this->files->get($keyPath));

        $x509 = new X509();
        $x509->setPrivateKey($privKey);
        $x509->setDNProp('commonname', $url);

        $x509->loadCSR($x509->saveCSR($x509->signCSR()));

        $x509->setExtension('id-ce-subjectAltName', [
            ['dNSName' => $url],
            ['dNSName' => "*.$url"],
        ]);

        $x509->setExtension('id-ce-keyUsage', [
            'digitalSignature',
            'nonRepudiation',
            'keyEncipherment',
        ]);

        $csr = $x509->saveCSR($x509->signCSR());

        $this->files->putAsUser($csrPath, $csr);
    }

    /**
     * Create the signed TLS certificate.
     *
     * @param  string  $keyPath
     * @param  string  $csrPath
     * @param  string  $crtPath
     * @return void
     */
    public function createSignedCertificate(string $keyPath, string $csrPath, string $crtPath)
    {
        /** @var \phpseclib3\Crypt\RSA\PrivateKey */
        $privKey = RSA::load($this->files->get($keyPath));
        $privKey = $privKey->withPadding(RSA::SIGNATURE_PKCS1);

        $subject = new X509();
        $subject->loadCSR($this->files->get($csrPath));
        $subject->setPublicKey($privKey->getPublicKey());

        $issuer = new X509();
        $issuer->setPrivateKey($privKey);
        $issuer->setDN($subject->getDN());

        $x509 = new X509();
        $x509->makeCA();
        $x509->setStartDate('-1 day');

        $result = $x509->sign($issuer, $subject);
        $certificate = $x509->saveX509($result);

        $this->files->putAsUser($crtPath, $certificate);
    }

    /**
     * Trust the given certificate file in the Windows Certmgr.
     *
     * @param  string  $crtPath
     * @return void
     */
    public function trustCertificate(string $crtPath)
    {
        $this->cli->runOrExit(sprintf('cmd "/C certutil -addstore "Root" "%s""', $crtPath), function ($code, $output) {
            error("Failed to trust certificate: $output");
        });
    }

    /**
     * Build the Nginx server configuration for the given Valet site.
     *
     * @param  string  $valetSite
     * @param  string  $phpVersion
     * @return string
     */
    public function installSiteConfig($valetSite, $phpVersion)
    {
        $phpVersion = $phpVersion ? $phpVersion : $this->config->get('default_php');

        $php = $this->config->getPhpByVersion($phpVersion);

        if (empty($php)) {
            warning("Cannot find PHP [$phpVersion] in the list.");

            return;
        }

        if ($this->files->exists($this->nginxPath($valetSite))) {
            $siteConf = $this->files->get($this->nginxPath($valetSite));
        } else {
            $siteConf = $this->files->get(__DIR__.'/../stubs/unsecure.valet.conf');
            $siteConf = str_replace(
                ['VALET_HOME_PATH', 'VALET_SERVER_PATH', 'VALET_STATIC_PREFIX', 'VALET_SITE', 'HOME_PATH'],
                [$this->valetHomePath(), VALET_SERVER_PATH, VALET_STATIC_PREFIX, $valetSite, $_SERVER['HOME']],
                $siteConf
            );
        }

        $siteConf = $this->replacePhpVersionInSiteConf($siteConf, $php['port'], $php['version']);

        $this->files->putAsUser($this->nginxPath($valetSite), $siteConf);
    }

    /**
     * Remove PHP Version isolation from a specific site.
     *
     * @param  string  $valetSite
     * @return void
     */
    public function removeIsolation($valetSite)
    {
        $existingSiteConf = $this->getSiteConfigFileContents($valetSite);

        if (empty($existingSiteConf)) {
            $siteConf = $this->buildSecureNginxServer($valetSite);
        }

        $siteConf = $this->replacePhpVersionInSiteConf($valetSite, '$valet_php_port');

        // If a site has an SSL certificate, we need to keep its custom config file
        if ($this->files->exists($this->certificatesPath($valetSite, 'crt'))) {
            $this->files->putAsUser($this->nginxPath($valetSite), $siteConf);
        } else {
            if ($existingSiteConf) {
                $this->files->putAsUser($this->nginxPath($valetSite), $siteConf);
            }
        }
    }

    /**
     * Build the TLS secured Nginx server for the given URL.
     *
     * @param  string  $url
     * @param  string  $siteConf  (optional) Nginx site config file content
     * @return string
     */
    public function buildSecureNginxServer($url, $siteConf = null)
    {
        if ($siteConf === null) {
            $siteConf = $this->files->get(__DIR__.'/../stubs/secure.valet.conf');
        }

        $path = $this->certificatesPath();

//        , 'VALET_PHP_PORT', 'VALET_ISOLATED_PHP_VERSION'
//    , $php['port'], $php['version']

        return str_replace(
            ['VALET_HOME_PATH', 'VALET_SERVER_PATH', 'VALET_STATIC_PREFIX', 'VALET_SITE', 'VALET_CERT', 'VALET_KEY', 'HOME_PATH'],
            [$this->valetHomePath(), VALET_SERVER_PATH, VALET_STATIC_PREFIX, $url, $path.'/'.$url.'.crt', $path.'/'.$url.'.key', $_SERVER['HOME']],
            $siteConf
        );
    }

    /**
     * Replace .sock file in an Nginx site configuration file contents.
     *
     * @param  string  $siteConf
     * @param  string  $phpPort
     */
    public function replacePhpVersionInSiteConf($siteConf, $phpPort, $phpVersion = null)
    {
        $siteConf = preg_replace('/127.0.0.1:[0-9]*;/', "127.0.0.1:{$phpPort};", $siteConf);
        $siteConf = str_replace('127.0.0.1:$valet_php_port;', "127.0.0.1:{$phpPort};", $siteConf);

        // Remove `Valet isolated PHP version` line from config
        $siteConf = preg_replace('/# Valet isolated PHP version.*\n/', '', $siteConf);

        if ($phpVersion) {
            $siteConf = '# Valet isolated PHP version : '.$phpVersion.PHP_EOL.$siteConf;
        }

        return $siteConf;
    }

    /**
     * Unsecure the given URL so that it will use HTTP again.
     *
     * @param  string  $url
     * @return void
     */
    public function unsecure($url)
    {
        // Extract in order to later preserve custom PHP version config when unsecuring. Example output: "8.1.2"
        $phpVersion = $this->customPhpVersion($url);

        if ($this->files->exists($this->certificatesPath($url, 'crt'))) {
            $this->files->unlink($this->nginxPath($url));

            $this->files->unlink($this->certificatesPath($url, 'key'));
            $this->files->unlink($this->certificatesPath($url, 'csr'));
            $this->files->unlink($this->certificatesPath($url, 'crt'));
        }

        $this->cli->run(sprintf('cmd "/C certutil -delstore "Root" "%s""', $url));

        // If the user had isolated the PHP version for this site, swap out .sock file
        if ($phpVersion) {
            $this->installSiteConfig($url, $phpVersion);
        }
    }

    public function unsecureAll()
    {
        $tld = $this->config->read()['tld'];

        $secured = $this->parked()
            ->merge($this->links())
            ->sort()
            ->where('secured', ' X');

        if ($secured->count() === 0) {
            return info('No sites to unsecure. You may list all servable sites or links by running <comment>valet parked</comment> or <comment>valet links</comment>.');
        }

        info('Attempting to unsecure the following sites:');
        table(['Site', 'SSL', 'URL', 'Path'], $secured->toArray());

        foreach ($secured->pluck('site') as $url) {
            $this->unsecure($url.'.'.$tld);
        }

        $remaining = $this->parked()
            ->merge($this->links())
            ->sort()
            ->where('secured', ' X');
        if ($remaining->count() > 0) {
            warning('We were not succesful in unsecuring the following sites:');
            table(['Site', 'SSL', 'URL', 'Path'], $remaining->toArray());
        }
        info('unsecure --all was successful.');
    }

    /**
     * Untrust all certificates.
     *
     * @return void
     */
    public function untrustCertificates()
    {
        $secured = $this->parked()
            ->merge($this->links())
            ->sort()
            ->where('secured', ' X');

        if ($secured->isEmpty()) {
            return;
        }

        $tld = $this->config->get('tld');

        foreach ($secured->pluck('site') as $domain) {
            $this->cli->run(sprintf('cmd "/C certutil -delstore "Root" "%s""', $domain.'.'.$tld));
        }
    }

    /**
     * Build the Nginx proxy config for the specified domain.
     *
     * @param  string  $url  The domain name to serve
     * @param  string  $host  The URL to proxy to, eg: http://127.0.0.1:8080
     * @return string
     */
    public function proxyCreate($url, $host)
    {
        if (! preg_match('~^https?://.*$~', $host)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid URL', $host));
        }

        $tld = $this->config->read()['tld'];
        if (! ends_with($url, '.'.$tld)) {
            $url .= '.'.$tld;
        }

        $siteConf = $this->files->get(__DIR__.'/../stubs/proxy.valet.conf');

        $siteConf = str_replace(
            ['VALET_HOME_PATH', 'VALET_SERVER_PATH', 'VALET_STATIC_PREFIX', 'VALET_SITE', 'VALET_PROXY_HOST'],
            [$this->valetHomePath(), VALET_SERVER_PATH, VALET_STATIC_PREFIX, $url, $host],
            $siteConf
        );

        $this->secure($url, $siteConf);

        info('Valet will now proxy [https://'.$url.'] traffic to ['.$host.'].');
    }

    /**
     * Unsecure the given URL so that it will use HTTP again.
     *
     * @param  string  $url
     * @return void
     */
    public function proxyDelete($url)
    {
        $tld = $this->config->read()['tld'];
        if (! ends_with($url, '.'.$tld)) {
            $url .= '.'.$tld;
        }

        $this->unsecure($url);
        $this->files->unlink($this->nginxPath($url));

        info('Valet will no longer proxy [https://'.$url.'].');
    }

    public function valetHomePath()
    {
        return VALET_HOME_PATH;
    }

    /**
     * Get the path to Nginx site configuration files.
     */
    public function nginxPath($additionalPath = null)
    {
        if ($additionalPath && ! ends_with($additionalPath, '.conf')) {
            $additionalPath = $additionalPath.'.conf';
        }

        return $this->valetHomePath().'/Nginx'.($additionalPath ? '/'.$additionalPath : '');
    }

    /**
     * Get the path to the linked Valet sites.
     *
     * @return string
     */
    public function sitesPath($link = null)
    {
        return $this->valetHomePath().'/Sites'.($link ? '/'.$link : '');
    }

    /**
     * Get the path to the Valet TLS certificates.
     *
     * @return string
     */
    public function certificatesPath($url = null, $extension = null)
    {
        $url = $url ? '/'.$url : '';
        $extension = $extension ? '.'.$extension : '';

        return $this->valetHomePath().'/Certificates'.$url.$extension;
    }
}
