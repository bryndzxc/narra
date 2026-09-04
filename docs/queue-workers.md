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
php artisan queue:work redis --queue=assets --tries=3 --max-time=32400
php artisan queue:work redis --queue=text   --tries=3 --max-time=10800
```

Those two numbers are derived below, from measured job durations, and they are
not round on purpose. See **Sizing `--max-time`**.

| Queue    | Processes | Why                                                                  |
|----------|-----------|----------------------------------------------------------------------|
| `render` | 1–2       | CPU-bound FFmpeg. A mux is tens of minutes and wants the cores.       |
| `assets` | 4–8       | Image and TTS calls. Mostly waiting on somebody else's server.        |
| `text`   | 2–4       | Script and metadata generation. Also mostly waiting.                  |

They are separate processes so a 40-minute mux can never block a script draft.

`--tries=1` on `render` is deliberate: re-running a render stage is minutes to
hours of CPU, and from Phase 2 it is real money. Retrying is an operator
decision, not an automatic one.

### `--tries=3` on `assets` does not apply to the paid stages

The three stages that spend money — stills, narration, word timings — set
`$tries = 1` on the job class, and a property beats the flag. That is not an
oversight and the flag is left at 3 for anything else that ever runs on this
queue.

An automatic retry of a call that already billed is an automatic second charge,
and the worker cannot tell "the provider never answered" from "the provider
answered, billed, and something after that threw". Transient failures are
retried where they can still be retried for free: inside the provider, at the
HTTP layer, before the call has succeeded — `providers.fal.max_retries` and its
equivalents. A job that gets past that has spent something, so it stops and
flags the scene instead.

Which is why the retry is a button. A failed scene lands at `SceneStatus::Failed`
and is listed on the Gate 2 page; pressing **Generate assets** again
re-dispatches exactly those scenes and nothing else. Every asset action is
idempotent, so even a job that runs against a finished scene declines to bill.

## Sizing `--max-time`

`--max-time` is the only worker-level timeout that does anything on this
platform, and it is the one that has to be sized against real work rather than
picked. `assets` sat at 3600 from the day it was written, when a story was 186
scenes and nobody had measured a fal call. Story 21 is 270 scenes and needs six
hours, so the worker exited cleanly a third of the way through, over and over,
and the progress page reported a run in flight every time. That is what the
`stranded` state on the workers panel now exists to say out loud.

**Measured, not assumed.** Per-job seconds from `render_jobs`, succeeded jobs
only, stories 9 and 21:

| Stage             |   n | p50 | p90 | p99 | max |
|-------------------|----:|----:|----:|----:|----:|
| `images` (fal)    | 303 |  53 |  69 |  92 | 117 |
| `scene_narration` | 187 |   1 |   2 |   4 |   7 |
| `scene_timings`   | 187 |  10 |  11 |  13 |  13 |

Two things in that table are worth reading twice. A fal image is **~53 s at the
median, not the ~30 s everyone quotes** — the estimate was low by 75%, which is
most of how the old number survived. And ElevenLabs TTS for a 35-word scene is
about a second, so narration is a rounding error next to the stills; the three
stages are nothing like equal thirds.

**One worker must be able to carry a whole run alone.** Not because one worker
is the plan — 4–8 is — but because the batch must not depend on how many
somebody happened to start. So size on the serial total:

```
max_time  =  scenes × (p99_images + p99_narration + p99_timings) × headroom
          =  270    × (92 + 4 + 13)                              × 1.1
          =  270    × 109                                        × 1.1
          ≈  32,400 s   (9 hours)
```

p99 rather than the mean, because the mean is what got us here. The same
arithmetic at the median gives 4 h 48 m and at p90 gives 6 h 9 m, so 9 hours is
the worst plausible single-worker run with a little room, not a guess with a
zero on the end. **Re-derive it when the scene count changes** — at 400 scenes
this is 13 hours, and the failure is silent.

**`text` was undersized too, and for a different reason.** `WriteStoryJob` is
one job that writes the outline and then every act script sequentially, and
`ANTHROPIC_TIMEOUT` is 900 s per call. A 7-act story is therefore a single job
with a worst case of 8 × 900 = 7,200 s, against a worker recycling at 3,600.
Measured, an outline is ~60 s and scene drafting ~750 s, so the median run is
nowhere near it — which is exactly the shape that waits for a slow day. 10,800
covers the worst case with headroom.

`render` stays at 21,600. One mux is one job and the whole worker lifetime; six
hours has held for a 58-minute render.

**These are ceilings, not budgets.** A worker that finishes the batch exits when
the queue drains, whatever `--max-time` says. Raising it costs nothing on a
quiet queue.

### It does not remove the need for a service

A worker that exits at `--max-time` is behaving correctly — that is the flag
doing its job, recycling the process. The defect was never the exit; it was that
nothing started another one. Sizing the number buys margin for the run in front
of you. **NSSM is what makes the exit a non-event**, and it is the actual fix
here: a service restarts and picks up the rest of the batch, so the ceiling
stops being a cliff.

## A running worker does not see your `.env` change

`queue:work` is a daemon. It boots the framework once, resolves its container
once, and then loops. **Editing `.env`, editing config, or deploying code
changes nothing for a worker that is already running** — it keeps the bindings
it started with until it exits.

This is not a footnote. It produced the most expensive mistake in the project
so far:

1. `PROVIDER_IMAGE_GENERATOR` was set to `fal` and the CLI correctly quoted
   $6.51 against `fal-ai/bytedance/seedream/v5/lite/edit`.
2. An `assets` worker had been running since hours earlier, from before the
   change. It was still bound to `FakeImageGenerator`.
3. It took 185 of the 186 image jobs and produced flat-fill placeholder PNGs.
   The vendor was never contacted. The dashboard showed zero credits consumed.

**After any change to `.env`, `config/providers.php`, or provider code:**

```bash
php artisan queue:restart          # asks workers to exit after the current job
# then CONFIRM they actually exited before dispatching anything:
Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
  Where-Object { $_.CommandLine -like '*queue:work*' } |
  Select-Object ProcessId, CreationDate
```

`queue:restart` is a cache flag, not a signal — a worker notices it between
jobs, so a worker mid-encode takes as long as that job takes. Check the process
list; do not assume. If workers run as NSSM services, restart the services.

### Do not edit `app/` or `config/` during a paid run

The run fingerprint's CODE axis is a marker over `app_path()` and
`config_path()`, sealed at each process's boot. Every queued job carries the
fingerprint of the process that dispatched it. So **any edit under those two
directories invalidates every job already sitting in the queue** — the next
worker to pick one up refuses it with a `StaleWorkerException`, and
`SceneAssetJob` then cancels the whole batch on purpose, because a stale worker
is wrong about every scene rather than about that one.

That is not a hypothetical either. Story 21's 270-scene run was cancelled this
way at scene 142: the queue had been loaded at 14:33, the code was edited during
the run to fix the very stall being diagnosed, NSSM brought up workers on the
new code at 16:56, and two minutes later the first pinned job was refused. About
400 remaining jobs then drained out of Redis as no-ops, which reads exactly like
a batch completing. Nothing was mis-billed and nothing was half-generated —
that is the cancel working — but the run had to be dispatched again.

**Safe to edit while assets or renders are in flight:** `docs/`, `tests/`,
`resources/views/`, `scripts/`, `CLAUDE.md`. None of them is in the marker.

**Not safe:** anything under `app/` or `config/`, including a comment. Wait for
the batch, or accept that you are cancelling it.

### The guard, for when you forget

Every paid asset job carries the provider name that was quoted to the operator,
pinned into the job payload at dispatch. Before generating anything, the job
compares it against what its own process resolves, and refuses loudly if they
differ:

> This job was queued against provider "fal" but this worker resolves "fake".

Nothing is generated and nothing is billed when that fires. It fails the scene,
which puts it in the retry set on the Gate 2 page. The point is that the failure
is now loud and immediate on job one, rather than 185 silent substitutions
discovered afterwards on a vendor dashboard.

The guard is a backstop, not a substitute for restarting the workers.

## `retry_after` must be longer than your longest job

This is the server-side half of the timeout story, and on this platform it is
the half that actually works — `--timeout` needs `pcntl` and does nothing, but
`retry_after` is enforced by the queue itself.

It is set on the **connection**, in `config/queue.php`:

```php
'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 21600),
```

Laravel's default is **90 seconds**. The mux is a full re-encode of 30-40
minutes of 1080p with burned-in subtitles and runs for tens of minutes. At 90
seconds the queue concluded the job had died, made it available again, and the
second render worker picked it up, saw `attempts > tries`, and marked it
**failed while the original FFmpeg was still encoding**. The result was a story
with a growing `final.mp4` and a failed job row, unable to reach `rendered`.

The case that did not happen only by luck: with `tries > 1` the redelivery does
not fail fast — it starts a **second FFmpeg writing the same output file**.

The rule: `retry_after` > the longest **job**, and ideally equal to the worker's
`--max-time`, so a stuck job is recycled by the worker rather than re-delivered
by the queue.

The `assets` worker now runs past that at 32,400, and that is fine — the two
numbers are answering different questions. `retry_after` bounds ONE job; the
`assets` jobs are short and many (117 s at the worst still measured, and the
provider clients time out well before that anyway), so 21,600 is three orders of
magnitude of headroom. The equality rule was written for `render`, where one job
genuinely is the worker's whole lifetime. Do not "fix" the mismatch by lowering
`assets` back.

## `--timeout` does nothing here — read this

Laravel enforces `--timeout` with a `pcntl` alarm. Without `pcntl` the flag is
**silently ineffective**: a hung FFmpeg call or a stalled HTTP request occupies a
worker indefinitely, with no error and no recovery. Note it is not even passed
above, because passing it would imply protection that does not exist.

What actually protects the workers:

1. **An explicit timeout on every call that leaves this process.** Enforced in
   userland, no `pcntl` needed. This is the real protection, and it has to
   exist in both halves of the app: a hung provider call and a hung FFmpeg
   hold a worker in exactly the same way, and only one of them is local.

   | Where | Mechanism | Setting | Default |
   |---|---|---|---|
   | FFmpeg | Symfony `Process::setTimeout()` | `render.timeouts.*` | per stage |
   | fal (stills) | `Http::timeout()` + `connectTimeout(15)` | `providers.fal.timeout_seconds` | 180 s |
   | ElevenLabs (TTS) | `Http::timeout()` + `connectTimeout(15)` | `providers.elevenlabs.tts.timeout_seconds` | 180 s |
   | ElevenLabs (reference sheets) | `Http::timeout()` plus an explicit poll deadline | `providers.elevenlabs.timeout_seconds`, `.poll_timeout_seconds` | 120 s, 300 s |
   | Anthropic (text) | `Http::timeout()`, streamed | `providers.anthropic.timeout_seconds` | 900 s |
   | WhisperX (local) | Symfony `Process::setTimeout()` | `providers.whisperx.timeout_seconds` | 600 s |

   Audited end to end while sizing `--max-time`, because "the guard exists in
   one place and not the one beside it" is this project's most common defect
   and a provider client without a timeout would hold a worker forever with
   nothing to report it. It does not have that shape: every provider has one.
   The table lives here rather than in one class's docblock so the next
   provider added has a row to fill in.

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

## Seeing the workers without a terminal

The console starts every stage now — a story goes premise to publish sheet
without a command — which removes the three terminal windows that were, in
practice, the worker health display. You could see them scrolling.

So the workers announce themselves and the pages read the announcement. Every
`queue:work` process writes its pid, boot time and run fingerprint into the cache
on each poll (`App\Support\WorkerRegistry`), and the panel on the stories index,
the renders pages and beside every dispatch button reports what is there
(`App\Support\WorkerHealth`).

Three states, and the difference between them is the whole point:

| State | Means | What happens on dispatch |
|---|---|---|
| **current** | Live workers, fingerprint matches this process | Normal |
| **stale** | Live workers that booted before the current code | **Refused.** Nothing is queued. |
| **nothing listening** | No worker on that queue, and the queue is empty | **Allowed, with a warning.** The job queues and waits; nothing is lost and nothing happens. |
| **stranded** | No worker on that queue, and jobs are waiting on it | **Allowed, with an alarm.** Nothing on that queue will run until a worker does. |

**The readout does not decide anything.** The refusal lives in
`AssertWorkersCurrent`, in the dispatching process, which is the only one in the
system with fresh code by construction — the operator started it seconds ago.
A page rendering a second opinion would be a guard downstream of the thing it
distrusts, which is how every defence in this project has failed. The panel
reads the same registry the refusal reads and recomputes nothing.

The panel exists because **absence is a warning, not a refusal**, and correctly
so. Without it, "queued" and "queued into a dead queue" look identical on the
page the operator watches instead of the pipeline. That is the fifth row of the
false-success table, which is the one that was not in the pipeline either.

### Why the panel had to start reading the queue itself

Absence alone turned out not to be enough, and the reason is structural.

`render_jobs` **cannot count a backlog**. `RenderJob::open()` runs inside the
job, so a scene still sitting in Redis has no row at all. When the `assets`
worker exited at `--max-time` part way through story 21, every row on the
progress page was finished, nothing had failed, no heartbeat was stale, and 152
scenes were waiting — so `$overall['active']` went false, the meta refresh came
off the page, and the footer read *"Nothing running — this page is not
refreshing itself."* Every number on it was correct and the page as a whole was
false.

So `WorkerHealth` now also asks the queue how deep it is, through
`Queue::connection()->size()`, which sums the ready list, the delayed set and
the reserved set. It is the one fact on that panel that does not come from the
app's own bookkeeping, and it is the only thing that can tell *nothing left to
do* from *nobody doing it*.

Depth on its own is not an alarm — a live worker with 416 jobs behind it is just
a worker working, and the panel says so. Depth **with nobody listening** is
`stranded`, and that is the state that gets the red box and the service name to
start.

A depth that cannot be READ is shown as `unreadable`, never as `0`. A Redis that
is down would otherwise report every queue as calmly empty at exactly the moment
the instrument broke, which is the same substitution one level up.

### Uptime is shown, and it is not evidence

The panel shows how long the longest-running worker has been up. That is not a
staleness signal — the fingerprint is — but it is the thing worth looking at
when the fingerprint says everything agrees and something is still wrong.

It matters more under NSSM than it did with hand-started terminals, for a reason
worth stating plainly: **NSSM raises the stale rate.** A terminal window dies on
reboot and gets restarted with current code — accidental freshness. A service
that has been up for six days across four `.env` edits is precisely the stale
worker, and it restarts itself after every crash you might otherwise have
noticed. NSSM removes "I forgot to start it" and amplifies "it has been running
since before the fix". Install it anyway — `queue:restart` only asks a worker to
exit, and something has to bring it back — but do not mistake it for a
mitigation of the failure that cost 15,308 credits.

## Running them as services (NSSM)

There is no Supervisor on Windows. Use [NSSM](https://nssm.cc/) to register each
worker as a Windows service so it restarts on crash and survives a reboot. Task
Scheduler is a weaker fallback. Do not rely on manually-opened terminal windows —
a closed terminal is a stopped pipeline, and nothing reports it.

**It is one script, and it must run elevated.** The instructions used to be a
wall of `nssm set` lines nobody had ever run, which is how a set-up step this
load-bearing stayed undone through two finished videos.

```powershell
# From an ELEVATED PowerShell prompt.
powershell -ExecutionPolicy Bypass -File E:\laragon\www\narra\scripts\install-worker-services.ps1
```

`scripts/install-worker-services.ps1` downloads nssm if it is not already
there (into `tools/nssm`, which is gitignored), registers `NarraText`,
`NarraAssets` and `NarraRender` with the `--max-time` values derived above,
starts them, and **verifies they are actually running** before reporting
success. It refuses immediately without administrator rights rather than
half-installing, and it is idempotent — run it again to apply a changed
`--max-time`.

The settings that matter, and why none of them is a default:

| Setting | Value | Why |
|---|---|---|
| `AppExit Default Restart` | restart | **The one that makes this worth doing.** A `--max-time` exit is a clean exit 0. Left at NSSM's default, that reads as "the app is finished" and the service stops — reproducing the original stall with extra steps. |
| `AppRestartDelay` | 5000 ms | Space between restarts. |
| `AppThrottle` | 10000 ms | An exit inside 10 s is a crash loop, not a recycle. A PHP that cannot boot otherwise spins as fast as the CPU allows and fills the log with one fatal. |
| `AppStopMethodConsole` | 60000 ms | Ctrl-C first, so `queue:work` finishes the job in hand rather than dying mid-FFmpeg or mid-billed-call. |
| `AppRotateOnline` / `AppRotateBytes` | 10 MB | A worker log at this volume fills a disk otherwise. |

More workers:

```powershell
# 4 assets workers (NarraAssets, NarraAssets2..4). More throughput, and a
# proportionally faster rate of spend — which is why it is not the default.
... -File .\scripts\install-worker-services.ps1 -AssetsWorkers 4

# Two render workers is the ceiling worth having on one box: the scene clips
# already saturate the cores, and a third makes every clip slower rather than
# the batch faster.
... -File .\scripts\install-worker-services.ps1 -RenderWorkers 2
```

`... -Uninstall` stops and removes all of them.

`nssm restart NarraRender` after a deploy, or let `queue:restart` recycle
them — under NSSM the service brings the worker back on its own, which is the
whole difference from a terminal.

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

Every one of those is also a button now — `render:cancel` is on the Gate 3 page,
and re-dispatch is the same button that dispatched in the first place. The
commands are kept deliberately: they are the fallback when the UI breaks, and
they consult the same `OperatorAction` predicates the buttons do, so neither can
offer a move the other refuses.

## Which queue runs what

| Queue | Stages | Started from |
|---|---|---|
| `text` | outline, act scripts, cast, scene drafts, publish sheet | New story page, Gate 1, Gate 2, Gate 4 — or `story:write`, `story:scenes`, `metadata:generate` |
| `assets` | stills, narration, word timings | Gate 2 — or `assets:generate`, `assets:timings` |
| `render` | scene clips, concat, subtitles, mux, purge, deliver | Gate 3 — or `render:dispatch` |

`text` carried nothing at all for two phases while the setup docs told operators
to run a worker for it. It now carries the stage that starts every video.
