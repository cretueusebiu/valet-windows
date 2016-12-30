<p align="center"><img src="https://laravel.com/assets/img/components/logo-valet.svg"></p>

<p align="center">
<a href="https://packagist.org/packages/cretueusebiu/valet"><img src="https://poser.pugx.org/cretueusebiu/valet/d/total.svg" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/cretueusebiu/valet"><img src="https://poser.pugx.org/cretueusebiu/valet/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/cretueusebiu/valet"><img src="https://poser.pugx.org/cretueusebiu/valet/license.svg" alt="License"></a>
</p>

<p align="center">Windows port of the popular development environment <a href="https://github.com/laravel/valet">Laravel Valet</a>.</p>

## Introduction

Valet is a Laravel development environment for Windows. No Vagrant, no `/etc/hosts` file. You can even share your sites publicly using local tunnels. _Yeah, we like it too._

Laravel Valet configures your Windows to always run Nginx in the background when your machine starts. Then, using [Acrylic DNS](http://mayakron.altervista.org/wikibase/show.php?id=AcrylicHome), Valet proxies all requests on the `*.dev` domain to point to sites installed on your local machine.

## Documentation

Before installation, make sure that no other programs such as Apache or Nginx are binding to your local machine's port 80. <br> Also make sure to open your preferred terminal (CMD, Git Bash, PowerShell, etc.) as Administrator. 

- If you don't have PHP installed, open PowerShell as Administrator and run: 

```bash
wget https://github.com/cretueusebiu/valet-windows/raw/master/bin/php-installer.ps1 -OutFile $env:temp\php-installer.ps1; ."$env:temp\php-installer.ps1"
``` 
> This script will download and install PHP 7.1 for you and it to your environment Path variable. PowerShell is only required for installing PHP this way.

- Configue Network by following this [guide](http://mayakron.altervista.org/wikibase/show.php?id=AcrylicWindows10Configuration).

- If you don't have Composer installed, make sure to [install](https://getcomposer.org/Composer-Setup.exe) it.

- Install Valet with Composer via `composer global require cretueusebiu/valet-windows`.

- Run the `valet install` command. This will configure and install Valet and register Valet's daemon to launch when your system starts.

Valet will automatically start its daemon each time your machine boots. There is no need to run `valet start` or `valet install` ever again once the initial Valet installation is complete.

For more please refer to the official documentation on the [Laravel website](https://laravel.com/docs/5.3/valet#serving-sites).

## Known Issues

- HTTP/2 does not work with secured sites.
- When sharing sites the url will not be copied to the clipboard.

## License

Laravel Valet is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)
