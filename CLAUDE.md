# Narra — AI Story Video Pipeline

> Working name. Internal/product-facing only — the YouTube audience never sees it.
> Replace with a find-and-replace once the final name is locked.

## What this is

A Laravel web app that turns a written story into a finished, narrated, subtitled
long-form YouTube video built from still illustrations with slow camera motion,
plus the YouTube metadata package needed to publish it.

**Not** a fully autonomous content farm. The operator makes real editorial decisions
at four fixed gates. Everything between the gates is automated.

---

## Non-negotiables

1. **Four human gates.** Never build a "generate and upload" button.
   - **Gate 1 — Outline.** Operator writes or edits the premise and approves the
     act-by-act outline.
   - **Gate 2 — Scenes.** Operator reviews every scene's narration + image prompt,
     edits, reorders, deletes, or regenerates before any paid asset is created.
   - **Gate 3 — Preview.** Operator watches the render.
   - **Gate 4 — Metadata.** Operator picks the title, edits the description, and
     approves tags and chapters.
2. **No auto-publish.** The app produces a file and a metadata sheet. The human
   uploads and toggles YouTube's "altered or synthetic content" disclosure manually.
3. **Spending is not a gate crossing.** Approving a gate is a quality decision;
   dispatching paid work is a money decision, and they get separate buttons. A gate
   crossing can't be re-crossed, so folding dispatch into approval would mean
   reopening a gate just to retry a handful of failed scenes — which risks
   regenerating everything else. The spend button must be re-runnable without
   touching gate state.
4. **Cost is logged per video.** Every paid API call writes a row. If we can't answer
   "what did this video cost" in one query, the feature is incomplete.
5. **Nothing is regenerated silently.** Re-running a stage must be an explicit action,
   because re-running costs money.

---

## Format: 30–40 minute long-form

This is the defining constraint of the project. It is not a cosmetic setting — it
changes the architecture in several places, listed below.

**Why this length**
- Watch time drives revenue in this niche far more than upload count.
- Videos over 8 minutes are eligible for mid-roll ads, and a 30–40 minute runtime
  supports several ad slots rather than one.

**The floor is a preference; the threshold is the law.** 30 minutes is a target
chosen for ad density, not a constraint anything enforces — the only hard line is
YouTube's 8-minute mid-roll eligibility, and the reference channels in this niche
run 44 and 54 minutes, so the upper end was never binding either. Nothing in the
code refuses a render for being short: `PreviewGate` reports `in_target_window`
at Gate 3 and leaves the decision to the operator, which is correct and should
stay that way.

This was decided against a live case rather than in the abstract. Story 9 came
back at 29:39 — 21 seconds under the floor — because its script was sized at 160
wpm against a narrator who reads 197. Re-narrating all 186 scenes at speed 0.9 to
recover those four minutes would have cost 15,303 credits, the entire remaining
monthly allowance, leaving nothing to retry a single failed scene with. It ships
at 29:39.

**Never move a target to match a result.** The tempting version of that fix was
to lower story 9's `target_duration_min` to 29 so Gate 3 reads green. That is the
false-success pattern in its purest form — adjusting the measurement until the
outcome passes — and it is why the story keeps its 30-minute target and simply
reports as under it. The band moved for FUTURE stories, in config, because the
wpm figure it was derived from was wrong; story 9's record stays honest.

The real fix is upstream: size the next script at the measured 197 wpm rather
than the assumed 160, which is 7 acts instead of 6 for the same runtime. A
correct word target costs nothing; re-narrating to correct a wrong one costs a
month of credits.

**What it costs**
- Roughly 5,900–6,400 words of narration.

**Runtime is the product; the word band is derived from it.** Narration over stills
reads at ~150–160 wpm, not the ~185 wpm that a 30–40 min / 5,500–8,000 word pairing
implies — those two targets contradict each other. Keep **one** config constant
(160 wpm) shared by the word target, the runtime estimate, and the TTS fake, so they
cannot drift. If they ever must diverge, move the word band, not the runtime.
- Roughly 150–250 stills. This is the dominant line item — assume image generation
  is ~70% of per-video cost.
- Render time of tens of minutes. Plan for it; do not treat a render as a request.

**Structural consequences — build for these from the start**

1. **Scripts are generated in chunks, never in one call.** A single API call cannot
   hold 7,000 words of coherent narrative. The pipeline is:
   `premise → act outline (5–8 acts) → per-act script generation, each call receiving
   the outline plus a running summary of prior acts`.
   A one-shot script generator will produce drift, repetition, and contradictions,
   and will be the first thing that has to be rewritten. Do not build one.

2. **Structure is acts, not a flat scene list.** An `acts` table sits between
   `stories` and `scenes`. Acts map directly to YouTube chapters, so this also
   solves metadata.

3. **Re-hooks at act boundaries.** The 15-second opening hook is not enough at this
   length. Each act opens with a line engineered to carry the viewer forward.
   `acts.is_rehook_written` tracks it; Gate 1 review surfaces it.

4. **Narration is generated per scene, never as one 40-minute file.** One giant TTS
   call means one bad sentence forces a full re-bill. Per-scene audio is
   re-generatable in isolation and concatenated at mux time.

5. **Transcription is chunked.** Whisper on a 40-minute file is slow and drifts on
   word timestamps toward the end. Transcribe per scene, then offset each scene's
   word timings by the cumulative duration of preceding scenes.

6. **Scene clip rendering fans out.** 200 sequential 10-second renders is an
   overnight job. Parallelize across workers, bounded by CPU count.

**Single story or multi-story?**
Both are supported and it is an operator choice per video, stored on
`stories.format`:
- `single` — one continuous narrative across all acts.
- `anthology` — 3–5 self-contained stories, each an act. Easier to write, lower
  coherence risk, and chapter titles become natural hooks. Recommended for the
  first several videos while the pipeline is being proven.

---

## Target audience: United States

A US-audience channel operated from the Philippines. This affects real technical
decisions, so it lives in the spec rather than in someone's head.

**Script generation**
- Stories must read as American. US settings, US names, US school system
  (high school, senior year, prom, college dorms), US holidays, US institutions.
- Imperial units. USD. American spelling.
- No Filipino idiom leakage. `"Ay naku"`, `"po/opo"`, `"barangay"`, `"jeepney"`,
  `"sari-sari store"` and similar must never appear. Run a denylist check on every
  generated act and fail the job loudly rather than passing it to Gate 1.
- The prompt template stores a `locale_profile` field so this is data, not
  hardcoded prose.

**Voice**
- American English TTS voices only. Neutral-to-warm narration.
- Store `voice_id` per story so a channel keeps one consistent narrator.

**Scheduling**
- Peak US viewing is roughly 6–10 PM Eastern. Manila is UTC+8; US Eastern is
  UTC−5 (−4 in daylight time). That window lands early morning Manila time.
- Do not plan to upload manually at that hour. Render on your schedule, then use
  YouTube's native scheduled publish. Store `target_publish_at` in UTC and display
  it in both PHT and ET so nothing gets fumbled.

**Localization (highest-leverage later feature)**
- One render, multiple audio tracks. Translated metadata and dubbed tracks expand
  reach without touching the inauthentic-content line.
- Schema supports N audio tracks per story from day one, even though Phase 0 only
  ever writes one.

---

## YouTube metadata module

The app produces a complete, copy-pasteable publish sheet. This is a first-class
feature, not an afterthought.

**Sequencing constraint:** chapters require real timestamps, which only exist after
the render. Metadata generation therefore runs *after* `rendered`, not alongside
script generation. Title and thumbnail text could run earlier, but keeping the whole
package in one stage keeps the operator flow simple.

### What it generates

**Titles** — 5 variants, operator picks one at Gate 4.
- Hard limit 100 characters; target 60–70 so nothing truncates in search or on mobile.
- Generated with the emotional hook front-loaded, since the left portion is what
  survives truncation everywhere.
- Store all 5 plus the pick. Over time this becomes data on what actually performs.

**Description**
- Opening 2–3 sentences are the real payload — they show in search and above the
  fold. Written as a hook, not a summary.
- Chapter timestamp list, auto-built from act durations.
- A fixed footer block, configurable per channel: AI disclosure line, any standard
  links.
- Limit 5,000 characters.

**Chapters**
- Derived from `acts` — one chapter per act, using the act title.
- YouTube's rules, enforced in code before output:
  - First chapter must be `00:00`.
  - Minimum 3 chapters.
  - Each chapter minimum 10 seconds.
  - Ascending order.
- A 30–40 minute video with no chapters is leaving retention on the table. This
  is not optional output.

**Tags**
- 500-character total budget across all tags — enforce it, do not silently truncate.
- Be aware tags carry far less ranking weight than title, thumbnail, and the first
  lines of the description. Generate them, do not optimize the roadmap around them.

**Thumbnail text**
- 3–5 short overlay phrases, 3–5 words each. Must be readable at small size.
- The app does not generate thumbnail images in this phase. It outputs the text
  and the recommended still (operator flags a scene as `thumbnail_candidate` at
  Gate 2).

**Publish checklist** — rendered as a checklist at Gate 4, not prose:
- Altered or synthetic content disclosure toggled
- "Not made for kids" audience setting confirmed
- Category set
- Video language and caption language set
- Scheduled publish time confirmed in ET
- Pinned comment drafted

### Schema

**youtube_metadata**
```
id, story_id,
title_options (json, 5 strings), title_selected,
description (longtext),
tags (json), tags_char_count (int),
thumbnail_text_options (json),
thumbnail_scene_id (nullable fk),
pinned_comment (text),
checklist_state (json),
status, created_at, updated_at
```

Chapters are derived from `acts`, not stored twice.

---

## Stack

| Layer | Choice |
|---|---|
| Framework | Laravel 12, PHP 8.3+ |
| DB | MySQL 8 |
| Queue | Redis + plain `queue:work` (**no Horizon — see Windows constraints**) |
| Frontend | Blade + Livewire (server-driven; internal tool, not a SPA) |
| Media | FFmpeg 6+ (libx264, libass, zoompan) |
| Storage | Local disk in Phase 0. S3-compatible later — code against `Storage::disk()`. |

**Deliberately deferred:** all AI APIs. See Phase plan.

---

## System requirements

**Development platform: Windows.** This is a deliberate choice, not an accident.
See "Windows constraints" below — several sections of this spec are shaped by it and
must not be "corrected" back to a Linux assumption.

```bash
php -v                      # 8.3+
composer -V
mysql --version             # 8.0+
redis-server --version
node -v                     # 20+ (Vite only)
ffmpeg -version             # 6.0+
nproc                       # worker sizing for parallel scene renders
```

FFmpeg must have these. Verify before writing a single line of render code:

```bash
ffmpeg -version | grep -o 'enable-libass'      # burned-in subtitles
ffmpeg -filters | grep zoompan                 # Ken Burns motion
ffmpeg -filters | grep -w concat
ffmpeg -encoders | grep libx264
```

If `libass` is missing the whole subtitle approach collapses — fix that first.

**Disk:** budget ~3 GB of scratch per in-flight video. Measured at 1.4 GB peak for a
260-scene / 58-minute run with fixture stills; real illustrations will be larger, so
the headroom is deliberate. Scratch is purged on successful render — and purge must
refuse to run unless `final.mp4` exists *and* decodes, since existence alone is not
success. Keep the final MP4, the `.ass`, and the scene manifest.

**Server:** rendering is CPU-bound and this format is long. Never run renders on the
process serving HTTP.

---

## Windows constraints

The dev machine runs Windows. `pcntl` and `posix` do not exist in Windows PHP — not
missing, not installable, absent by design. Everything below follows from that.

### No Horizon

Horizon hard-requires `pcntl` and `posix`. Do not install it. Do not add it to
`composer.json`. Do not reference `horizon:work` anywhere.

The queue layer is plain `queue:work`, one process per queue:

```
php artisan queue:work redis --queue=render --tries=1  --max-time=21600
php artisan queue:work redis --queue=assets --tries=3  --max-time=3600
php artisan queue:work redis --queue=text   --tries=3  --max-time=3600
```

`render` runs 1–2 processes. `assets` and `text` can run more.

### `--timeout` does not work — this is the important one

Laravel enforces `--timeout` using a `pcntl` alarm. Without `pcntl`, **the flag is
silently ineffective**. A hung FFmpeg call or a stalled HTTP request will occupy a
worker indefinitely with no error and no recovery.

Mitigations, all required:

- Set an explicit timeout on **every** `Process` call in the FFmpeg wrapper. Symfony
  Process enforces its own timeout in userland and does not need `pcntl`. This is the
  real protection; the queue-level timeout is not coming to help.
- Set explicit timeouts on every HTTP client call in Phase 2 providers.
- Use `--max-time` so workers recycle on a schedule regardless.
- Write a heartbeat: long-running jobs update `render_jobs.updated_at` periodically.
  The operator UI must surface a stale heartbeat.

**Two distinct failure modes — neither mechanism covers the other:**

| Failure | Caught by | Why the other misses it |
|---|---|---|
| Worker process dies | Stale heartbeat | Process timeout dies with the worker |
| FFmpeg hangs, worker alive | Symfony Process timeout | Heartbeat keeps advancing — correctly, the worker *is* alive |

Both are required. Make the stale threshold and the Process timeouts env-tunable: an
alarm nobody can rehearse is not an alarm.

**Pre-flight every input still with `ffprobe` before invoking FFmpeg.** `-loop 1` does
not fail on a corrupt image — it loops forever emitting no frames and never exits, so
the job sits at `running` until the Process timeout. Three bad stills in a 200-scene
batch would stall a render for most of an hour before reporting anything. Note that
`ffprobe` **exits 0** on such a file and reports a stream of 0x0, so the check must be
on the dimensions, not the exit code.

**Treat an unreadable output artifact as absent.** A half-written clip left by a killed
job will otherwise fail the idempotency probe forever, and every retry dies on the
wreckage of the last.

### Batches still work; the dashboard does not

`Bus::batch()` is core Laravel and needs no Horizon. The 200-scene fan-out stages use
it exactly as specified.

What is lost is Horizon's batch UI. **Build a minimal replacement in Phase 1** — a
single page reading the `job_batches` table plus `render_jobs`, showing per-stage
progress, failed job count, and which scenes failed. At 200 scenes this is not a
nice-to-have; without it a partial failure is invisible.

Run `php artisan queue:batches-table` and `queue:failed-table` during setup.

### Worker supervision

No Supervisor on Windows. Use **NSSM** to register each `queue:work` command as a
Windows service so workers restart on crash and survive reboot. Task Scheduler is a
weaker fallback. Do not rely on manually-opened terminal windows.

`php artisan queue:restart` works normally — it uses a cache flag, not signals.

### FFmpeg filter-graph path escaping

Separate from argument escaping and genuinely platform-specific. Inside a filter
string, a Windows drive-letter colon must be escaped:

```
ass=E\\:/path/to/subs.ass        # Windows
ass=/path/to/subs.ass            # Linux
```

The same applies to paths inside `clips.txt` for the concat demuxer. Use forward
slashes throughout.

Put this in **one** method on the FFmpeg wrapper — `escapeFilterPath()` — with a
platform check. One place, tested both ways, so a future move to Linux is a
one-method change rather than a hunt.

### Path length

Windows has a 260-character path limit unless long paths are enabled. With 200 scene
files under nested storage directories, keep the project path short (`E:\narra`, not
a deep folder) and keep generated filenames short and slugged.

### Deployment note

Production will be Linux. Everything above is a dev-environment accommodation, so
keep platform-specific code confined to the two places named here — the Process-based
wrapper and `escapeFilterPath()`. Do not let Windows assumptions spread into Actions,
jobs, or models.

---

## Phase plan

### Phase 0 — Render pipeline (current phase, no APIs, no cost)

Prove the hardest and most fragile part first, with dummy assets.

**Deliverable:** a CLI command that takes a folder of numbered PNGs, matching MP3s,
and a JSON of word-level timings, and produces a finished MP4 with motion and
burned-in animated subtitles.

```bash
php artisan render:test storage/app/fixtures/sample-story
```

Fixtures live in `storage/app/fixtures/sample-story/`:
```
scene-001.png  scene-001.mp3
scene-002.png  scene-002.mp3
...
timings.json          # word-level, hand-written for the fixture
acts.json             # act boundaries, for chapter generation
```

Use ~12 fixture scenes, not 200 — enough to exercise concat, offsets, and act
boundaries without a slow feedback loop. But **run the full-length render at least
once in Phase 0** with duplicated fixtures padded to 35 minutes. Long renders fail
in ways short ones do not: disk exhaustion, worker timeouts, audio/video drift.
Find that now, not in Phase 2 with paid assets.

Nothing in Phase 0 touches the network. Nothing costs money.

### Phase 1 — Schema, gates, and the review UI
Full DB, queue workers, the four-gate operator flow, and the minimal batch-progress
page that replaces Horizon's dashboard — still driven by fixture assets.
Metadata module built here too, using fixture act data. It is pure text formatting
and needs no AI to prove out.

### Phase 2 — AI integration
Chunked script generation, TTS, image generation, transcription, metadata copy.
One provider at a time, each behind an interface, each with a fake implementation
used in tests.

### Phase 3 — Multi-language audio tracks

---

## Schema

Written for Phase 1, but Phase 0 code should not contradict it.

**stories**
```
id, title, premise, format (enum: single, anthology),
locale_profile (default 'en-US'), voice_id,
target_duration_min (default 30), target_duration_max (default 40),
status, target_publish_at (UTC, nullable),
total_cost_usd (decimal, denormalized), created_at, updated_at
```

`status`: `draft` → `outlined` → `scripted` → `scenes_drafted` → `scenes_approved` →
`assets_generating` → `assets_ready` → `rendering` → `rendered` → `metadata_ready` →
`published`

Gate 1 sits on `outlined` → `scripted`.
Gate 2 sits on `scenes_drafted` → `scenes_approved`. **No paid asset generation may
begin before this transition.**
Gate 3 sits on `rendered`.
Gate 4 sits on `metadata_ready` → `published`.

**acts**
```
id, story_id, sequence (int), title, summary,
script (longtext), is_rehook_written (bool),
start_ms (int, nullable — filled after render), duration_ms (int, nullable)
```
`title` doubles as the YouTube chapter title — write it to work as both.

**characters**
```
id, story_id, name, description,
seed (int, nullable), reference_image_path, style_notes
```
Character consistency across 150–250 stills is the single biggest quality risk, and
it gets harder as the video gets longer. A locked seed plus a stored reference image
per character is the mechanism.

**scenes**
```
id, story_id, act_id, sequence (int), is_hook (bool),
is_thumbnail_candidate (bool),
narration_text, image_prompt,
image_path, duration_ms,
motion_preset (enum: zoom_in, zoom_out, pan_left, pan_right, static),
status, created_at, updated_at
```

**audio_tracks**
```
id, story_id, language (default 'en-US'), voice_id,
audio_path, timings_json (longtext, word-level), duration_ms, status
```
One row in Phase 0. The table exists now so Phase 3 is not a migration nightmare.

**scene_audio**
```
id, scene_id, audio_track_id, audio_path,
timings_json, duration_ms, padded_duration_ms,
frames, offset_frames, offset_samples, offset_ms, status
```
Per-scene narration. `duration_ms` is the raw audio; `padded_duration_ms` is
`frames / fps` after padding.

`offset_frames` and `offset_samples` are the **authoritative** cumulative start
positions, accumulated as integers. `offset_ms` is derived from `offset_frames` for
display only — never use it for timing. See the frame-count rule in the render
pipeline section.

**render_jobs**
```
id, story_id, stage, status, started_at, finished_at,
output_path, log (longtext), error (text, nullable)
```

**cost_entries**
```
id, story_id, provider, operation, quantity, unit, usd_cost, created_at
```
Every paid call writes one row. No exceptions.

---

## Render pipeline

Three steps. Each is independently testable and independently re-runnable.

### 1. Scene clip — still image plus Ken Burns motion

`zoompan` is jittery when applied directly at output resolution. The fix is to
upscale the source, zoom on the large version, then downscale. This is the single
most important detail in the render pipeline.

```bash
ffmpeg -loop 1 -i scene-001.png \
  -filter_complex "\
    scale=3840:-2,\
    zoompan=z='min(zoom+0.0004,1.20)':\
            d=DURATION_FRAMES:\
            x='iw/2-(iw/zoom/2)':\
            y='ih/2-(ih/zoom/2)':\
            s=1920x1080:fps=30,\
    format=yuv420p" \
  -frames:v DURATION_FRAMES -c:v libx264 -preset medium -crf 20 \
  scene-001.mp4
```

### Frame count is authoritative — read this before touching timing

**Use `-frames:v`, never `-t`.** They disagree: `-t` yields `round(seconds × fps)`
while `zoompan`'s `d=` takes the frame count directly. When they disagree, `-t` wins
and the emitted clip does not match `d=`, so the zoom never completes and the frame
count is unpredictable. `-frames:v` makes output frames exactly equal `d=` by
construction.

**The rule:**

```
frames        = ceil(audio_ms / 1000 * fps)     # ceil, deliberately
clip_duration = frames / fps                     # exact, by construction
```

`ceil` is correct **because** the audio is padded to match (below). It guarantees
`video >= audio` for every scene, so padding only ever adds silence. `round` would
sometimes make video shorter than audio, forcing a trim that can clip the tail of the
last word. Do not "optimize" this to `round`.

**Audio is padded to the video, not the other way around.** At mux time, each scene's
audio is padded with silence to exactly `frames / fps`. Maximum padding is one frame
(~33 ms at 30fps), average ~16 ms — inaudible, and distributed across scenes rather
than pooled.

**Consequences that must hold everywhere:**

- `scenes.duration_ms` stores the **audio** duration. The clip duration is derived,
  never stored twice.
- **Offsets accumulate in integer frames and samples, never in rounded milliseconds.**
  Summing `padded_duration_ms` compounds rounding error scene by scene. Store
  `offset_frames` (video) and `offset_samples` (audio) as the authoritative values;
  `offset_ms` is derived for display only and must never be used for timing.
  Step 3's subtitle shift reads `offset_samples`, not `offset_ms`.
- **Audio padding and concat happen in PCM, never MP3.** A `-c copy` concat of padded
  MP3s accumulates per-file encoder delay — measured at +458 ms over 12 scenes,
  extrapolating to ~7.6 s at 200. Pad and concatenate as PCM (WAV), then encode once
  at mux time. Mux reads the WAV, not an MP3.
- The concat duration assertion is **exact and integer**, not a tolerance. Cross-
  multiply rather than comparing floats:
  `audio_samples * fps == video_frames * sample_rate`.
  If these differ at all, something is wrong — fail loudly. **This is the assertion
  that prevents drift**, and it runs on PCM where exactness is achievable.
- **The mux assertion is different, and deliberately weaker on audio.** AAC encodes in
  1024-sample frames with a priming delay, so a muxed AAC track cannot carry an
  arbitrary sample count exactly — measured losses of 0, 15, or 29 samples depending
  on how the total divides. Assert the **video** frame count exactly (hard fail), and
  bound the audio to within one AAC frame while reporting the exact delta. This is a
  single terminal boundary artifact, not accumulation.
- **Ship AAC, not PCM.** 192k AAC mono is transparent for narration, and YouTube
  re-encodes on ingest regardless, so a PCM master buys no audible quality while
  doubling upload size (~570 MB vs ~275 MB per video). Keep the codec behind a config
  flag in case a PCM master is ever wanted.
- **Comparisons must happen in one resolution.** ASS is centisecond-resolution and
  cannot represent every frame boundary — `105365/30 = 351216.667 cs`. Comparing a
  centisecond timeline against a millisecond-rounded duration is a category error that
  can differ by up to 5 ms while everything is correct. Assert
  `timeline_end_cs == round(frames / fps * 100)` and report the sub-frame residual.
- **Verify from container metadata, not by decoding.** Fully decoding a finished
  58-minute file to count frames and samples took 404 s — a third of the mux stage,
  and it would run on every render. Read the declared stream lengths instead
  (`presentedSampleCount`, container `nb_frames`); make full decode an opt-in deep
  check, not the default.
- Without padding, `ceil` accumulates ~+3.3 s of drift over 200 scenes and `round`
  ~+1.1 s. Both fail the assertion. Padding makes drift structurally zero.

A one-frame error per scene is invisible on a single clip and becomes seconds of
desync by minute 35 — compute this, never guess it.

Source stills should be generated at 1920x1080 or larger so the 2x upscale is not
inventing detail.

At this scale, use `-preset medium` for scene clips. `slow` doubles render time for
marginal gain across 200 clips.

### 2. Concat

Concat demuxer, not the filter — all clips share identical codec parameters, so this
is a stream copy and effectively instant regardless of length.

```bash
ffmpeg -f concat -safe 0 -i clips.txt -c copy silent.mp4
```

Audio is **not** concatenated this way. Pad and concat per-scene audio as PCM into a
single WAV, then encode once at mux. Stream-copying MP3s accumulates encoder delay —
see the frame-count rule above.

Assert audio and video durations match exactly, by integer cross-multiplication. A
silent mismatch here is the classic long-form failure.

**`clips.txt` paths do not use `escapeFilterPath()`.** The concat demuxer's list file
follows different escaping rules from a filter graph — an escaped drive colon fails
there. Separate method, separate test asserting the two differ.

### 3. Audio mux and burned-in subtitles

```bash
ffmpeg -i silent.mp4 -i narration.wav \
  -vf "ass=subs.ass" \
  -c:v libx264 -preset medium -crf 20 \
  -c:a aac -b:a 192k -shortest \
  final.mp4
```

This step re-encodes the full video and is the longest single operation in the
pipeline. Give the job a multi-hour timeout.

Subtitles are burned in, not soft. The animated word-by-word highlight style is the
format's visual signature and cannot survive as a soft track.

### Subtitle format

**ASS, not SRT.** SRT cannot do per-word highlighting.

The effect is ASS karaoke timing: `{\k}` tags carry centisecond durations per word.
The app generates the `.ass` file from per-scene timings, offset into whole-video time.

- Word-level timings are required. Sentence-level timings cannot produce this effect.
- In Phase 2 these come from Whisper with word timestamps enabled, per scene. In
  Phase 0 they are hand-written in the fixture.
- A 35-minute video is roughly 6,000 karaoke-timed words. Generate the `.ass` file
  with a streaming writer, not by concatenating strings in memory.
- Store the style block (font, outline width, primary and highlight colors) as a
  configurable preset — it is the channel's visual identity and will be tuned often.

---

## Job pipeline

Each stage is a queued job, dispatched in a chain, each writing to `render_jobs`.

```
GenerateOutline           → gate 1   [free — text only]
GenerateActScripts        (sequential, each fed prior summaries)
DraftScenes               → gate 2   [free — text only, no paid assets yet]
────────────────────────────────────  operator approval required
GenerateImages            (fan out, one job per scene)
GenerateSceneNarration    (fan out, one job per scene)
TranscribeSceneTimings    (fan out, one job per scene)
RenderSceneClips          (fan out, one job per scene)
ConcatClips
MuxAndSubtitle            → gate 3
PurgeRenderScratch                   [chained after the mux, so a failed render
                                      never reaches it — scratch is what a
                                      re-run reuses]
GenerateMetadata                     [needs act timestamps from the render;
                                      runs on the `text` queue]
                          → gate 4
```

**Rules**
- Every job is idempotent. Re-running a completed job must not duplicate work or
  re-bill an API.
- `GenerateActScripts` is the one stage that must stay sequential — each act needs
  the summary of the acts before it.
- Fan-out stages use a batch with a completion callback, not a sleep-and-poll loop.
  At 200 scenes, a poll loop will hold a worker hostage for hours.
- Three queues run as separate `queue:work` processes: `render` (1–2 workers),
  `assets` (higher, for image and TTS calls), `text` (higher). A 40-minute mux must
  never block a script draft.
- Timeouts: enforce them in Symfony Process, not via `--timeout`. See
  "Windows constraints" — the queue-level flag does nothing on this platform.
- Batch failure policy: if 3 scenes out of 200 fail image generation, the batch
  should complete and flag them for retry, not fail the whole video.

---

## Conventions

- Business logic in Action classes (`app/Actions/`), not controllers, not models.
- Every external provider sits behind an interface in `app/Contracts/` with a `Fake`
  implementation in `app/Services/Fake/`. Tests never hit the network.
- FFmpeg invocation goes through one wrapper class. Exactly one place in the codebase
  builds commands.
- **Never call `escapeshellarg()` or build shell strings by hand.** The wrapper passes
  an **array of arguments to Symfony Process** (already a Laravel dependency), which
  handles per-platform escaping internally. This makes the wrapper portable by
  construction.
  Rationale: Windows PHP's `escapeshellarg()` replaces `%` and `"` with spaces rather
  than escaping them — silent corruption, no error. Story titles and image prompts are
  exactly the strings carrying those characters. Array args sidestep the whole class
  of bug on every platform.
- Image prompts and story titles reach the filesystem — treat every one as hostile
  input. Sanitize to a slug for filenames; never pass raw user text as a path.
- FFmpeg *filter-graph* path escaping is separate from argument escaping and is
  platform-specific. It lives in exactly one method, `escapeFilterPath()`. See
  "Windows constraints".
- Money is `decimal(10,4)`, never float.
- Durations in the DB are integer milliseconds. Convert at the edges only.
- Migrations are never edited after being run. New change, new migration.

---

## Where bugs actually live

Every dead-code gap found so far sat at a **seam between phases** — a mechanism built
in one phase with its production caller due in the next, which then arrived without
wiring it. Nothing inside a phase was ever dead. The asset stage went missing this
way: three stages declared in the enum, three provider contracts, and
`ResolveSceneReferences` all existed with no caller, because the render pipeline was
being fed by the Phase 0 fixture importer the whole time.

Phase-local tests do not catch this. When finishing any phase, run one path that
crosses the seam end to end, and audit for declared-but-never-called stages,
contracts, enum cases, and queues before declaring the phase done.

### The audit, run properly once

The sixth instance — Gate 4's form with no generator behind it — prompted a full
sweep rather than another one-off fix: every Action, contract method, enum case,
config key, route, queued job and Livewire method checked for a producer or a
caller. It found five more. **Do this at the end of every phase, not when
something looks wrong**, because none of these ever looked wrong.

Closed since:

- `GenerateMetadata` — Gate 4's form had no producer. Six.
- The `text` queue — in config, in `docs/queue-workers.md`, in the NSSM
  instructions, and receiving nothing. An operator following the setup docs ran
  a worker that could never get a job. `GenerateMetadataJob` uses it.
- `PurgeRenderScratchJob` — existed, chained by nobody, so "scratch is purged on
  successful render" was false and ~700 MB survived every render. Now the last
  link of the render chain.
- `RenderStage::Outline`, `ActScripts`, `DraftScenes` — enumerated for a page
  that could never show them, because the text stages run synchronously and
  wrote no row. Act scripts dying on act 4 of 6, after billing three Opus calls,
  left `/renders/{slug}` looking like a story nobody had started. They now write
  rows through `RenderJob::record()`.
- `ScenesGate::assetGenerationRefusal()` — computed, rendered nowhere. The panel
  simply vanished when generation was unavailable, so the page said nothing
  where it should have said why.
- `stories.target_publish_at` — two timezone helpers and a display block on the
  index, and no input anywhere, so the column was null on every story and the
  block never rendered. Meanwhile the Gate 4 checklist asked the operator to
  confirm a scheduled publish time the app had no way to hold. **A checklist
  item about something that cannot exist is the same defect as a form with no
  producer** — the fix is to make the thing exist or to stop asking, never to
  leave the question there.

Still open, none blocking, all findable here rather than one gate at a time:

- **`SplitScene` has no production caller.** A full Action with a verbatim
  recombination guard and six tests, reachable from nothing: no console command,
  no button on the Gate 2 page beside edit/move/delete. `ScenesMerge` and
  `ScenesRecut` both got commands and this did not. The 70+-word single-sentence
  scene it exists to fix is currently unfixable through any interface.
- **`OperatorAction::ReopenScenesGate` is consulted by nobody.** Its own
  `callers()` names "ScenesGate::reopen() and its blade"; both call
  `$status->canReopenScenesGate()` directly instead. Same answer today — the
  case delegates to that method — which is exactly why it can drift silently.
- **`CostUnit::InputTokens` has no writer.** The Anthropic writer records one row
  per call at `OutputTokens` with the split in `detail`. Either use it or drop it.
- **`providers.whisperx.compute_type`** is documented as "the script passes it
  through" and the PHP side never sends it.

The same shape recurs in guards: a check that only tests the axis a component is
already strong on will always pass. The Haiku fallback checked that sentence ranges
tiled (counting — Haiku's strong axis) and missed that it chopped scenes too short.
When adding a guard, name the failure mode it is meant to catch and confirm it fires
against a real instance of that failure.

### False success is a defect class, not a run of bad luck

Five times now the app has reported success while something was silently wrong.
Note where the fifth one lives: not in the pipeline, but on the PAGE the operator
watches instead of the pipeline.

| # | What was reported | What was true |
|---|---|---|
| 1 | $8.12 of image spend in the ledger | A stand-in generated 186 flat fills; nobody was billed |
| 2 | 186 stills bought | 185 were placeholders from a fake provider |
| 3 | 186 scenes narrated | 117 read at speed 1.0 with NULL speed provenance |
| 4 | An asset run "complete" | 181 alignments had failed inside it |
| 5 | Subtitles and Mux "1/1 done", mux 506 s | Both stages were chained behind a concat that had just failed and never ran; the rows were 21 h old |

The individual bugs are all different and every fix for them was correct. The
constant is the reporting, and it has one mechanism behind it:

**Absence is read as agreement.** A NULL provenance column means "unknown", and
every check in this codebase correctly refuses to destroy an asset on unknown —
so unknown is preserved, and preserved reads as fine. A stage that never ran
leaves no failure row. A guard that is not in a worker's loaded code cannot fire,
and a check that cannot fire is indistinguishable from a check that passed.

Three rules follow, and they are worth more than any individual guard:

1. **A guard must be upstream of the thing it distrusts.** Every defence that
   failed above was downstream: a worker evaluating whether it was itself stale,
   a cost row asserting a spend was allowed after the spend. The dispatching
   process is the only one with fresh code by construction, so that is where
   staleness is decided. See `PreflightAssetDispatch`.

2. **Prefer arrangements where the bad outcome is unreachable over checks that it
   did not happen.** `assets:timings` cannot bill because it cannot construct a
   TTS job, which is a stronger claim than any assertion that it did not.

3. **Keep one number that we did not compute.** An external reading is the only
   one not derived from our own assumptions, so a disagreement between it and an
   internal figure is always worth chasing to the end. Chase it to the END,
   though: a 6,289-credit gap between the vendor usage page and this app's
   ledger was investigated across every product, model, voice and date range the
   vendor exposes, and the app's figure reconciled exactly while the gap could
   not be reproduced at all. An external number is a reason to look, not a
   verdict on its own.

And when a check cannot run, that is a failure, not a pass. An unreadable quota
is reported as unreadable and never as "fine" — the same rule, one level up.

---

## Out of scope

Do not build these until asked, and do not add scaffolding "for later":

- YouTube upload API integration (the app outputs a file and a metadata sheet)
- Thumbnail image composition
- Multi-user accounts, teams, billing
- Any SaaS/tenancy layer
- Video-generation models (Ken Burns on stills is the format, and it is ~100x cheaper)
- A/B thumbnail testing
- Analytics dashboards

---

## First task

Verify the FFmpeg feature checks above, then build Phase 0: the `render:test`
command and the three-step pipeline, driven entirely by hand-made fixtures.

Prove it on ~12 scenes for fast iteration, then run it once padded to full 35-minute
length before declaring the phase done.

Do not install a single AI SDK until a full-length fixture-driven MP4 plays correctly
end to end.
