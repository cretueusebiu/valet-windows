$phpVersion = $args[0]
$phpPath = "C:\php"
$caCertUrl = "https://curl.haxx.se/ca/cacert.pem"
$phpReleasesUrl = "http://windows.php.net/downloads/releases"
$xdebugReleasesUrl = "https://xdebug.org"
$stubsUrl = "https://raw.githubusercontent.com/cretueusebiu/valet-windows/master/cli/stubs"

if (Get-Command "php" -errorAction SilentlyContinue) {
    Write-Output "PHP already installed!"
    exit
}

if ('7.2','7.3','7.4','8.0','8.1','8.2','8.3','8.4' -notcontains $phpVersion) {
    throw "Invalid PHP version [$phpVersion]."
}

function Find-PhpRelease {
    $architecture = if ([Environment]::Is64BitProcess) {"x64"} else {"x86"}
    $phpResponse = Invoke-WebRequest "$phpReleasesUrl/releases.json" -UseBasicParsing | ConvertFrom-Json
    $path = $phpResponse.$phpVersion."nts-vc15-$architecture"."zip"."path"

    if (! $path) {
        $path = $phpResponse.$phpVersion."nts-vs16-$architecture"."zip"."path"
    }

    if (! $path) {
        $path = $phpResponse.$phpVersion."nts-vs17-$architecture"."zip"."path"
    }

    if (! $path) {
        throw "Could not find a release for PHP $phpVersion."
    }

    return "$phpReleasesUrl/$path"
}

function Find-XdebugRelease {
    $architecture = if ([Environment]::Is64BitProcess) {"-x86_64"} else {""}
    # php_xdebug-3.0.3-7.4-vc15-nts-x86_64.dll
    # php_xdebug-3.0.3-8.0-vs16-nts-x86_64.dll
    $html = (Invoke-WebRequest "${xdebugReleasesUrl}/download/historical" -UseBasicParsing).rawcontent

    if ($html -match "php_xdebug-(.+)-${phpVersion}-vc15-nts${architecture}.dll") {
        return "${xdebugReleasesUrl}/files/" + $matches[0]
    }

    if ($html -match "php_xdebug-(.+)-${phpVersion}-vs16-nts${architecture}.dll") {
        return "${xdebugReleasesUrl}/files/" + $matches[0]
    }

    if ($html -match "php_xdebug-(.+)-${phpVersion}-vs17-nts${architecture}.dll") {
        return "${xdebugReleasesUrl}/files/" + $matches[0]
    }

    throw "Could not find a Xdebug release for PHP $phpVersion."
}

function Download-File {
    Param ($Source, $Destination)

    $wc = New-Object System.Net.WebClient
    $wc.Headers.Add("User-Agent", "Windows Powershell WebClient")
    $wc.DownloadFile($Source, $Destination)
}

Add-Type -AssemblyName System.IO.Compression.FileSystem

$phpZip = "$PSScriptRoot\php.zip"

# Download php
Write-Output "Downloading PHP..."
$phpUrl = Find-PhpRelease
Download-File $phpUrl -Destination $phpZip

# Extract php
Write-Output "Installing PHP..."
[System.IO.Compression.ZipFile]::ExtractToDirectory($phpZip, $phpPath)
Remove-Item $phpZip

# Download php.ini
Write-Output "Installing php.ini..."
$phpIniUrl = $stubsUrl + "/php" + $phpVersion.Replace('.', '') + ".ini"
$phpIniContents = (Invoke-WebRequest $phpIniUrl -UseBasicParsing).Content
Set-Content -Path "$phpPath\php.ini" -Value $phpIniContents.Replace('~/.config', "$HOME/.config")

# Download cacert.pem
Write-Output "Installing CA certificate..."
Download-File -Source $caCertUrl -Destination "$phpPath\cacert.pem"

# Download xdebug
Write-Output "Downloading Xdebug..."
$xdebugUrl = Find-XdebugRelease
Download-File $xdebugUrl -Destination "$phpPath\ext\php_xdebug.dll"

# Add PHP to path environment variable
if ($env:Path -notlike "*$phpPath*") {
    Write-Output "Adding PHP to path..."
    $env:Path += ";" + $phpPath
    [Environment]::SetEnvironmentVariable("Path", $env:Path, [EnvironmentVariableTarget]::Machine)
}

# Done
Write-Output "PHP installed successfully!`n"
php -v
