# NordiPass R0 Verification Script
param([switch]$SelfTest)

$script:results = [System.Collections.Generic.List[object]]::new()
$script:failed = $false
$script:locationChanged = $false
$script:logDir = Join-Path ([System.IO.Path]::GetTempPath()) 'nordipass-r0-verify-logs'
$null = New-Item -ItemType Directory -Force -Path $script:logDir

function Run-Step {
    param(
        [string]$Name,
        [string]$Program,
        [string[]]$Arguments = @(),
        [int]$TimeoutSeconds = 300,
        [bool]$AllowNonZero = $false
    )
    if ($script:failed) { return }

    $safeName = $Name -replace '[^\w\-]', '_'
    $logPath = Join-Path $script:logDir "$safeName.log"
    Write-Host -NoNewline "$Name ... "

    $sw = [System.Diagnostics.Stopwatch]::StartNew()

    try {
        # Use Start-Process for reliable exit code capture
        $proc = Start-Process -FilePath $Program -ArgumentList $Arguments `
            -NoNewWindow -Wait -PassThru `
            -RedirectStandardOutput $logPath -RedirectStandardError "$logPath.err"

        $sw.Stop()
        $ec = $proc.ExitCode
        $dur = $sw.Elapsed.ToString('mm\:ss\.fff')

        $isError = $ec -ne 0 -and -not $AllowNonZero
        $statusText = if ($isError) { 'FAIL' } elseif ($ec -eq 0) { 'PASS' } else { 'OK' }
        $script:results.Add([PSCustomObject]@{Name=$Name; ExitCode=$ec; Duration=$dur; Status=$statusText})

        $color = if ($isError) { 'Red' } else { 'Green' }
        Write-Host "$statusText ($dur)" -ForegroundColor $color

        if ($isError) {
            $script:failed = $true
            if (Test-Path "$logPath.err") {
                $err = Get-Content "$logPath.err" -Raw -ErrorAction SilentlyContinue
                if ($err.Trim()) { Write-Host "  $($err -split '\n' | Select-Object -First 2)" -ForegroundColor Yellow }
            }
        }
    }
    catch {
        $sw.Stop()
        $script:failed = $true
        $script:results.Add([PSCustomObject]@{Name=$Name; ExitCode=-1; Duration=$sw.Elapsed.ToString('mm\:ss\.fff'); Status='ERROR'})
        Write-Host "ERROR" -ForegroundColor Red
    }
}

# Self-test mode
if ($SelfTest) {
    Write-Host "=== NordiPass R0 Runner Self-Test ===" -ForegroundColor Cyan

    Write-Host "`n--- Success Test ---" -ForegroundColor Yellow
    $script:failed = $false; $script:results.Clear()
    Run-Step "pass-1" "cmd" @('/c','exit 0')
    Run-Step "pass-2" "cmd" @('/c','exit 0')
    $ok = -not $script:failed
    Write-Host "Success: $(if($ok){'PASS'}else{'FAIL'}) "

    Write-Host "`n--- Failure Test ---" -ForegroundColor Yellow
    $script:failed = $false; $script:results.Clear()
    Run-Step "step-a" "cmd" @('/c','exit 0')
    Run-Step "step-b" "cmd" @('/c','exit 7')
    Run-Step "step-c" "cmd" @('/c','exit 0')
    $b = $script:results | Where-Object { $_.Name -eq 'step-b' } | Select-Object -First 1
    $c = $script:results | Where-Object { $_.Name -eq 'step-c' } | Select-Object -First 1
    $bCode = if ($b) { $b.ExitCode } else { '?' }
    $cRan = $null -ne $c
    $failOk = $script:failed -and ($bCode -eq 7) -and (-not $cRan)
    Write-Host "B exit: $bCode, C ran: $cRan"
    Write-Host "Failure: $(if($failOk){'PASS'}else{'FAIL'}) "

    Write-Host "`n=== Self-Test Results ===" -ForegroundColor Cyan
    Write-Host "Success: $(if($ok){'PASS'}else{'FAIL'})"
    Write-Host "Failure: $(if($failOk){'PASS'}else{'FAIL'})"
    if (-not $ok -or -not $failOk) { exit 1 } else { exit 0 }
}

# Full verification
try {
    $baseDir = Resolve-Path "$PSScriptRoot\.."
    Push-Location $baseDir
    $script:locationChanged = $true

    $php = (Get-Command php.bat -ErrorAction SilentlyContinue).Source
    if (-not $php) { $php = (Get-Command php).Source }
    $composer = (Get-Command composer.bat -ErrorAction SilentlyContinue).Source
    if (-not $composer) { $composer = (Get-Command composer).Source }
    $npm = (Get-Command npm.cmd -ErrorAction SilentlyContinue).Source
    if (-not $npm) { $npm = (Get-Command npm).Source }
    $node = (Get-Command node).Source
    $gitExe = (Get-Command git).Source
    $hasBash = $null -ne (Get-Command bash -ErrorAction SilentlyContinue)

    Write-Host "`n=== NordiPass R0 Verification ===" -ForegroundColor Cyan
    Write-Host "PHP: $php"
    Write-Host "Logs: $($script:logDir)"

    Run-Step "composer validate" $composer @('validate','--strict')
    Run-Step "composer install" $composer @('install','--no-interaction','--prefer-dist','--no-progress')
    Run-Step "config:clear" $php @('artisan','config:clear')
    Run-Step "migrate:fresh" $php @('artisan','migrate:fresh','--seed')
    Run-Step "migrate:status" $php @('artisan','migrate:status')
    Run-Step "test" $php @('artisan','test')
    Run-Step "pint --test" $php @('vendor/bin/pint','--test')
    Run-Step "phpstan" $php @('vendor/bin/phpstan','analyse','--no-progress')
    Run-Step "composer audit" $composer @('audit','--locked')
    Run-Step "node --version" $node @('--version')
    Run-Step "npm --version" $npm @('--version')
    Run-Step "npm ci" $npm @('ci')
    Run-Step "npm run build" $npm @('run','build')
    Run-Step "npm audit" $npm @('audit','--omit=dev','--audit-level=high') -AllowNonZero $true
    Run-Step "config:cache" $php @('artisan','config:cache')
    Run-Step "route:cache" $php @('artisan','route:cache')
    Run-Step "view:cache" $php @('artisan','view:cache')
    Run-Step "route:list" $php @('artisan','route:list')
    Run-Step "route:list api" $php @('artisan','route:list','--path=api')
    Run-Step "schedule:list" $php @('artisan','schedule:list')
    Run-Step "schedule:run" $php @('artisan','schedule:run','-v')
    Run-Step "queue:failed" $php @('artisan','queue:failed')
    Run-Step "deploy-check" $php @('artisan','nordipass:deploy-check')
    Run-Step "backup dry-run" $php @('artisan','nordipass:backup','--dry-run')
    Run-Step "backup-prune dry" $php @('artisan','nordipass:backup-prune','--dry-run')

    if ($hasBash) {
        Run-Step "bash -n deploy.sh" "bash" @('-n','deploy/scripts/deploy.sh')
        Run-Step "bash -n rollback.sh" "bash" @('-n','deploy/scripts/rollback.sh')
    } else {
        Write-Host "bash syntax: NOT VERIFIED" -ForegroundColor Yellow
    }

    Run-Step "git diff --check" $gitExe @('diff','--check')
}
catch {
    if (-not $script:failed) { $script:failed = $true }
    Write-Host "EXCEPTION: $_" -ForegroundColor Red
}
finally {
    if ($script:locationChanged) { Pop-Location }
    Write-Host "`n=== Results ===" -ForegroundColor Cyan
    $p = 0; $f = 0
    foreach ($r in $script:results) {
        $c = if ($r.ExitCode -eq 0 -and $r.Status -ne 'FAIL') { 'Green'; $p++ } else { 'Red'; $f++ }
        Write-Host "$($r.Status.PadRight(7)) | $($r.ExitCode) | $($r.Duration) | $($r.Name)" -ForegroundColor $c
    }
    Write-Host "$p passed, $f failed"
}
if ($script:failed) { exit 1 }
exit 0
