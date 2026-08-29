# Queue workers

Three queues, three worker processes, no Horizon.

Horizon hard-requires `pcntl` and `posix`. Those do not exist in Windows PHP —
not missing, not installable, absent by design — so it is not installed, not in
`composer.json`, and `horizon:work` appears nowhere. The queue layer is plain
`queue:work`, and the batch dashboard it would have provided is replaced by the
page at `/renders`.

## The three workers

```bash
php artisan queue:work redis --queue=render --tries=1 --max-time=21600
php artisan queue:work redis --queue=assets --tries=3 --max-time=3600
php artisan queue:work redis --queue=text   --tries=3 --max-time=3600
```

| Queue    | Processes | Why                                                                  |
|----------|-----------|----------------------------------------------------------------------|
| `render` | 1–2       | CPU-bound FFmpeg. A mux is tens of minutes and wants the cores.       |
| `assets` | 4–8       | Image and TTS calls. Mostly waiting on somebody else's server.        |
| `text`   | 2–4       | Script and metadata generation. Also mostly waiting.                  |

They are separate processes so a 40-minute mux can never block a script draft.

`--tries=1` on `render` is deliberate: re-running a render stage is minutes to
hours of CPU, and from Phase 2 it is real money. Retrying is an operator
decision, not an automatic one.

## `--timeout` does nothing here — read this

Laravel enforces `--timeout` with a `pcntl` alarm. Without `pcntl` the flag is
**silently ineffective**: a hung FFmpeg call or a stalled HTTP request occupies a
worker indefinitely, with no error and no recovery. Note it is not even passed
above, because passing it would imply protection that does not exist.

What actually protects the workers:

1. **Symfony Process timeouts**, set on every FFmpeg invocation in
   `App\Services\Ffmpeg`. Enforced in userland, no `pcntl` needed. This is the
   real protection. Values live in `config/render.php` under `timeouts`.
2. **`--max-time`**, which recycles each worker on a schedule regardless of what
   it thinks it is doing.
3. **The heartbeat.** Long-running jobs touch `render_jobs.updated_at` every
   `RENDER_HEARTBEAT_SECONDS` (default 30) while FFmpeg runs. A row that has been
   `running` with no heartbeat for `RENDER_STALE_AFTER_MINUTES` is hung, and
   `/renders/{slug}` says so at the top of the page in yellow. Nothing else on
   this platform will ever tell you.

### What each one actually catches

These overlap less than they look, and the difference was established by drill
rather than by reasoning:

| Failure | Caught by | How it looks |
|---|---|---|
| Worker process killed, crashed, or the box rebooted | **Heartbeat** | Row stuck at `running`, `updated_at` frozen. Yellow alarm on the page naming the stage and scene. |
| FFmpeg hung, worker alive | **Process timeout** | The heartbeat keeps beating — correctly, the worker *is* alive — so the row never goes stale. The Symfony timeout kills FFmpeg and the row goes `failed` with "exceeded its Ns timeout". |
| Corrupt input | **Pre-flight probe in the job** | `-loop 1` does not fail on a bad still; it loops forever printing "Invalid PNG signature" and emits no frames. One ffprobe call up front turns a 15-minute hang into an instant failure naming the scene. Note ffprobe exits **0** on such a file and reports 0x0 dimensions — the check is on the dimensions, not the exit code. |

The heartbeat proves the *worker* is alive, not that FFmpeg is making progress.
Both mechanisms are needed; neither substitutes for the other.

### Rehearsing the alarm

An alarm nobody has watched fire is not an alarm.

```bash
# 1. Drop the threshold, restart whatever serves the page.
#    RENDER_STALE_AFTER_MINUTES=1 in .env

# 2. Start a worker, dispatch a render, then kill the worker mid-job.
php artisan render:dispatch sample-story
#    Ctrl+C the worker, or `taskkill /F /IM php.exe` for the brutal version.

# 3. Wait a minute and load /renders/sample-story.
#    The row is still `running`; the page is yellow and names the stage and scene.
```

## Restarting workers

```bash
php artisan queue:restart
```

Works normally — it sets a cache flag rather than signalling processes, so the
absence of `posix` does not matter. Workers finish the job in hand and exit;
whatever supervises them starts them again.

## Running them as services (NSSM)

There is no Supervisor on Windows. Use [NSSM](https://nssm.cc/) to register each
worker as a Windows service so it restarts on crash and survives a reboot. Task
Scheduler is a weaker fallback. Do not rely on manually-opened terminal windows —
a closed terminal is a stopped pipeline, and nothing reports it.

Not yet installed on this machine. When it is, one service per queue:

```powershell
# Run from an elevated prompt. Adjust paths to match the box.
$php  = "E:\laragon\bin\php\php-8.3.22-Win32-vs16-x64\php.exe"
$app  = "E:\laragon\www\narra"

nssm install NarraRender  $php "$app\artisan" queue:work redis --queue=render --tries=1 --max-time=21600
nssm set     NarraRender  AppDirectory $app
nssm set     NarraRender  AppStdout    "$app\storage\logs\worker-render.log"
nssm set     NarraRender  AppStderr    "$app\storage\logs\worker-render.log"
nssm set     NarraRender  AppRotateFiles 1
nssm set     NarraRender  Start SERVICE_AUTO_START

nssm install NarraAssets  $php "$app\artisan" queue:work redis --queue=assets --tries=3 --max-time=3600
nssm set     NarraAssets  AppDirectory $app
nssm set     NarraAssets  AppStdout    "$app\storage\logs\worker-assets.log"
nssm set     NarraAssets  AppStderr    "$app\storage\logs\worker-assets.log"
nssm set     NarraAssets  Start SERVICE_AUTO_START

nssm install NarraText    $php "$app\artisan" queue:work redis --queue=text --tries=3 --max-time=3600
nssm set     NarraText    AppDirectory $app
nssm set     NarraText    AppStdout    "$app\storage\logs\worker-text.log"
nssm set     NarraText    AppStderr    "$app\storage\logs\worker-text.log"
nssm set     NarraText    Start SERVICE_AUTO_START

nssm start NarraRender; nssm start NarraAssets; nssm start NarraText
```

For a second render worker, install `NarraRender2` with the same command line.
Two is the ceiling worth having on one box — the scene clips already saturate
the cores, and a third process makes every clip slower rather than the batch
faster.

`nssm restart NarraRender` after a deploy, or let `queue:restart` recycle them.

## Where the state lives

| Thing                      | Where                                             |
|----------------------------|---------------------------------------------------|
| Queued jobs                | Redis (`QUEUE_CONNECTION=redis`)                  |
| Batch progress             | MySQL `job_batches` (`queue.batching.database`)    |
| Permanently failed jobs    | MySQL `failed_jobs`                               |
| Per-stage operator state   | MySQL `render_jobs`                               |

Batches and failures are deliberately in MySQL rather than Redis: they are what
the operator page reads, and they must survive a `redis-cli FLUSHALL` or a Redis
restart. `php artisan queue:batches-table` and `queue:failed-table` created both
schemas; they are already in this repo's migrations.

The Redis client is **predis**, not phpredis: phpredis is a compiled extension
and is not present in this PHP build. Predis is pure PHP and needs nothing
installed. `REDIS_CLIENT=predis` in `.env`.

## Retrying

```bash
php artisan queue:failed                 # what died, and why
php artisan queue:retry <uuid>           # one job
php artisan queue:retry all              # everything
php artisan render:cancel <story-slug>   # stop an in-flight batch
```

Re-dispatching a whole story's render is `render:dispatch <story-slug>`. It is
idempotent stage by stage: a clip that already holds the right frame count is
not re-encoded, correctly padded audio is left alone, and a finished mux is
verified rather than redone.
