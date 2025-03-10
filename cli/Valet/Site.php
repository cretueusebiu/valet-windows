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
        $this->unsecure($url);

        $this->files->ensureDirExists($this->caPath(), user());

        $this->files->ensureDirExists($this->certificatesPath(), user());

        $this->files->ensureDirExists($this->nginxPath(), user());

        $this->createCa();

        $this->createCertificate($url);

        $this->files->putAsUser(
            $this->nginxPath($url), $this->buildSecureNginxServer($url, $siteConf)
        );
    }

    /**
     * If CA and root certificates are nonexistent, crete them and trust the root cert.
     *
     * @return void
     */
    public function createCa()
    {
        $caPemPath = $this->caPath('LaravelValetCASelfSigned.crt');
        $caKeyPath = $this->caPath('LaravelValetCASelfSigned.key');

        if ($this->files->exists($caKeyPath) && $this->files->exists($caPemPath)) {
            return;
        }

        $oName = 'Laravel Valet CA Self Signed Organization';
        $cName = 'Laravel Valet CA Self Signed CN';

        if ($this->files->exists($caKeyPath)) {
            $this->files->unlink($caKeyPath);
        }
        if ($this->files->exists($caPemPath)) {
            $this->files->unlink($caPemPath);
        }

        $this->cli->runOrExit(sprintf('cmd "/C certutil -delstore "Root" "%s""', $cName), function ($code, $output) {
            error("Failed to trust certificate: $output");
        });

        $CAPrivKey = RSA::createKey()->withPadding(RSA::ENCRYPTION_PKCS1 | RSA::SIGNATURE_PKCS1);
        $CAPubKey = $CAPrivKey->getPublicKey();

        $CASubject = new X509;
        $CASubject->setDNProp('emailaddress', 'rootcertificate@laravel.valet');
        $CASubject->setDNProp('ou', 'Developers');
        $CASubject->setDNProp('cn', $cName);
        $CASubject->setDNProp('o', $oName);
        $CASubject->setPublicKey($CAPubKey);

        $CAIssuer = new X509;
        $CAIssuer->setPrivateKey($CAPrivKey);
        $CAIssuer->setDN($CASubject->getDN());

        $x509 = new X509;
        $x509->makeCA();
        $result = $x509->sign($CAIssuer, $CASubject);

        $CAPem = $x509->saveX509($result);

        $this->files->putAsUser($caPemPath, $CAPem);
        $this->files->putAsUser($caKeyPath, $CAPrivKey);

        $this->trustCa($caPemPath);
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
        $caPemPath = $this->caPath('LaravelValetCASelfSigned.crt');
        $caKeyPath = $this->caPath('LaravelValetCASelfSigned.key');

        $this->createPrivateKey($keyPath);
        $this->createSigningRequest($url, $keyPath, $csrPath);
        $this->createSignedCertificate($keyPath, $csrPath, $crtPath, $caPemPath, $caKeyPath);

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
     * @param  string  $caPemPath
     * @param  string  $caKeyPath*
     * @return void
     */
    public function createSignedCertificate(string $keyPath, string $csrPath, string $crtPath, string $caPemPath, string $caKeyPath)
    {
        /** @var \phpseclib3\Crypt\RSA\PrivateKey */
        $privKey = RSA::load($this->files->get($keyPath));
        $privKey = $privKey->withPadding(RSA::SIGNATURE_PKCS1);

        $subject = new X509();
        $subject->loadCSR($this->files->get($csrPath));
        $subject->setPublicKey($privKey->getPublicKey());
        $subject->setDNProp('emailaddress', 'valet');
        $subject->setDNProp('ou', 'Valet');
        $subject->setDNProp('l', 'Valet');
        $subject->setDNProp('o', 'Valet');
        $subject->setDNProp('st', 'MN');
        $subject->setDNProp('c', 'US');

        $ca = new X509();
        $ca->loadX509($this->files->get($caPemPath));

        $caPrivKey = RSA::load($this->files->get($caKeyPath))->withPadding(RSA::ENCRYPTION_PKCS1 | RSA::SIGNATURE_PKCS1);

        $issuer = new X509();
        $issuer->setPrivateKey($caPrivKey);
        $issuer->setDN($ca->getIssuerDN());

        $x509 = new X509();

        $result = $x509->sign($issuer, $subject);
        $certificate = $x509->saveX509($result);

        $this->files->putAsUser($crtPath, $certificate);
    }

    /**
     * Trust the given root certificate file in the Windows Certmgr.
     *
     * @param  string  $pemPath
     * @return void
     */
    public function trustCa($caPemPath)
    {
        echo $caPemPath;
        $this->cli->runOrExit(sprintf('cmd "/C certutil -addstore "Root" "%s""', $caPemPath), function ($code, $output) {
            error("Failed to trust certificate: $output");
        });
    }

    /**
     * Trust the given certificate file in the Windows Certmgr.
     *
     * @param  string  $crtPath
     * @return void
     */
    public function trustCertificate(string $crtPath)
    {
        $this->cli->runOrExit(sprintf('cmd "/C certutil -addstore "CA" "%s""', $crtPath), function ($code, $output) {
            error("Failed to trust certificate: $output");
        });
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

        return str_replace(
            ['VALET_HOME_PATH', 'VALET_SERVER_PATH', 'VALET_STATIC_PREFIX', 'VALET_SITE', 'VALET_CERT', 'VALET_KEY', 'HOME_PATH'],
            [$this->valetHomePath(), VALET_SERVER_PATH, VALET_STATIC_PREFIX, $url, $path.'/'.$url.'.crt', $path.'/'.$url.'.key', $_SERVER['HOME']],
            $siteConf
        );
    }

    /**
     * Unsecure the given URL so that it will use HTTP again.
     *
     * @param  string  $url
     * @return void
     */
    public function unsecure($url)
    {
        if ($this->files->exists($this->certificatesPath($url, 'crt'))) {
            $this->files->unlink($this->nginxPath($url));

            $this->files->unlink($this->certificatesPath($url, 'key'));
            $this->files->unlink($this->certificatesPath($url, 'csr'));
            $this->files->unlink($this->certificatesPath($url, 'crt'));
        }

        $this->cli->run(sprintf('cmd "/C certutil -delstore "CA" "%s""', $url));

        $this->cli->run(sprintf('cmd "/C certutil -delstore "Root" "%s""', $url));
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
            $this->cli->run(sprintf('cmd "/C certutil -delstore "CA" "%s""', $domain.'.'.$tld));

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
     * Get the path to the Valet CA certificates.
     *
     * @return string
     */
    public function caPath($caFile = null)
    {
        return $this->valetHomePath().'/CA'.($caFile ? '/'.$caFile : '');
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
