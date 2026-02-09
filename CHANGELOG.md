# Changelog

## 2.6.0 - 2026-02-09

- Added support for PHP 8.4.

## 2.5.2 - 2023-12-21

- Added support for PHP 8.2 and 8.3.

## 2.5.1 - 2022-08-25

- Fixed Xdebug download error [#201](https://github.com/cretueusebiu/valet-windows/pull/201)

## 2.5.0 - 2022-02-18

- Update Acrylic DNS Proxy.
- Update composer dependencies.

## 2.4.1 - 2021-12-03

- Fixed `valet share`.

## 2.4.0 - 2021-11-24

- Added support for PHP 8.1.

## 2.3.2 - 2021-01-26

- Fixed service installation when user path contains spaces.

## 2.3.1 - 2021-04-25

- Fixed 404 error when sharing upper case folder [#168](https://github.com/cretueusebiu/valet-windows/pull/168)

## 2.3.0 - 2021-03-28

- Added support for Xdebug [#165](https://github.com/cretueusebiu/valet-windows/pull/165)

## 2.2.0 - 2021-01-11

- Sync with [laravel/valet](https://github.com/laravel/valet)
- Added support for PHP 8.0
- Update Acrylic DNS Proxy, Nginx, WinSW
- Added integration tests with Docker

## 2.1.5 - 2021-01-06

- Fixed ssl redirect [#150](https://github.com/cretueusebiu/valet-windows/pull/150)
- Fixed certificate keyUsage [#151](https://github.com/cretueusebiu/valet-windows/pull/151)

## 2.1.4 - 2020-10-23

- Lowered the number of php processes

## 2.1.3 - 2020-10-21

- Update Acrylic DNS Proxy
- Added `PHP_FCGI_CHILDREN=10` [#148](https://github.com/cretueusebiu/valet-windows/issues/148)
- Fixed illuminate/container conflict [#147](https://github.com/cretueusebiu/valet-windows/issues/147)

## 2.1.1 - 2019-07-26

- Fixed certificates in non-UTC timezones [#121](https://github.com/cretueusebiu/valet-windows/pull/121) 

## 2.1.0 - 2019-04-13

- Moved the helper functions into the Valet namespace
- Renamed `valet domain` command to `valet tld`
- Moved `~/.valet` to `~/.config/valet`
- Unlink renamed links
- Fixed warning on newer `symfony/process` versions
- Share command now works with secured sites
- Added PHP 7.3 install script
- HTTP/2 works now with secured sites
- Updated `nginx`, `ngrok`, `winsw` and `Acrylic DNS` 
- Other various fixes ported from [laravel/valet](https://github.com/laravel/valet)

## 2.0.15 - 2018-01-10

- Add compatibility with symfony/process v4

## 2.0.14 - 2018-01-06

- Fixed PHP path detection

## 2.0.13 - 2017-12-08

- Changed default tld from `.dev` to `.test`
- Fixed `valet share` for linked sites
- Change FPM port to 9001 ([#51](https://github.com/cretueusebiu/valet-windows/pull/51))
- Other minor fixes ported from [laravel/valet](https://github.com/laravel/valet)

## 2.0.12 - 2017-09-27

- Fixed global composer files ([#49](https://github.com/cretueusebiu/valet-windows/issues/49)).

## 2.0.11 - 2017-08-04

- Pretty print sites
- Update some drivers
- Added wildcard certificates
- Fixed Nginx upload max size
- Added `--secure` option for `valet link`
- Added ability to pass directory to `valet open`
- Other minor fixes

## 2.0.10 - 2017-05-23

- Fixed SSL certificate ([#29](https://github.com/cretueusebiu/valet-windows/pull/30)).

## 2.0.9 - 2017-04-03

- Fixed issues ([#10](https://github.com/cretueusebiu/valet-windows/issues/10) and [#18](https://github.com/cretueusebiu/valet-windows/issues/18)) related to paths.

## 2.0.8 - 2017-01-25

- Fixed Nginx configuration for secured sites.

## 2.0.7 - 2017-01-21

- Fix Nginx when parking a directory from other drives than `C:`.
- Read the domain set in config when reinstalling.

## 2.0.5 - 2017-01-12

- Restart PHP-FPM service on failure.
- Upgrade [WinSW](https://github.com/kohsuke/winsw).
- Fix Nginx `server_names_hash_bucket_size` error.

## 2.0.4 - 2017-01-02

- Fix `valet link` command.
- Configure [Acrylic DNS Proxy](http://mayakron.altervista.org/wikibase/show.php?id=AcrylicWindows10Configuration) automatically.
