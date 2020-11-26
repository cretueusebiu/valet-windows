<p align="center"><img src="https://laravel.com/assets/img/components/logo-valet.svg"></p>

<p align="center">
<a href="https://travis-ci.org/cretueusebiu/valet-windows"><img src="https://travis-ci.org/cretueusebiu/valet-windows.svg?branch=master" alt="Build Status"></a>
<a href="https://packagist.org/packages/cretueusebiu/valet-windows"><img src="https://poser.pugx.org/cretueusebiu/valet-windows/d/total.svg" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/cretueusebiu/valet-windows"><img src="https://poser.pugx.org/cretueusebiu/valet-windows/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/cretueusebiu/valet-windows"><img src="https://poser.pugx.org/cretueusebiu/valet-windows/license.svg" alt="License"></a>
</p>

<p align="center">Windows port of the popular development environment <a href="https://github.com/laravel/valet">Laravel Valet</a>.</p>

## Introduction

Valet is a Laravel development environment for Windows. No Vagrant, no `/etc/hosts` file. You can even share your sites publicly using local tunnels. _Yeah, we like it too._

Laravel Valet configures your Windows to always run Nginx in the background when your machine starts. Then, using [Acrylic DNS](http://mayakron.altervista.org/wikibase/show.php?id=AcrylicHome), Valet proxies all requests on the `*.test` domain to point to sites installed on your local machine.

## Documentation

Before installation, make sure that no other programs such as Apache or Nginx are binding to your local machine's port 80. <br> Also make sure to open your preferred terminal (CMD, Git Bash, PowerShell, etc.) as Administrator.

-   If you don't have PHP installed, open PowerShell (3.0+) as Administrator and run:

```bash
# PHP 8.0
Set-ExecutionPolicy RemoteSigned; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri "https://github.com/cretueusebiu/valet-windows/raw/master/bin/php80.ps1" -OutFile $env:temp\php80.ps1; .$env:temp\php80.ps1

# PHP 7.4
Set-ExecutionPolicy RemoteSigned; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri "https://github.com/cretueusebiu/valet-windows/raw/master/bin/php74.ps1" -OutFile $env:temp\php74.ps1; .$env:temp\php74.ps1
```

> This script will download and install PHP for you and add it to your environment path variable. PowerShell is only required for this step.

-   If you don't have Composer installed, make sure to [install](https://getcomposer.org/Composer-Setup.exe) it.

-   Install Valet with Composer via `composer global require cretueusebiu/valet-windows`.

-   Run the `valet install` command. This will configure and install Valet and register Valet's daemon to launch when your system starts.

-   If you're installing on Windows 10, you may need to [manually configure](http://mayakron.altervista.org/wikibase/show.php?id=AcrylicWindows10Configuration) Windows to use the Acrylic DNS proxy.

Valet will automatically start its daemon each time your machine boots. There is no need to run `valet start` or `valet install` ever again once the initial Valet installation is complete.

For more please refer to the official documentation on the [Laravel website](https://laravel.com/docs/5.8/valet#serving-sites).

## Known Issues

-   When sharing sites the url will not be copied to the clipboard.
-   You must run the `valet` commands from the drive where Valet is installed, except for park and link. See [#12](https://github.com/cretueusebiu/valet-windows/issues/12#issuecomment-283111834).
-   If your machine is not connected to the internet you'll have to manually add the domains in your `hosts` file or you can install the "Microsoft Loopback Adapter" as this simulates an active local network interface that Valet can bind too.

## Useful Links

-   [Install ImageMagick](https://mlocati.github.io/articles/php-windows-imagick.html)

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information what has changed recently.

## License

Laravel Valet is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT).
