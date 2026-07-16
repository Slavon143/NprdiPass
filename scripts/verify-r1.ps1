# NordiPass R1 Core Catalog Release Verification
# Requires PowerShell 7+ (pwsh.exe)
param([switch]$SelfTest)

$ErrorActionPreference = 'Stop'

# --- PowerShell version guard ---
if ($PSVersionTable.PSVersion.Major -lt 7) {
    Write-Host "ERROR: This script requires PowerShell 7+ (pwsh.exe). Current: $($PSVersionTable.PSVersion)" -ForegroundColor Red
    exit 2
}

$script:results = [System.Collections.Generic.List[object]]::new()
$script:failed = $false
$script:locationChanged = $false
$script:stopwatch = [System.Diagnostics.Stopwatch]::StartNew()

function Write-Header { Write-Host "`n=== R1 Core Catalog Verification ===" -ForegroundColor Cyan }
function Write-Step { Write-Host "`n--- $($args[0]) ---" -ForegroundColor DarkYellow }
function Write-Pass { Write-Host "PASS" -ForegroundColor Green }
function Write-Fail { Write-Host "FAIL" -ForegroundColor Red }

function Invoke-Step {
    param(
        [string]$Name,
        [string]$Program,
        [string[]]$Arguments = @(),
        [int]$TimeoutSeconds = 600,
        [bool]$AllowNonZero = $false
    )
    if ($script:failed) { return }

    Write-Host -NoNewline "$Name ... "

    $sw = [System.Diagnostics.Stopwatch]::StartNew()

    try {
        $proc = Start-Process -FilePath $Program -ArgumentList $Arguments `
            -NoNewWindow -Wait -PassThru `
            -RedirectStandardOutput "$env:TEMP\verify-r1-out-$PID.tmp" `
            -RedirectStandardError "$env:TEMP\verify-r1-err-$PID.tmp"

        $sw.Stop()
        $ec = $proc.ExitCode
        $dur = $sw.Elapsed.ToString('mm\:ss\.fff')

        $isError = $ec -ne 0 -and -not $AllowNonZero
        $statusText = if ($isError) { 'FAIL' } elseif ($ec -eq 0) { 'PASS' } else { 'OK' }
        $script:results.Add([PSCustomObject]@{Name=$Name; ExitCode=$ec; Duration=$dur; Status=$statusText})

        $color = if ($isError) { 'Red' } else { 'Green' }
        Write-Host "$statusText (exit $ec, $dur)" -ForegroundColor $color

        if ($isError) {
            $script:failed = $true
            $errFile = "$env:TEMP\verify-r1-err-$PID.tmp"
            if (Test-Path $errFile) {
                $err = Get-Content $errFile -Raw -ErrorAction SilentlyContinue
                if ($err.Trim()) {
                    Write-Host "  STDERR:" -ForegroundColor Yellow
                    $lines = $err -split "`n" | Select-Object -First 10
                    foreach ($line in $lines) { Write-Host "    $line" -ForegroundColor Yellow }
                }
            }
        }
    }
    catch {
        $sw.Stop()
        $script:failed = $true
        $script:results.Add([PSCustomObject]@{Name=$Name; ExitCode=-1; Duration=$sw.Elapsed.ToString('mm\:ss\.fff'); Status='ERROR'})
        Write-Host "ERROR (exception: $_)" -ForegroundColor Red
    }
    finally {
        Remove-Item "$env:TEMP\verify-r1-out-$PID.tmp", "$env:TEMP\verify-r1-err-$PID.tmp" -ErrorAction SilentlyContinue
    }
}

# --- Self-test mode ---
if ($SelfTest) {
    Write-Header
    Write-Host "Self-Test Mode" -ForegroundColor Yellow

    Write-Host "`n--- Success Test ---"
    $script:failed = $false; $script:results.Clear()
    Invoke-Step "pass-1" "cmd" @('/c','exit 0')
    Invoke-Step "pass-2" "cmd" @('/c','exit 0')
    $ok = -not $script:failed
    Write-Host "Success flow: $(if($ok){'PASS'}else{'FAIL'})"

    Write-Host "`n--- Failure Test ---"
    $script:failed = $false; $script:results.Clear()
    Invoke-Step "step-a" "cmd" @('/c','exit 0')
    Invoke-Step "step-b" "cmd" @('/c','exit 7')
    Invoke-Step "step-c" "cmd" @('/c','exit 0')
    $b = $script:results | Where-Object { $_.Name -eq 'step-b' } | Select-Object -First 1
    $c = $script:results | Where-Object { $_.Name -eq 'step-c' } | Select-Object -First 1
    $failOk = $script:failed -and ($b.ExitCode -eq 7) -and (-not $c)
    Write-Host "Failure flow: $(if($failOk){'PASS'}else{'FAIL'})"

    if (-not $ok -or -not $failOk) { exit 1 } else { exit 0 }
}

# =====================================================================
# FULL VERIFICATION
# =====================================================================

try {
    $baseDir = Resolve-Path "$PSScriptRoot\.."
    Push-Location $baseDir
    $script:locationChanged = $true

    $phpExe = (Get-Command php.bat -ErrorAction SilentlyContinue).Source
    if (-not $phpExe) { $phpExe = (Get-Command php -ErrorAction Stop).Source }

    $composerExe = (Get-Command composer.bat -ErrorAction SilentlyContinue).Source
    if (-not $composerExe) { $composerExe = (Get-Command composer -ErrorAction Stop).Source }

    $npmExe = (Get-Command npm.cmd -ErrorAction SilentlyContinue).Source
    if (-not $npmExe) { $npmExe = (Get-Command npm -ErrorAction Stop).Source }

    $gitExe = (Get-Command git -ErrorAction Stop).Source

    Write-Header
    Write-Host "Project: $baseDir"
    Write-Host "PHP:     $phpExe"
    Write-Host "PS Ver:  $($PSVersionTable.PSVersion)"

    # =====================================================================
    # SAFETY CHECKS
    # =====================================================================
    Write-Step "Safety Checks"

    # 1. Check APP_ENV in .env (file)
    $envFile = Join-Path $baseDir '.env'
    if (-not (Test-Path $envFile)) {
        Write-Host "SAFETY FAIL: .env file not found at $envFile" -ForegroundColor Red
        exit 3
    }

    $envContent = Get-Content $envFile -Raw
    if ($envContent -match '^\s*APP_ENV\s*=\s*production\s*$') {
        Write-Host "SAFETY FAIL: .env has APP_ENV=production. Refusing to run destructive commands." -ForegroundColor Red
        exit 3
    }
    Write-Host "  .env APP_ENV: OK (not production)" -ForegroundColor Green

    # 2. Check APP_ENV environment variable
    $envAppEnv = [Environment]::GetEnvironmentVariable('APP_ENV')
    if ($envAppEnv -eq 'production') {
        Write-Host "SAFETY FAIL: Environment variable APP_ENV=production. Refusing to run destructive commands." -ForegroundColor Red
        exit 3
    }
    Write-Host "  Env APP_ENV:  OK (not production)" -ForegroundColor Green

    # 3. Check DB_CONNECTION=mysql
    $envTestingFile = Join-Path $baseDir '.env.testing'
    if (Test-Path $envTestingFile) {
        $testingContent = Get-Content $envTestingFile -Raw
    }
    else {
        $testingContent = $envContent
    }

    if ($testingContent -notmatch '^\s*DB_CONNECTION\s*=\s*mysql\s*$') {
        Write-Host "SAFETY FAIL: DB_CONNECTION is not mysql in .env.testing (or .env)." -ForegroundColor Red
        exit 3
    }
    Write-Host "  DB_CONNECTION: OK (mysql)" -ForegroundColor Green

    # 4. Check database name ends with _testing
    if ($testingContent -match '^\s*DB_DATABASE\s*=\s*(.+?)\s*$') {
        $dbName = $matches[1].Trim()
        if ($dbName -notmatch '_testing$') {
            Write-Host "SAFETY FAIL: DB_DATABASE='$dbName' does not end with '_testing'. Refusing destructive operations." -ForegroundColor Red
            exit 3
        }
        Write-Host "  DB_DATABASE:   OK ($dbName)" -ForegroundColor Green
    }
    else {
        Write-Host "SAFETY FAIL: Could not determine DB_DATABASE from config." -ForegroundColor Red
        exit 3
    }

    Write-Host "`nAll safety checks passed." -ForegroundColor Green

    # =====================================================================
    # VERIFICATION COMMANDS
    # =====================================================================

    Write-Step "Code Quality & Tests"

    Invoke-Step "composer validate" $composerExe @('validate','--strict')
    Invoke-Step "artisan optimize:clear" $phpExe @('artisan','optimize:clear')
    Invoke-Step "artisan migrate:fresh --seed" $phpExe @('artisan','migrate:fresh','--seed','--env=testing') -TimeoutSeconds 300
    Invoke-Step "artisan migrate:status" $phpExe @('artisan','migrate:status','--env=testing')

    Write-Step "Catalog Audit Tests"
    Invoke-Step "test Catalog/Audit" $phpExe @('artisan','test','tests/Feature/Catalog/Audit','tests/Unit/Catalog/Audit','--env=testing')

    Write-Step "Catalog Operations Tests"
    Invoke-Step "test Catalog/Operations" $phpExe @('artisan','test','tests/Feature/Catalog/Operations','tests/Unit/Catalog/Operations','--env=testing')

    Write-Step "Catalog Console Tests"
    Invoke-Step "test Console/Catalog" $phpExe @('artisan','test','tests/Feature/Console/Catalog','--env=testing')

    Write-Step "Catalog Categories Tests"
    Invoke-Step "test Catalog/Categories" $phpExe @('artisan','test','tests/Feature/Catalog/Categories','tests/Unit/Catalog/Categories','--env=testing')

    Write-Step "Catalog Products Tests"
    Invoke-Step "test Catalog/Products" $phpExe @('artisan','test','tests/Feature/Catalog/Products','tests/Unit/Catalog/Products','--env=testing')

    Write-Step "Catalog Variants Tests"
    Invoke-Step "test Catalog/Variants" $phpExe @('artisan','test','tests/Feature/Catalog/Variants','tests/Unit/Catalog/Variants','--env=testing')

    Write-Step "Catalog Attributes Tests"
    Invoke-Step "test Catalog/Attributes" $phpExe @('artisan','test','tests/Feature/Catalog/Attributes','tests/Unit/Catalog/Attributes','--env=testing')

    Write-Step "Catalog Media Tests"
    Invoke-Step "test Catalog/Media" $phpExe @('artisan','test','tests/Feature/Catalog/Media','tests/Unit/Catalog/Media','--env=testing')

    Write-Step "Catalog Lifecycle Tests"
    Invoke-Step "test Catalog/Lifecycle" $phpExe @('artisan','test','tests/Feature/Catalog/Lifecycle','--env=testing')

    Write-Step "Catalog Search Tests"
    Invoke-Step "test Catalog/Search" $phpExe @('artisan','test','tests/Feature/Catalog/Search','tests/Unit/Catalog/Search','--env=testing')

    Write-Step "API v1 Catalog Tests"
    Invoke-Step "test Api/V1/Catalog" $phpExe @('artisan','test','tests/Feature/Api/V1/Catalog','--env=testing')

    Write-Step "Concurrency Tests"
    Invoke-Step "test Concurrency" $phpExe @('artisan','test','tests/Concurrency','--env=testing')

    Write-Step "Full Catalog Suite"
    Invoke-Step "test Catalog (all)" $phpExe @('artisan','test','tests/Feature/Catalog','tests/Unit/Catalog','--env=testing')

    Write-Step "Full Test Suite"
    Invoke-Step "test (all)" $phpExe @('artisan','test','--env=testing')

    Write-Step "Static Analysis & Linting"
    Invoke-Step "pint --test" $phpExe @('vendor/bin/pint','--test')
    Invoke-Step "phpstan analyse" $phpExe @('vendor/bin/phpstan','analyse','--no-progress')
    Invoke-Step "composer audit --locked" $composerExe @('audit','--locked')

    Write-Step "Frontend"
    Invoke-Step "npm ci" $npmExe @('ci')
    Invoke-Step "npm run build" $npmExe @('run','build')
    Invoke-Step "npm audit --audit-level=high" $npmExe @('audit','--audit-level=high') -AllowNonZero $true

    Write-Step "Production Caching"
    Invoke-Step "artisan config:cache" $phpExe @('artisan','config:cache')
    Invoke-Step "artisan route:cache" $phpExe @('artisan','route:cache')
    Invoke-Step "artisan view:cache" $phpExe @('artisan','view:cache')
    Invoke-Step "artisan schedule:list" $phpExe @('artisan','schedule:list')

    Write-Step "Git Cleanliness"
    Invoke-Step "git diff --check" $gitExe @('diff','--check')
}
catch {
    if (-not $script:failed) {
        $script:failed = $true
    }
    Write-Host "`nFATAL EXCEPTION: $_" -ForegroundColor Red
    Write-Host $_.ScriptStackTrace -ForegroundColor DarkRed
}
finally {
    if ($script:locationChanged) { Pop-Location }

    $script:stopwatch.Stop()
    $totalTime = $script:stopwatch.Elapsed.ToString('mm\:ss\.fff')

    # =====================================================================
    # SUMMARY
    # =====================================================================
    Write-Host "`n====================================" -ForegroundColor Cyan
    Write-Host "         R1 VERIFICATION SUMMARY" -ForegroundColor Cyan
    Write-Host "====================================" -ForegroundColor Cyan

    $passed = 0
    $failCount = 0
    $errorCount = 0

    foreach ($r in $script:results) {
        $symbol = if ($r.Status -eq 'PASS') { '[PASS]'; $passed++ }
                  elseif ($r.Status -eq 'OK') { '[ OK ]'; $passed++ }
                  elseif ($r.Status -eq 'FAIL') { '[FAIL]'; $failCount++ }
                  else { '[ ERR]'; $errorCount++ }

        $color = if ($r.Status -eq 'PASS' -or $r.Status -eq 'OK') { 'Green' } else { 'Red' }
        Write-Host "$symbol  exit=$($r.ExitCode)  $($r.Duration)  $($r.Name)" -ForegroundColor $color
    }

    $total = $script:results.Count
    $totalFailed = $failCount + $errorCount

    Write-Host "`n------------------------------------" -ForegroundColor Cyan
    Write-Host "Total commands : $total" -ForegroundColor White
    Write-Host "Passed         : $passed" -ForegroundColor Green
    Write-Host "Failed         : $totalFailed" -ForegroundColor $(if ($totalFailed -gt 0) { 'Red' } else { 'Green' })
    Write-Host "Total time     : $totalTime" -ForegroundColor White
    Write-Host "------------------------------------" -ForegroundColor Cyan

    if ($totalFailed -eq 0) {
        Write-Host "`n*** ALL CHECKS PASSED - R1 Core Catalog is RELEASE READY ***" -ForegroundColor Green
    }
    else {
        Write-Host "`n*** $totalFailed CHECK(S) FAILED - R1 Core Catalog is NOT ready ***" -ForegroundColor Red
    }
}

if ($script:failed) { exit 1 }
exit 0
