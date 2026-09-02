# Narra

> Working name — internal and product-facing only. The YouTube audience never sees it.

A Laravel app that turns a written premise into a finished, narrated, subtitled
30–40 minute YouTube video built from AI-generated still illustrations with slow
camera motion, plus the complete metadata sheet needed to publish it.

It is **not** a "generate and upload" button. An operator makes four real
editorial decisions at four fixed gates; everything between the gates is
automated. The app produces a file and a publish sheet — a human uploads it.

---

## Contents

- [What it produces](#what-it-produces)
- [The four gates](#the-four-gates)
- [How a video gets made](#how-a-video-gets-made)
- [A real run](#a-real-run)
- [Stack](#stack)
- [Requirements](#requirements)
- [Setup](#setup)
- [Queue workers](#queue-workers)
- [Command reference](#command-reference)
- [Pages](#pages)
- [Where the code lives](#where-the-code-lives)
- [Design rules that are load-bearing](#design-rules-that-are-load-bearing)
- [Testing](#testing)
- [Known gaps](#known-gaps)

---

## What it produces

For one story, at the end of a run:

- `final.mp4` — 1920×1080, 30fps, H.264, AAC 192k, burned-in karaoke subtitles
- A YouTube publish sheet — 5 title variants, a description with a chapter list,
  tags inside the 500-character budget, thumbnail overlay text, a pinned comment
  and a publish checklist
- A per-video cost ledger — every paid API call writes a row, so
  "what did this video cost" is one query

**Format is the defining constraint.** 30–40 minutes, ~5,900–6,400 words of
narration, 150–250 stills. Long-form is chosen because watch time drives revenue
in this niche far more than upload count, and videos over 8 minutes are eligible
for mid-roll ads. That length changes the architecture in several places — see
[Design rules](#design-rules-that-are-load-bearing).

**Audience is the United States**, operated from the Philippines. Scripts must
read as American; a denylist check fails a generated act loudly rather than
letting Filipino idiom reach Gate 1.

---

## The four gates

These are the product. None may be automated away.

| Gate | Waits at | Page | The decision |
|---|---|---|---|
| **1 — Outline** | `outlined` | `/stories/{slug}/outline` | Approve the act-by-act structure the whole script is written against |
| **2 — Scenes** | `scenes_drafted` | `/stories/{slug}/scenes` | Review every scene's narration and image prompt **before any paid asset exists** |
| **3 — Preview** | `rendered` | `/stories/{slug}/preview` | Watch the render |
| **4 — Metadata** | `metadata_ready` | `/stories/{slug}/metadata` | Pick the title, edit the description, work the checklist |

Story status moves through:

```
draft → outlined → scripted → scenes_drafted → scenes_approved
      → assets_generating → assets_ready → rendering → rendered
      → metadata_ready → published
```

**Spending is not a gate crossing.** Approving a gate is a quality decision;
dispatching paid work is a money decision. They get separate buttons, because a
gate crossing cannot be re-crossed — folding dispatch into approval would mean
reopening a gate just to retry a handful of failed scenes.

---

## How a video gets made

```
GenerateOutline           → gate 1   [free — text only]
GenerateActScripts        (sequential, each act fed summaries of the ones before)
ExtractCharacters         (the cast, described once, pasted into every prompt)
DraftScenes               → gate 2   [free — no paid assets yet]
──────────────────────────────────────  operator approval required
GenerateImages            (fan out, one job per scene)
GenerateSceneNarration    (fan out, one job per scene)
TranscribeSceneTimings    (fan out, one job per scene)
RenderSceneClips          (fan out, one job per scene)
ConcatClips
GenerateSubtitles
MuxAndSubtitle            → gate 3
PurgeRenderScratch                   [chained after the mux, never reached on failure]
GenerateMetadata          → gate 4   [needs act timestamps, so it runs last]
```

### Text generation is chunked, never one call

A single API call cannot hold 7,000 words of coherent narrative. The shape is
`premise → act outline (5–8 acts) → per-act script, each call given the outline
plus a running summary of the acts already written`. `GenerateActScripts` is the
one stage in the whole pipeline that **must stay sequential** — act 4 needs to
know what happened in acts 1–3.

Model routing is per-operation, in `config/providers.php`, because the calls are
not the same kind of work:

| Operation | Model | Why |
|---|---|---|
| `generate_outline` | Opus | One call; every act is written against it |
| `generate_act_script` | Opus | The narration itself. Measured against Sonnet and moved back |
| `extract_characters` | Sonnet | One call, pasted verbatim into 150–250 image prompts |
| `draft_scenes` | Haiku 4.5 | Mechanical and structurally checkable, with a Sonnet fallback |
| `generate_titles` | Opus | The title decides whether any of the rest is watched |
| `generate_copy` | Sonnet | Thumbnail text and pinned comment |
| `generate_tags` | Haiku 4.5 | A keyword list against a hard character budget |

`story:write` and `metadata:generate` both print the live roster before spending.

### Render is three FFmpeg steps

1. **Scene clip** — upscale the still to 3840px, `zoompan` on the large version,
   downscale to 1920×1080. Zooming at output resolution is visibly jittery; this
   is the single most important detail in the render pipeline.
2. **Concat** — concat demuxer, stream copy, effectively instant. Audio is *not*
   concatenated this way: per-scene audio is padded and joined as PCM, then
   encoded once at mux.
3. **Mux** — burn in the `.ass` karaoke subtitles and encode the final MP4. This
   re-encodes the whole video and is the longest single operation.

**Frame count is authoritative.** `frames = ceil(audio_ms / 1000 * fps)`, clip
duration is `frames / fps` by construction, and audio is padded to the video
rather than the other way round. Offsets accumulate in integer frames and
samples, never in rounded milliseconds — summing milliseconds compounds error
scene by scene, and a one-frame error per scene is seconds of desync by minute
35.

---

## A real run

Story 9, generated end to end with real providers:

| | |
|---|---|
| Runtime | 29m 38.7s |
| Acts / scenes | 6 / 186 |
| Narration | 5,781 words |
| Stills | 186, fal.ai Seedream |
| Voice | ElevenLabs, Brian, speed 1.0 |
| Word timings | Local WhisperX forced alignment |
| **Total cost** | **$12.44** |

Spend by provider:

```
fal (images)          $8.1550
anthropic (text)      $2.6350
elevenlabs (TTS)      $1.6478
whisperx (alignment)  $0.0000   — local, real work, genuinely free
```

Images are ~66% of the bill, which is why Gate 2 sits where it does.

The ledger holds **1,018 rows** for this story: 646 real calls (186 of them the
free local alignments) and 372 from earlier stand-in runs, recorded at exactly
$0.00 because a simulated call contacted no vendor and owes nothing. That
separation is the whole point — a fixture run once wrote $8.12 of plausible-
looking spend and was indistinguishable from a real one at a glance.

It came back 21 seconds under the 30-minute target because the script was sized
at 160 wpm against a narrator who reads 197. It ships at 29:38.7 rather than
having its target lowered to match — see *Never move a target to match a result*
in [CLAUDE.md](CLAUDE.md).

---

## Stack

| Layer | Choice |
|---|---|
| Framework | Laravel 12, PHP 8.3 |
| Database | MySQL 8 |
| Queue | Redis + plain `queue:work` (**no Horizon** — see below) |
| Frontend | Blade + Livewire 4 (server-driven; internal tool, not a SPA) |
| Media | FFmpeg 6+ (libx264, libass, zoompan) |
| Storage | Local disk. S3-compatible later — code against `Storage::disk()` |
| Text | Anthropic (`anthropic-ai/sdk`) |
| Images | fal.ai Seedream |
| Speech | ElevenLabs |
| Alignment | Local WhisperX (Python) |

Every provider sits behind an interface in `app/Contracts/` with a `Fake` in
`app/Services/Fake/`. **Tests never hit the network** — in the `testing`
environment every interface binds to its fake unconditionally, before config is
consulted.

---

## Requirements

```bash
php -v                      # 8.3+
composer -V
mysql --version             # 8.0+
redis-server --version
node -v                     # 20+ (Vite only)
ffmpeg -version             # 6.0+
```

FFmpeg must have these. Verify before running anything:

```bash
ffmpeg -version | grep -o 'enable-libass'      # burned-in subtitles
ffmpeg -filters | grep zoompan                 # Ken Burns motion
ffmpeg -filters | grep -w concat
ffmpeg -encoders | grep libx264
```

If `libass` is missing the whole subtitle approach collapses — fix that first.

**Disk:** budget ~3 GB of scratch per in-flight video. Scratch is purged
automatically after a successful mux; `final.mp4`, `subs.ass` and
`scene_audio.json` are kept.

**Windows note:** keep the project path short and generated filenames slugged.
With 200 scene files under nested storage directories, the 260-character path
limit is reachable.

---

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate          # includes job_batches and failed_jobs
npm install && npm run build
```

`Bus::batch()` is core Laravel and needs no Horizon, so the 200-scene fan-out
stages work as specified — `job_batches` and `failed_jobs` ship in the stock
jobs migration and are already applied by `migrate`. What is lost without Horizon
is the batch dashboard, which `/renders/{slug}` replaces.

Then set the provider keys in `.env`:

```dotenv
ANTHROPIC_API_KEY=
FAL_API_KEY=
ELEVENLABS_API_KEY=
NARRATION_VOICE_ID=            # pick one with `php artisan voices:list --set=<story>`

PROVIDER_SCRIPT_WRITER=anthropic
PROVIDER_METADATA_WRITER=anthropic
PROVIDER_IMAGE_GENERATOR=fal
PROVIDER_REFERENCE_IMAGE_GENERATOR=fal
PROVIDER_SPEECH_SYNTHESIZER=elevenlabs
PROVIDER_TRANSCRIBER=whisperx
```

Every provider except the two text ones defaults to `fake`, so nothing can start
billing because a job got wired up early. Switching one on is an edit to `.env`
— a decision with a date on it, not a side effect of deploying.

To run the pipeline with no network and no cost, leave them all at `fake` and
import a fixture set:

```bash
php artisan render:import sample-story    # a directory on the fixtures disk
```

`sample-story` is 12 scenes for fast iteration; `long-story` is padded to full
length, because long renders fail in ways short ones do not — disk exhaustion,
worker timeouts, audio/video drift.

---

## Queue workers

**There is no Horizon and there must not be.** Horizon hard-requires `pcntl` and
`posix`, which do not exist in Windows PHP — not missing, absent by design. Do
not install it, do not add it to `composer.json`, do not reference
`horizon:work`.

Three queues, three processes:

```bash
php artisan queue:work redis --queue=render --tries=1 --max-time=21600
php artisan queue:work redis --queue=assets --tries=3 --max-time=3600
php artisan queue:work redis --queue=text   --tries=3 --max-time=3600
```

`render` runs 1–2 processes (CPU-bound). `assets` and `text` can run more (they
wait on somebody else's HTTP server). A 40-minute mux must never block a script
draft.

> **`--timeout` does not work on Windows.** Laravel enforces it with a `pcntl`
> alarm, so the flag is silently ineffective. Real protection comes from explicit
> Symfony Process timeouts in the FFmpeg wrapper, explicit HTTP client timeouts
> in the providers, `--max-time` recycling, and a heartbeat on `render_jobs`
> that the operator UI surfaces when it goes stale.

**Restart workers after any code or `.env` change.** A worker holds the code and
config it booted with. This has cost the project 15,308 TTS credits and two
failed render dispatches. `AssertWorkersCurrent` now refuses a dispatch when a
live worker's fingerprint disagrees with the dispatching process — but restart
anyway:

```bash
php artisan queue:restart      # works normally; uses a cache flag, not signals
```

For running these as Windows services under NSSM, see
[docs/queue-workers.md](docs/queue-workers.md). Do not rely on manually-opened
terminal windows.

---

## Command reference

Every stage has a command. The four gates are pages.

### Writing a story

```bash
php artisan story:write --premise="..." --title="..."   # outline + every act
php artisan story:write <story> --acts-only=4           # rewrite one act
php artisan story:scenes <story>                        # cast, then scenes → Gate 2
php artisan story:fork <story>                          # copy an outline for an A/B run
```

### Scene edits (free — narration stays verbatim)

```bash
php artisan scenes:merge <story> <sequence>    # merge a scene with the one after it
php artisan scenes:recut <story>               # reassign motion presets
```

### Character references (Gate 2)

```bash
php artisan characters:sheets <story>
php artisan characters:verify <story>
```

### Paid assets

```bash
php artisan narration:preflight <story>    # providers, voice, quota, aligner — before spending
php artisan narration:bakeoff <story>      # one scene at several speeds, to compare by ear
php artisan voices:list --set=<story> --voice=<id>
php artisan assets:generate <story>        # stills + narration + timings → assets queue
php artisan assets:timings <story>         # re-align scenes that have audio but no timings; never bills TTS
```

### Render

```bash
php artisan render:dispatch <story>        # the whole pipeline onto the render queue
php artisan render:cancel <story>          # call off an in-flight batch
php artisan render:purge <story> --dry-run # scratch is normally purged automatically
```

Individual steps, for debugging a stage in isolation:

```bash
php artisan render:clips <story>
php artisan render:concat <story>
php artisan render:subtitles <story>
php artisan render:mux <story>
```

### Publish sheet (Gate 4)

```bash
php artisan metadata:generate <story>           # 3 calls, cents
php artisan metadata:generate <story> --queue   # onto the text queue instead
php artisan metadata:generate <story> --force   # overwrite a sheet already written
```

### What the spending commands do before they spend

Each one prints the provider resolved **from the container** — not from config;
those disagreed once and put $8.12 in the ledger against a vendor that was never
contacted — plus the model roster for the calls it is about to make, an itemised
quote, and a confirmation prompt.

To see the quote without spending anything:

```bash
php artisan assets:generate <story> --estimate    # breakdown, queues nothing
php artisan assets:timings <story> --dry-run
php artisan render:purge <story> --dry-run
```

The skip-confirmation flag differs by command and that is deliberate:
`story:write`, `story:scenes` and `metadata:generate` take `--yes`;
`assets:generate` takes `--force`, because it is the one behind the money line.

---

## Pages

| URL | What it is |
|---|---|
| `/stories` | Index — every story, its status, its cost |
| `/stories/{slug}` | Overview |
| `/stories/{slug}/outline` | **Gate 1** |
| `/stories/{slug}/scenes` | **Gate 2** — narration, prompts, reorder, delete, spend button |
| `/stories/{slug}/characters` | Character reference sheets |
| `/stories/{slug}/faces` | Every character's locked reference, and which scenes use it |
| `/stories/{slug}/preview` | **Gate 3** — the render, streamed with range support |
| `/stories/{slug}/metadata` | **Gate 4** — the publish sheet |
| `/renders` | Every story's stage progress |
| `/renders/{slug}` | Per-stage progress, failed scenes and why — the Horizon replacement |

`/renders/{slug}` is not a nice-to-have. At 200 scenes a partial failure is
invisible without it, and there is no Horizon dashboard on this platform.

---

## Where the code lives

```
app/
  Actions/          Business logic. Not controllers, not models.
  Contracts/        One interface per external provider.
  Enums/            StoryStatus, Gate, RenderStage, OperatorAction, CostCategory…
  Jobs/             Queued stages. Thin wrappers over Actions + bookkeeping.
  Livewire/Gates/   The four gate components.
  Services/
    Claude/         Anthropic script writer + metadata writer
    Fal/            Seedream image generation
    ElevenLabs/     TTS
    WhisperX/       Local forced alignment
    Fake/           A fake for every contract. Tests run against these.
    Ffmpeg.php      The ONE place that builds FFmpeg commands.
  Support/          Value objects, rate cards, guards, the render workspace.
config/
  providers.php     Which implementation, which model per call, every rate card.
  render.php        FFmpeg paths, timeouts, video/audio parameters, subtitle presets.
  youtube.php       Publish-sheet limits, description footer, Gate 4 checklist.
  scenes.php        Scene sizing and motion presets.
  characters.php    Reference-sheet settings.
  locale.php        The US-audience denylist and its ambiguous-term warnings.
docs/
  queue-workers.md  Running the three workers, including as NSSM services.
resources/whisperx/ The alignment script, committed rather than generated.
storage/app/fixtures/  sample-story (12 scenes) and long-story (full length).
```

`CLAUDE.md` is the full specification and the more important document. It carries
the reasoning behind every decision here, including the ones that look arbitrary.

---

## Design rules that are load-bearing

These are the ones that have already been violated once and cost something.

- **Every paid call writes a cost row.** No exceptions. A provider cannot return
  a result without saying what it cost.
- **A simulated call must cost exactly $0.00.** "Realistic" fake costs once put
  $8.12 in the ledger for 186 placeholder PNGs nobody was billed for.
- **Ask the container what ran, never config.** Config says what *should* be
  bound; only the object knows what *was* called.
- **A guard must be upstream of the thing it distrusts.** The dispatching
  process is the only one with fresh code by construction.
- **Never call `escapeshellarg()`.** Windows PHP replaces `%` and `"` with
  spaces rather than escaping them — silent corruption, no error. The FFmpeg
  wrapper passes an array of arguments to Symfony Process.
- **Filter-graph path escaping lives in exactly one method**,
  `Ffmpeg::escapeFilterPath()`, with a platform check. `clips.txt` paths use
  different rules and a separate method, with a test asserting the two differ.
- **Money is `decimal(10,4)`, never float. Durations are integer milliseconds.**
- **Migrations are never edited after being run.** New change, new migration.
- **Nothing is regenerated silently.** Re-running a stage is an explicit action,
  because re-running costs money.

### False success is a defect class

The app has reported success while something was silently wrong five times. The
individual bugs were all different; the constant is the reporting, and the
mechanism is that **absence is read as agreement**. A stage that never ran leaves
no failure row. A guard not in a worker's loaded code cannot fire, and a check
that cannot fire is indistinguishable from a check that passed.

When a check *cannot* run, that is a failure, not a pass.

### Bugs live at seams between phases

Every dead-code gap found so far was a mechanism built in one phase whose caller
was due in the next and never arrived. Nothing inside a phase was ever dead.
Phase-local tests do not catch this — at the end of every phase, audit for
declared-but-never-called Actions, contracts, enum cases, config keys, routes,
jobs and queues. The running record is in
[CLAUDE.md → Where bugs actually live](CLAUDE.md).

---

## Testing

```bash
php artisan test              # or: vendor/bin/phpunit
```

493 tests. They never touch the network: in the `testing` environment every
provider interface binds to its fake before config is consulted, so a test that
misconfigures itself gets a fake, not a bill.

Style:

```bash
vendor/bin/pint
```

---

## Known gaps

Honest list, not a roadmap.

- **Several operations are CLI-only.** `story:write`, `assets:generate`,
  `assets:timings`, `render:dispatch` and `metadata:generate` have no or partial
  UI triggers, and there is no way to create a new story from the browser at
  all. The four gates are wired; the stages between them are not. Closing this
  is the next piece of work.
- **Worker health is not on any page.** Workers are started by hand or by NSSM
  and the console cannot see them; a stale one is caught at dispatch, not before.
- **`SplitScene` has no production caller** — a complete Action with a verbatim
  recombination guard, reachable from no command and no button.
- **`CostUnit::InputTokens` has no writer**; the Anthropic writer records one row
  per call with the token split in `detail`.

## Out of scope

Not built, and no scaffolding for them until asked: YouTube upload API,
thumbnail image composition, multi-user accounts or tenancy, video-generation
models (Ken Burns on stills is the format, and ~100× cheaper), A/B thumbnail
testing, analytics dashboards.
