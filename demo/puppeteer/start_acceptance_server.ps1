param(
    [string] $ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,
    [int] $Port = 8766,
    [string] $PhpCommand = 'php'
)

$ErrorActionPreference = 'Stop'

function Invoke-ExactPhp {
    param(
        [string] $Php,
        [string[]] $Arguments
    )

    & $Php @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "PHP command failed: $Php $($Arguments -join ' ')"
    }
}

function Get-HttpStatus {
    param([string] $Url)

    $status = (& curl.exe -sS -o NUL -w '%{http_code}' --max-time 5 $Url 2>$null).Trim()
    if ($LASTEXITCODE -ne 0 -or $status -notmatch '^\d{3}$') {
        return $null
    }

    return [int] $status
}

$phpBinary = (& $PhpCommand -r 'echo PHP_BINARY;').Trim()
if (-not (Test-Path -LiteralPath $phpBinary)) {
    throw "Unable to resolve exact PHP binary from $PhpCommand"
}

$pdoMysql = (& $phpBinary -r "echo extension_loaded('pdo_mysql') ? 'yes' : 'no';").Trim()
if ($pdoMysql -ne 'yes') {
    throw "pdo_mysql is not loaded by $phpBinary"
}

$openssl = (& $phpBinary -r "echo extension_loaded('openssl') ? 'yes' : 'no';").Trim()
if ($openssl -ne 'yes') {
    throw "openssl is not loaded by $phpBinary"
}

$pdoDrivers = (& $phpBinary -r "echo implode(',', PDO::getAvailableDrivers());").Trim()
if (($pdoDrivers -split ',') -notcontains 'mysql') {
    throw "PDO mysql driver is not available to $phpBinary"
}

$envPath = Join-Path $ProjectRoot '.env.acceptance'
$testingEnvPath = Join-Path $ProjectRoot '.env.testing'

if (-not (Test-Path -LiteralPath $envPath)) {
    if (-not (Test-Path -LiteralPath $testingEnvPath)) {
        throw '.env.acceptance is missing and .env.testing is unavailable as a local template.'
    }

    $lines = Get-Content -LiteralPath $testingEnvPath
    $map = [ordered]@{
        APP_ENV = 'acceptance'
        APP_DEBUG = 'true'
        APP_URL = "http://127.0.0.1:$Port"
        CACHE_STORE = 'file'
        SESSION_DRIVER = 'file'
        QUEUE_CONNECTION = 'sync'
        MAIL_MAILER = 'log'
        DOCUMENTS_ROOT = 'storage/framework/testing/disks/product_documents'
        CATALOG_MEDIA_ROOT = 'storage/framework/testing/disks/catalog_media'
        PASSPORT_ASSETS_ROOT = 'storage/framework/testing/disks/passport_assets'
    }

    $seen = @{}
    $updated = foreach ($line in $lines) {
        if ($line -match '^\s*([A-Z0-9_]+)\s*=') {
            $key = $Matches[1]
            if ($map.Contains($key)) {
                $seen[$key] = $true
                "$key=$($map[$key])"
                continue
            }
        }

        $line
    }

    foreach ($key in $map.Keys) {
        if (-not $seen.ContainsKey($key)) {
            $updated += "$key=$($map[$key])"
        }
    }

    Set-Content -LiteralPath $envPath -Value $updated -Encoding UTF8
}

$env:APP_ENV = 'acceptance'
$env:APP_URL = "http://127.0.0.1:$Port"
$env:SESSION_SECURE_COOKIE = 'false'

Push-Location $ProjectRoot
try {
    Invoke-ExactPhp $phpBinary @('artisan', 'optimize:clear', '--env=acceptance')
    Invoke-ExactPhp $phpBinary @('artisan', 'cache:clear', '--env=acceptance')

    $pidPath = Join-Path $ProjectRoot 'storage/framework/r3_4_acceptance_server.pid'
    $outPath = Join-Path $ProjectRoot 'storage/framework/r3_4_acceptance_server.out'
    $errPath = Join-Path $ProjectRoot 'storage/framework/r3_4_acceptance_server.err'
    Remove-Item -LiteralPath $pidPath, $outPath, $errPath -ErrorAction SilentlyContinue

    $args = @(
        '-S', "127.0.0.1:$Port",
        '-t', 'public',
        'demo/puppeteer/acceptance-router.php'
    )

    $process = Start-Process `
        -FilePath $phpBinary `
        -ArgumentList $args `
        -WorkingDirectory $ProjectRoot `
        -WindowStyle Hidden `
        -RedirectStandardOutput $outPath `
        -RedirectStandardError $errPath `
        -PassThru

    Set-Content -LiteralPath $pidPath -Value ([string] $process.Id) -Encoding ASCII

    $readyUrl = "http://127.0.0.1:$Port/ready"
    $loginUrl = "http://127.0.0.1:$Port/login"
    $deadline = (Get-Date).AddSeconds(30)
    $readyStatus = $null
    $loginStatus = $null

    do {
        Start-Sleep -Milliseconds 500

        if ($process.HasExited) {
            $stderr = if (Test-Path -LiteralPath $errPath) { Get-Content -LiteralPath $errPath -Raw } else { '' }
            throw "Acceptance server exited early. $stderr"
        }

        $readyStatus = Get-HttpStatus $readyUrl
        $loginStatus = Get-HttpStatus $loginUrl
    } while (($readyStatus -ge 500 -or $loginStatus -ge 500 -or $readyStatus -eq $null -or $loginStatus -eq $null) -and (Get-Date) -lt $deadline)

    if ($readyStatus -ge 500 -or $loginStatus -ge 500 -or $readyStatus -eq $null -or $loginStatus -eq $null) {
        if (-not $process.HasExited) {
            Stop-Process -Id $process.Id -Force
        }

        throw "Acceptance server readiness failed. /ready=$readyStatus /login=$loginStatus"
    }

    [pscustomobject]@{
        phpBinary = $phpBinary
        port = $Port
        pid = $process.Id
        readyStatus = $readyStatus
        loginStatus = $loginStatus
        pidPath = $pidPath
        stdout = $outPath
        stderr = $errPath
    } | ConvertTo-Json -Depth 3
} finally {
    Pop-Location
}
