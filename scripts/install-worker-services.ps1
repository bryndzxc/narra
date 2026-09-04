<#
.SYNOPSIS
    Registers the three queue workers as Windows services under NSSM.

.DESCRIPTION
    There is no Supervisor on Windows and no Horizon on this platform, so a
    worker started in a terminal is a worker that exits at --max-time and never
    comes back. That is not a hypothetical: it is how story 21's 270-scene asset
    run stalled repeatedly, a third of the way through, while the progress page
    reported work in flight.

    A service turns that exit into a non-event. NSSM restarts the process, the
    worker picks up the rest of the batch, and nobody has to be watching.

    Idempotent. Running it again reconfigures the existing services rather than
    failing, so it is also the way to apply a changed --max-time.

    KNOWN TRADEOFF, stated because it is real and not a reason to skip this:
    NSSM raises the stale-worker rate. A terminal dies on reboot and comes back
    with current code; a service up for six days across four .env edits is
    precisely the stale worker, and it restarts itself after every crash
    somebody might otherwise have noticed. The stale-worker refusal in
    AssertWorkersCurrent covers that, and a refusal at dispatch is a better
    failure than a silent stall mid-batch. Run `php artisan queue:restart`
    after any change to .env, config/providers.php, or provider code.

.PARAMETER AssetsWorkers
    How many `assets` worker services to install. The queue is mostly waiting on
    somebody else's server, so more of them is more throughput up to the point
    the provider rate-limits. 1 by default; docs/queue-workers.md suggests 4-8
    once the run is trusted. Each extra one raises the rate of spend, which is
    why it is a flag and not a default.

.PARAMETER RenderWorkers
    How many `render` worker services to install. Two is the ceiling worth
    having on one box: scene clips already saturate the cores, and a third
    process makes every clip slower rather than the batch faster.

.PARAMETER Uninstall
    Stop and remove every Narra* service instead of installing.

.EXAMPLE
    # From an ELEVATED PowerShell prompt:
    powershell -ExecutionPolicy Bypass -File E:\laragon\www\narra\scripts\install-worker-services.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\scripts\install-worker-services.ps1 -AssetsWorkers 4
#>

[CmdletBinding()]
param(
    [int]    $AssetsWorkers = 1,
    [int]    $RenderWorkers = 1,
    [string] $Php           = 'E:\laragon\bin\php\php-8.3.22-Win32-vs16-x64\php.exe',
    [string] $App           = 'E:\laragon\www\narra',
    [string] $Nssm          = '',
    [switch] $Uninstall
)

$ErrorActionPreference = 'Stop'

# --------------------------------------------------------------------------
# Pre-flight. Every one of these is a thing that fails silently or confusingly
# halfway through otherwise.
# --------------------------------------------------------------------------

$identity  = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)

if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw "This must run from an ELEVATED prompt. Registering a Windows service needs administrator rights; without them nssm fails partway and leaves a service that exists but cannot start."
}

if (-not (Test-Path $Php))               { throw "PHP not found at $Php. Pass -Php with the right path." }
if (-not (Test-Path "$App\artisan"))     { throw "No artisan at $App\artisan. Pass -App with the project root." }

$logDir = Join-Path $App 'storage\logs'
if (-not (Test-Path $logDir)) { New-Item -ItemType Directory -Path $logDir | Out-Null }

# --------------------------------------------------------------------------
# Find or fetch nssm.
# --------------------------------------------------------------------------

function Resolve-Nssm {
    param([string] $Hint)

    if ($Hint -and (Test-Path $Hint)) { return (Resolve-Path $Hint).Path }

    $onPath = Get-Command nssm -ErrorAction SilentlyContinue
    if ($onPath) { return $onPath.Source }

    $vendored = Join-Path $App 'tools\nssm\nssm.exe'
    if (Test-Path $vendored) { return $vendored }

    Write-Host "nssm not found. Downloading nssm 2.24 from nssm.cc..." -ForegroundColor Yellow

    $toolDir = Join-Path $App 'tools\nssm'
    New-Item -ItemType Directory -Path $toolDir -Force | Out-Null
    $zip = Join-Path $env:TEMP 'nssm-2.24.zip'

    Invoke-WebRequest -Uri 'https://nssm.cc/release/nssm-2.24.zip' -OutFile $zip -UseBasicParsing
    Expand-Archive -Path $zip -DestinationPath $env:TEMP -Force

    # 64-bit build. The 32-bit one works too but there is no reason to take it.
    Copy-Item (Join-Path $env:TEMP 'nssm-2.24\win64\nssm.exe') $vendored -Force
    Remove-Item $zip -Force -ErrorAction SilentlyContinue

    return $vendored
}

$nssmExe = Resolve-Nssm -Hint $Nssm
Write-Host "nssm: $nssmExe"

# --------------------------------------------------------------------------
# The three queues.
#
# --max-time is sized against measured job durations, not rounded. See
# docs/queue-workers.md > "Sizing --max-time". Short version:
#
#   assets  32400  270 scenes x p99 (92s image + 4s tts + 13s align) x 1.1
#   text    10800  one WriteStoryJob = outline + 7 acts x 900s ANTHROPIC_TIMEOUT
#   render  21600  one mux is one job and the whole worker lifetime
#
# --tries=1 on render is deliberate: re-running a render stage is hours of CPU
# and, from Phase 2, real money. Retrying is an operator decision.
# --------------------------------------------------------------------------

$queues = @(
    [pscustomobject]@{ Name = 'NarraText';   Queue = 'text';   Tries = 3; MaxTime = 10800; Count = 1              }
    [pscustomobject]@{ Name = 'NarraAssets'; Queue = 'assets'; Tries = 3; MaxTime = 32400; Count = $AssetsWorkers }
    [pscustomobject]@{ Name = 'NarraRender'; Queue = 'render'; Tries = 1; MaxTime = 21600; Count = $RenderWorkers }
)

function Get-ServiceNames {
    param([string] $Base, [int] $Count)

    # The first one keeps the bare name, because that is what the workers panel
    # prints as the fix and what the docs name. Extras are suffixed.
    1..$Count | ForEach-Object { if ($_ -eq 1) { $Base } else { "$Base$_" } }
}

# --------------------------------------------------------------------------
# Uninstall.
# --------------------------------------------------------------------------

if ($Uninstall) {
    Get-Service -Name 'Narra*' -ErrorAction SilentlyContinue | ForEach-Object {
        Write-Host "Removing $($_.Name)..." -ForegroundColor Yellow
        & $nssmExe stop   $_.Name | Out-Null
        & $nssmExe remove $_.Name confirm | Out-Null
    }
    Write-Host "Done." -ForegroundColor Green
    return
}

# --------------------------------------------------------------------------
# Install / reconfigure.
# --------------------------------------------------------------------------

foreach ($q in $queues) {
    foreach ($name in (Get-ServiceNames -Base $q.Name -Count $q.Count)) {

        $existing = Get-Service -Name $name -ErrorAction SilentlyContinue

        if ($existing) {
            Write-Host "$name exists - stopping and reconfiguring." -ForegroundColor Yellow
            & $nssmExe stop $name | Out-Null
        } else {
            Write-Host "Installing $name..." -ForegroundColor Cyan
            & $nssmExe install $name $Php | Out-Null
        }

        $arguments = "`"$App\artisan`" queue:work redis --queue=$($q.Queue) --tries=$($q.Tries) --max-time=$($q.MaxTime)"

        & $nssmExe set $name Application       $Php              | Out-Null
        & $nssmExe set $name AppParameters     $arguments        | Out-Null
        & $nssmExe set $name AppDirectory      $App              | Out-Null
        & $nssmExe set $name DisplayName       "Narra $($q.Queue) worker"                            | Out-Null
        & $nssmExe set $name Description       "php artisan queue:work redis --queue=$($q.Queue)"    | Out-Null

        $log = Join-Path $logDir "worker-$($q.Queue).log"
        & $nssmExe set $name AppStdout         $log              | Out-Null
        & $nssmExe set $name AppStderr         $log              | Out-Null
        & $nssmExe set $name AppRotateFiles    1                 | Out-Null
        & $nssmExe set $name AppRotateOnline   1                 | Out-Null
        & $nssmExe set $name AppRotateBytes    10485760          | Out-Null

        # The point of the whole exercise. A --max-time exit is a clean exit 0,
        # and without this NSSM would treat it as "the app is done" and stop.
        & $nssmExe set $name AppExit Default   Restart           | Out-Null

        # Wait 5s before restarting, and treat an exit inside 10s as a crash
        # loop rather than a recycle. Without a throttle, a PHP that cannot boot
        # (a syntax error in a config file, say) spins as fast as the CPU allows
        # and fills the log with the same fatal.
        & $nssmExe set $name AppRestartDelay   5000              | Out-Null
        & $nssmExe set $name AppThrottle       10000             | Out-Null

        # Ctrl-C first, so `queue:work` finishes the job in hand rather than
        # being killed mid-FFmpeg or mid-billed-API-call.
        & $nssmExe set $name AppStopMethodConsole 60000          | Out-Null

        & $nssmExe set $name Start SERVICE_AUTO_START            | Out-Null
    }
}

# --------------------------------------------------------------------------
# Start and verify. An install that reports success without the service
# actually running is the same false-success shape this project keeps hitting.
# --------------------------------------------------------------------------

Get-Service -Name 'Narra*' | ForEach-Object { & $nssmExe start $_.Name | Out-Null }

Start-Sleep -Seconds 5

$services = Get-Service -Name 'Narra*' | Sort-Object Name
$services | Select-Object Name, Status, StartType | Format-Table -AutoSize

$bad = $services | Where-Object { $_.Status -ne 'Running' }

if ($bad) {
    Write-Host ""
    Write-Host "NOT RUNNING: $($bad.Name -join ', ')" -ForegroundColor Red
    Write-Host "Check $logDir\worker-*.log - a service that installs but will not start is almost always a bad path or a PHP that cannot boot." -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "All workers running." -ForegroundColor Green
Write-Host "Confirm the app agrees - /renders should show three queues as 'current':" -ForegroundColor Green
Write-Host "  $Php $App\artisan tinker --execute=`"print_r(App\Support\WorkerHealth::all());`""
Write-Host ""
Write-Host "After any change to .env, config/providers.php or provider code:" -ForegroundColor Yellow
Write-Host "  $Php $App\artisan queue:restart      # the services restart themselves"
