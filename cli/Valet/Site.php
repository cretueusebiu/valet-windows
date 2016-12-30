<?php

namespace Valet;

use DomainException;
use phpseclib\Crypt\RSA;
use phpseclib\File\X509;

class Site
{
    var $config, $cli, $files;

    /**
     * Create a new Site instance.
     *
     * @param  Configuration  $config
     * @param  CommandLine  $cli
     * @param  Filesystem  $files
     */
    function __construct(Configuration $config, CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->files = $files;
        $this->config = $config;
    }

    /**
     * Get the real hostname for the given path, checking links.
     *
     * @param  string  $path
     * @return string|null
     */
    function host($path)
    {
        foreach ($this->files->scandir($this->sitesPath()) as $link) {
            if ($resolved = realpath($this->sitesPath().'/'.$link) === $path) {
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
    function link($target, $link)
    {
        $this->files->ensureDirExists(
            $linkPath = $this->sitesPath(), user()
        );

        $this->config->prependPath($linkPath);

        $this->files->symlinkAsUser($target, $linkPath.'/'.$link);

        return $linkPath.'/'.$link;
    }

    /**
     * Unlink the given symbolic link.
     *
     * @param  string  $name
     * @return void
     */
    function unlink($name)
    {
        if ($this->files->exists($path = $this->sitesPath().'/'.$name)) {
            $this->files->unlink($path);
        }
    }

    /**
     * Remove all broken symbolic links.
     *
     * @return void
     */
    function pruneLinks()
    {
        $this->files->ensureDirExists($this->sitesPath(), user());

        $this->files->removeBrokenLinksAt($this->sitesPath());
    }

    /**
     * Resecure all currently secured sites with a fresh domain.
     *
     * @param  string  $oldDomain
     * @param  string  $domain
     * @return void
     */
    function resecureForNewDomain($oldDomain, $domain)
    {
        if (! $this->files->exists($this->certificatesPath())) {
            return;
        }

        $secured = $this->secured();

        foreach ($secured as $url) {
            $this->unsecure($url);
        }

        foreach ($secured as $url) {
            $this->secure(str_replace('.'.$oldDomain, '.'.$domain, $url));
        }
    }

    /**
     * Get all of the URLs that are currently secured.
     *
     * @return array
     */
    function secured()
    {
        return collect($this->files->scandir($this->certificatesPath()))
                    ->map(function ($file) {
                        return str_replace(['.key', '.csr', '.crt'], '', $file);
                    })->unique()->values()->all();
    }

    /**
     * Secure the given host with TLS.
     *
     * @param  string  $url
     * @return void
     */
    function secure($url)
    {
        $this->unsecure($url);

        $this->files->ensureDirExists($this->certificatesPath(), user());

        $this->createCertificate($url);

        $this->files->putAsUser(
            VALET_HOME_PATH."/Nginx/$url.conf", $this->buildSecureNginxServer($url)
        );
    }

    /**
     * Create and trust a certificate for the given URL.
     *
     * @param  string  $url
     * @return void
     */
    function createCertificate($url)
    {
        $keyPath = $this->certificatesPath().'/'.$url.'.key';
        $csrPath = $this->certificatesPath().'/'.$url.'.csr';
        $crtPath = $this->certificatesPath().'/'.$url.'.crt';

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
    function createPrivateKey($keyPath)
    {
        $key = (new RSA())->createKey(2048);

        $this->files->putAsUser($keyPath, $key['privatekey']);
    }

    /**
     * Create the signing request for the TLS certificate.
     *
     * @param  string  $keyPath
     * @return void
     */
    function createSigningRequest($url, $keyPath, $csrPath)
    {
        $privKey = new RSA();
        $privKey->loadKey($this->files->get($keyPath));

        $x509 = new X509();
        $x509->setPrivateKey($privKey);
        $x509->setDNProp('commonname', $url);

        $csr = $x509->saveCSR($x509->signCSR());

        $this->files->putAsUser($csrPath, $csr);
    }

    /**
     * Create the signed TLS certificate.
     *
     * @param  string $keyPath
     * @param  string $csrPath
     * @param  string $crtPath
     * @return void
     */
    function createSignedCertificate($keyPath, $csrPath, $crtPath)
    {
        $privKey = new RSA();
        $privKey->loadKey($this->files->get($keyPath));

        $subject = new X509();
        $subject->loadCSR($this->files->get($csrPath));

        $issuer = new X509();
        $issuer->setPrivateKey($privKey);
        $issuer->setDN($subject->getDN());

        $x509 = new X509();
        $x509->makeCA();

        $result = $x509->sign($issuer, $subject, 'sha256WithRSAEncryption');
        $certificate = $x509->saveX509($result);

        $this->files->putAsUser($crtPath, $certificate);
    }

    /**
     * Trust the given certificate file in the Mac Keychain.
     *
     * @param  string  $crtPath
     * @return void
     */
    function trustCertificate($crtPath)
    {
        $this->cli->run(sprintf('cmd "/C certutil -addstore "Root" "%s""', $crtPath));
    }

    /**
     * Build the TLS secured Nginx server for the given URL.
     *
     * @param  string  $url
     * @return string
     */
    function buildSecureNginxServer($url)
    {
        $path = $this->certificatesPath();

        return str_replace(
            ['VALET_HOME_PATH', 'VALET_SERVER_PATH', 'VALET_STATIC_PREFIX', 'VALET_SITE', 'VALET_CERT', 'VALET_KEY', 'HOME_PATH'],
            [VALET_HOME_PATH, VALET_SERVER_PATH, VALET_STATIC_PREFIX, $url, $path.'/'.$url.'.crt', $path.'/'.$url.'.key', $_SERVER['HOME']],
            $this->files->get(__DIR__.'/../stubs/secure.valet.conf')
        );
    }

    /**
     * Unsecure the given URL so that it will use HTTP again.
     *
     * @param  string  $url
     * @return void
     */
    function unsecure($url)
    {
        if ($this->files->exists($this->certificatesPath().'/'.$url.'.crt')) {
            $this->files->unlink(VALET_HOME_PATH."/Nginx/$url.conf");

            $this->files->unlink($this->certificatesPath().'/'.$url.'.key');
            $this->files->unlink($this->certificatesPath().'/'.$url.'.csr');
            $this->files->unlink($this->certificatesPath().'/'.$url.'.crt');

            $this->cli->run(sprintf('cmd "/C certutil -delstore "Root" "%s""', $url));
        }
    }

    /**
     * Get the path to the linked Valet sites.
     *
     * @return string
     */
    function sitesPath()
    {
        return VALET_HOME_PATH.'/Sites';
    }

    /**
     * Get the path to the Valet TLS certificates.
     *
     * @return string
     */
    function certificatesPath()
    {
        return VALET_HOME_PATH.'/Certificates';
    }
}
