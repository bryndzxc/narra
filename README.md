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
- [The arc a story has to carry](#the-arc-a-story-has-to-carry)
- [Thumbnails are composed, never generated](#thumbnails-are-composed-never-generated)
- [Two real runs](#two-real-runs)
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
- [Out of scope](#out-of-scope)

---

## What it produces

For one story, at the end of a run:

- `final.mp4` — 1920×1080, 30fps, H.264, AAC 192k, burned-in karaoke subtitles
- A thumbnail — 1280×720, a split panel composed from two stills the story
  already owns. No image is generated for it and it costs nothing
- A YouTube publish sheet — 5 title variants, a description with a chapter list,
  tags inside the 500-character budget, thumbnail overlay text, a pinned comment
  and a publish checklist
- A per-video cost ledger — every paid API call writes a row, so
  "what did this video cost" is one query

The MP4 and the thumbnail are copied out of the render workspace to
`RENDER_DELIVERY_PATH` as `<slug>.mp4` and `<slug>.jpg` — one findable pair per
story, rather than twenty files all called `final.mp4` in twenty scratch
directories. Copied, never moved: Gate 3's player, the purge guard and re-render
idempotency all read the workspace copy.

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
| **4 — Metadata** | `metadata_ready` | `/stories/{slug}/metadata` | Pick the title and the thumbnail, edit the description, work the checklist |

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
DeliverFinalVideo                    [copies final.mp4 out as <slug>.mp4. Last, and
                                      AFTER the purge: it is the only stage touching a
                                      path the app does not control, and failing ahead
                                      of the purge would strand ~700 MB of scratch on a
                                      render that actually succeeded]
GenerateMetadata          → gate 4   [needs act timestamps, so it runs last]
```

The thumbnail is composed at Gate 4 rather than in this chain, because choosing
one is an editorial decision and the operator has to see the candidates. The
chosen composition is copied out beside the video when the sheet is saved.

### Text generation is chunked, never one call

A single API call cannot hold 7,000 words of coherent narrative. The shape is
`premise → act outline (5–8 acts, seven by default) → per-act script, each call
given the outline plus a running summary of the acts already written`. `GenerateActScripts` is the
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

## The arc a story has to carry

The genre has a structure, and it is checkable. Seven columns on `stories` hold
it, the outline generator is required to fill all seven, and Gate 1 surfaces any
that are missing, thin or wrong in a way that shows in the text.

| Field | What it holds |
|---|---|
| `narrator_grievance` | Who wronged the narrator, in the first person |
| `antagonist_justification` | Their own account of why they were entitled to it |
| `withheld_information` | What the narrator knows and they do not |
| `exposure_moment` | Where it comes out, and in front of whom — the **public** payoff |
| `departure` | How and when the narrator leaves, and whether they announce it |
| `reversal_beats` | What the antagonist does to find them, and what each attempt costs **her** |
| `refusal` | What the narrator says when finally found — the **private** payoff |

The last three were added after watching a finished video back. It ran
*escalation → escalation → exposure → end* and gave the narrator power for
exactly one scene out of two hundred and seventy. Every check passed on it. What
the niche actually pays off on is a **phase**, not a scene:

```
escalation → the narrator LEAVES → the antagonist SEARCHES → the narrator REFUSES → end
```

`acts.phase` records which of those four an act belongs to, and the act-script
generator branches on it. That column is persisted rather than derived because
the previous prompt could only ask *"is this the last act"* and answered every
other act with "end worse off than it started" — right for act 2, and the exact
opposite of what act 6 of seven needs, where the ground is being lost by the
antagonist.

A single narrative gets **seven acts** by default, laid out by
`ActPhase::planFor()`: escalation 1–4, departure 5, search 6, refusal 7. It was
six while the arc stopped at the exposure; taking the reversal's room out of the
escalation would have traded one missing phase for another.

Gate 1's structural checks, none of which block — the operator's judgment beats
the heuristic, and a guard that refuses approval is a guard that gets removed:

- an antagonist whose justification reads as a confession rather than an excuse
- an exposure with nobody named in the room
- two acts claiming the same escalation
- **an announced departure** — the check with the least margin in it, because a
  narrator who says they are leaving cannot be searched for, and the search is
  the next third of the video
- **an outline with no departure act at all** — the shape the last video
  shipped as, and the one this whole structure exists to stop
- **a search that costs the antagonist nothing**, or that reads as one attempt
  rather than a phase
- **a refusal that answers no earlier moment by name** — it only lands as an
  inversion, so the check is overlap: the refusal has to reuse the specific
  language of the grievance, the justification or an escalation beat. Gate 1
  reports *which* moment it matched

Stories outlined before the phase existed say so once, as a warning naming what
is missing, rather than as three "missing field" errors on a shipped video.

---

## Thumbnails are composed, never generated

The channel's format: two stills side by side, cropped to portrait panels, faces
prominent, **no text overlay** — in this format the title is the hook, and words
burned into the image compete with it at the size anyone actually sees.

**It costs nothing, and that is the constraint the feature is built around
rather than a saving.** A 270-scene story has already paid for every frame it
could want; buying another one to crop in half would be spending money to avoid
making a choice. There is no provider here, no contract and no fake, because
there is no network call to fake — and a test asserts a composition run writes
no `cost_entries` row.

Three or four candidates are built on demand at Gate 4 and picked the way a
title is. `Ffmpeg::composeSplitPanel()` builds the command, like every other
media command in the app.

**The ranking is a proxy for face size and says so.** Nothing in this stack can
find a face in a JPEG — FFmpeg cannot, GD cannot, and buying something that can
would break the $0 rule. So `ThumbnailFraming` reasons from the two things the
app knows exactly: who is recorded in the frame (`scene_character`), and how the
frame was written — the frame sentence at the top of `image_prompt`, which is
the text that *produced* the picture. A wide establishing shot makes a poor
thumbnail however good the frame is, and one real story has exactly that flagged
as a thumbnail candidate: a chair in an empty room, nominated by the same model
that wrote the scene.

It ranks; it does not decide. The reasons print beside each composition and the
operator is looking at the actual image.

Pairing prefers the two **ends of the arc** — an escalation-phase still on the
left, a search or refusal still on the right, which is how this niche's
thumbnails read. Earlier scene left, later right. On a story outlined before
`acts.phase` existed it falls back to opposite ends of the scene list.

---

## Two real runs

Both generated end to end with real providers. Story 21 is the second setting
(`en-CN` — a Chinese setting narrated in American English) and is the longer of
the two.

| | Story 9 | Story 21 |
|---|---|---|
| Setting | `en-US` | `en-CN` |
| Runtime | 29m 38.7s | 40m 36.4s |
| Acts / scenes | 6 / 186 | 7 / 270 |
| Narration | 5,781 words | 8,065 words |
| Delivered file | — | 708 MB |
| **Cost of the video** | **$12.5428** | **$15.2440** |

Spend by provider, excluding evaluation:

```
                      story 9     story 21
fal (images)          $ 8.2600    $10.8150
anthropic (text)      $ 2.6350    $ 2.3097
elevenlabs (TTS)      $ 1.6478    $ 2.1193
whisperx (alignment)  $ 0.0000    $ 0.0000   — local, real work, genuinely free
```

Images are ~66–71% of the bill, which is why Gate 2 sits where it does.

Story 21 also carries **$0.0559 of evaluation spend** — style previews and
bake-offs, which are real money on real files and are the one cost category kept
*out* of the per-video total. It belongs to the channel rather than to the video
whose cast the preview borrowed, and it is shown beside the total wherever the
total is shown: logged and nowhere on screen is the same defect one level up.

> **Story 21's TTS figure is under-recorded by 2x** and is left as it is rather
> than corrected. ElevenLabs' `character-cost` response header is already the
> billable credit count — 183 characters sent, 92 charged — and the recorder
> multiplied it by the model's credit rate a second time, so the narration is on
> record at $2.12 and really cost about $4.24 at the plan rate. The *credits*
> column, which is what the monthly allowance is actually spent in, was right all
> along and reconciled to the vendor's own usage counter exactly. Story 9 is
> unaffected: its calls came back with no header, so it took the other path,
> which was and is correct.
>
> Fixed forward, not retroactively. `cost_entries` is write-once, and a ledger
> that edits itself is worth less than one that is wrong in a way you can date.

The ledger holds **1,021 rows** for story 9: 649 real calls (186 of them the
free local alignments) and 372 from earlier stand-in runs, recorded at exactly
$0.00 because a simulated call contacted no vendor and owes nothing. That
separation is the whole point — a fixture run once wrote $8.12 of plausible-
looking spend and was indistinguishable from a real one at a glance. Story 21
has 882 rows and not one simulated among them.

Story 9 came back 21 seconds under the 30-minute target because the script was
sized at 160 wpm against a narrator who reads 197. It ships at 29:38.7 rather
than having its target lowered to match — see *Never move a target to match a
result* in [CLAUDE.md](CLAUDE.md).

Both narrators have since been measured on their own prose: **197.0 wpm on
`en-US`** across story 9's 186 scenes, **199.5 on `en-CN`** across story 21's
270. The pace guard compares a run's cumulative words-per-minute against the
figure its script was sized to and stops the batch when a measured pair
disagrees — but only once **1,000 cumulative words** exist, because below that
the running average's own noise reaches ±12.6% against a 12% tolerance and the
guard is measuring where the sentence breaks fell rather than how fast anyone
read.

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

And where the finished files should land. Absolute, outside the project, and
empty means delivery is off — a render on a machine that has not set it is a
complete, correct render that reports the stage as skipped:

```dotenv
RENDER_DELIVERY_PATH=E:/Narra Videos
RENDER_DELIVERY_CREATE=true
```

A relative path is refused rather than resolved, because "relative to what" has
three plausible answers here and a worker running as a Windows service does not
share a working directory with your shell. The parent must already exist, which
is the guard that catches a wrong drive letter before 700 MB goes into a folder
nobody looks in.

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
php artisan queue:work redis --queue=assets --tries=3 --max-time=32400
php artisan queue:work redis --queue=text   --tries=3 --max-time=10800
```

`render` runs 1–2 processes (CPU-bound). `assets` and `text` can run more (they
wait on somebody else's HTTP server). A 40-minute mux must never block a script
draft.

**Those numbers are derived from measured job durations, not rounded.** `assets`
was `3600` — one hour — sized when a story was 186 scenes against an assumed 30
seconds an image. A real fal call measures 53 s at the median and a 270-scene
story needs six hours on one worker, so the worker exited mid-run, 152 jobs sat
in Redis with nothing listening, and the progress page went on reporting a
healthy run. The derivation is in
[docs/queue-workers.md → Sizing `--max-time`](docs/queue-workers.md), and it is
re-derivable when the scene count changes.

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

**Run them as services, not as terminal windows.** A worker started by hand
exits at `--max-time` and does not come back, which is the stall above. The
install is elevated and idempotent:

```powershell
scripts\install-worker-services.ps1
```

It is worth knowing what that trade is: NSSM makes a *stale* worker more likely,
not less — a service up for six days across four config edits is exactly the
worker running old code, restarting itself after every crash somebody might
otherwise have noticed. That staleness is refused loudly at dispatch by
`AssertWorkersCurrent`; the stall it removes was silent and cost hours per
occurrence. A refusal you can read beats a stall you have to notice. See
[docs/queue-workers.md](docs/queue-workers.md).

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
| `/stories/{slug}/metadata` | **Gate 4** — the publish sheet, and the thumbnail picker |
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
  youtube.php       Publish-sheet limits, description footer, channel upload
                    defaults, thumbnail composition, Gate 4 checklist.
  scenes.php        Scene sizing and motion presets.
  characters.php    Reference-sheet settings.
  locale.php        The US-audience denylist and its ambiguous-term warnings.
resources/views/partials/base-css.blade.php
                    The whole console's stylesheet, inlined, no build step — a
                    dashboard that fails to load because an asset pipeline is
                    down is worse than a plain one that always loads.
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
- **One quantity, one computation.** The pre-spend estimate and the cost ledger
  priced the same narration two different ways and disagreed by exactly the
  model's credit multiplier — in opposite directions, so each looked plausible
  on its own. The regression test asserts an *identity* between the two routes
  to a price rather than either against a constant, because a constant can be
  updated to match a bug.
- **A correct diagnosis of one defect is not evidence that it was THE defect.**
  A pace guard cancelled a 270-scene batch; the locale key it was blamed on was
  genuinely wrong and correctly fixed, and the batch would have died anyway from
  a second fault in the same call. When a guard fires wrongly, keep looking after
  the first thing you find wrong with it.
- **A guard that fires is evidence about its INPUT, not only about the thing it
  guards.** `PadSceneAudio` refusing at concat was read at first as "the audio is
  wrong"; it meant "the frame count handed to me is too small", and the fix was
  three steps upstream of where the alarm rang.
- **No refusal, warning or advisory may get quieter.** This applies to the
  stylesheet as much as to the code, and a restyle is the easiest place in the
  world to lose it: nothing fails, no test goes red, and the page simply becomes
  calmer than the truth. Alerts are deliberately louder than the panels around
  them. Where a page emits a run of identical warnings — Gate 2 on a 168-scene
  story emits fourteen — the fix is to let them cluster, never to tone any of
  them down.
- **A class the markup asks for and the stylesheet does not answer fails
  silently and looks deliberate.** `.panel.money` was written by the three
  screens where spending is authorised and defined by nothing, so all three
  rendered as an ordinary panel. Worse than a missing method, which at least
  throws.

### False success is a defect class

The app has reported success while something was silently wrong eight times. The
individual bugs were all different; the constant is the reporting, and the
mechanism is that **absence is read as agreement**. A stage that never ran leaves
no failure row. A guard not in a worker's loaded code cannot fire, and a check
that cannot fire is indistinguishable from a check that passed.

When a check *cannot* run, that is a failure, not a pass.

The most recent instance is worth knowing because nothing on the page was wrong:
a narration ledger that reconciled to the vendor's own counter *in credits* and
was half the truth *in dollars*. Rule three — keep one number you did not
compute — caught the quantity, because an external number existed for it.
Nothing external checks the dollars, and that is exactly where the surviving
error was.

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

653 tests. They never touch the network: in the `testing` environment every
provider interface binds to its fake before config is consulted, so a test that
misconfigures itself gets a fake, not a bill.

Style:

```bash
vendor/bin/pint
```

---

## Known gaps

Honest list, not a roadmap. Two entries that used to head it — CLI-only stages,
and worker health being invisible — are closed: every stage between the gates
now has a button on the page its decision belongs to, story creation has a
browser entry point, and worker state appears beside every dispatch button from
the same registry the staleness refusal reads.

- **The Gate 2 surgery tools are still terminal-only.** `scenes:merge`,
  `scenes:recut` and `SplitScene` are the operations that fix a bad scene list,
  and the console offers none of them — so the one part of Gate 2 that still
  needs a terminal is the part that edits what Gate 2 is for. Lower priority
  than the dispatch buttons were, because a bad cut is recoverable by
  re-drafting and a missing dispatch button was not.
- **`SplitScene` has no production caller at all** — a complete Action with a
  verbatim recombination guard and six tests, reachable from no command and no
  button. `characters:verify` and `story:fork` have no button either; both are
  free and neither blocks a video, which is the only reason they were left.
- **A story does not record the wpm its script was sized against.** The pace
  guard reads the expected figure live from config, so it answers "what do we
  believe now" while the guard needs "what was this script written to". Less
  urgent than it looked now that the second locale is measured — the two are
  1.26% apart — but it becomes real the first time a profile moves by more than
  a few percent. The fix is a `stories.sized_against_wpm` column written at
  outline time and frozen.
- **The dispatch preflight's notes do not reach the page built for deciding to
  spend.** `assets:generate --estimate` prints the itemised bill and exits before
  the preflight runs, so the pace expectation, the aligner probe and the style
  fingerprint appear only on a real dispatch.
- **`CostUnit::InputTokens` has no writer**; the Anthropic writer records one row
  per call at `OutputTokens` with the token split in `detail`. Either use it or
  drop it. `CostCategory::isSpendOnAssets()` has no caller either, and with an
  `Evaluation` category in the enum its answer is now also ambiguous.
- **`providers.whisperx.compute_type`** is documented as "the script passes it
  through" and the PHP side never sends it.
- **The extraction repair loop re-asks the same question** rather than scoping
  the retry to the offending field. Designed in detail in
  [CLAUDE.md](CLAUDE.md), deliberately not built yet — building it on top of an
  untested rejection-block change would stack a second untested mechanism on the
  first.

## Out of scope

Not built, and no scaffolding for them until asked: YouTube upload API,
multi-user accounts or tenancy, video-generation models (Ken Burns on stills is
the format, and ~100× cheaper), A/B thumbnail testing, analytics dashboards.

Thumbnail *composition* used to be on this list and was brought in deliberately
— see [Thumbnails are composed, never generated](#thumbnails-are-composed-never-generated).
Composition is not generation: it crops frames the story already owns, and it
costs nothing.
