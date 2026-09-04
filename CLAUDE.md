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

3a. **The arc has five movements and the reversal is a PHASE, not a scene.**
   This is the largest single correction the genre contract has taken, and it
   came from watching story 21 back rather than from any check failing. That
   story ran escalation → escalation → exposure → end, and the narrator held
   power for exactly one scene out of two hundred and seventy. Every structural
   check passed on it: seven acts, a beat each, a self-justifying antagonist, an
   exposure with eighty witnesses in it. It was still the wrong video.

   What the niche actually pays off on is:

   ```
   escalation → the narrator LEAVES → the antagonist SEARCHES →
   the narrator REFUSES → end
   ```

   The reference channel frames its own videos on the gap rather than on the
   grievance — *"never expecting to see me and our son 5 years later"* is a
   departure and a refusal, and no exposure at all. Three spine columns carry it:

   - **`departure`** — how and when the narrator goes, and whether they announce
     it. **They must not**, and this is the detail with the least margin in the
     whole spine: an announced departure cannot be searched for, so it does not
     weaken the reversal, it deletes it. Gate 1 flags one, with a negation window
     in front of every marker so that "leaves without telling them" is not read
     as the failure it is the opposite of.
   - **`reversal_beats`** — what the antagonist does to find them and what each
     attempt costs HER. The humiliation beats running the other way and
     escalating the same. Gate 1 flags a search that costs her nothing named, and
     one that reads as a single attempt rather than a phase.
   - **`refusal`** — what the narrator says when finally found, and which earlier
     moment it answers. `exposure_moment` is the public payoff; this is the
     private one, and it is the thing viewers wait forty minutes for. It only
     lands as an inversion, so the check is overlap: the refusal has to reuse the
     specific language of the grievance, the justification or an escalation beat.
     Gate 1 reports WHICH moment it matched, because "it answers something" is
     worth less than "it answers act 3".

   **The act count moved from six to seven for this**, and the acts are laid out
   across the phases by `ActPhase::planFor()`: escalation 1–4, departure 5, search
   6, refusal 7. Taking the room out of the escalation instead would have traded
   one missing phase for another. The departure is clamped to leave at least two
   acts behind it — a search with nowhere to run and a refusal in the same act as
   the leaving is the compressed ending the whole structure exists to replace.

   **Story 9 and story 21 are not regenerated.** Their outlines predate the phase
   and Gate 1 says exactly that, once, as a warning naming what is missing —
   rather than as three "missing field" problems on a shipped video. An outline
   somebody has started fixing by hand gets the ordinary per-field checks back.

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

**Audience and setting are different things, and only one of them is fixed.**
The audience is American and the narrator is American in every profile. Where
the story is SET is a per-story choice, `stories.locale_profile`, picked on the
new-story form and fixed from then on because the outline, the acts and the cast
are all generated against it. Two profiles exist and neither replaces the other
— they are meant to be run against comparable premises and compared:

- **`en-US`** — American setting, below.
- **`en-CN`** — Chinese setting, American English narration. The
  translated-Chinese-web-novel register a large part of this niche runs on:
  elders and in-laws with real authority over adult children, dowry and bride
  price, face and losing face, filial duty, the eldest son, the family banquet.
  Dialogue formal and direct — accusations stated outright rather than implied.
  Yuan, metric units. It also suits the anime style better than American
  suburbia does.

Adding it needed no code in `LocaleGuard`, which is what the profiles being data
was for. What it did need was a producer: `locale_profile` had no input anywhere,
so a second profile would have been a column value no story could hold.

**The two leaks are not the same leak, and only one moves with the setting.**
Filipino idiom is a leak because of where the OPERATOR sits; a British spelling
is a leak because of who the NARRATOR is. Neither changes when the story moves
to China, so both lists are shared by every profile rather than copied into each
— copies drift, and a newer profile that quietly caught less than the older one
would look identical from outside. The setting-specific half is the only part
that differs, and for `en-US` it turned out to be empty.

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
- Kept for the record and so a title and a picture can be checked against each
  other. The composed thumbnails carry **no text**: in this format the title is
  the hook, and words burned into the image compete with it at the size anyone
  actually sees.

**Thumbnail composition** — was out of scope, is not any more.

The app handed over overlay text and a recommended still and composed nothing,
so every video meant opening an image editor. That is the shape this file keeps
naming from the other side: the sheet describing the work rather than doing it.

- **The format is the channel's**: two stills side by side, cropped to portrait
  panels, faces prominent, no text overlay. 1280×720, under 2 MB — YouTube's
  numbers, and both asserted from the written file rather than from the
  arguments that produced it.
- **It composes from stills already owned and NEVER generates one.** That is the
  constraint the feature is built around rather than a saving: a 270-scene story
  has already paid for every frame it could want, and buying another one to crop
  in half would be spending money to avoid making a choice. There is no provider
  here, no contract and no fake, because there is no network call to fake — and
  a test asserts a composition run writes no `cost_entries` row.
- **3–4 candidates, picked at Gate 4 the way a title is.** The pick is copied to
  `RENDER_DELIVERY_PATH` as `<slug>.jpg`, beside `<slug>.mp4`. One folder, one
  name, both files an upload needs.
- **The ranking is a proxy for face size and says so.** Nothing in this stack can
  find a face in a JPEG — FFmpeg cannot, GD cannot, and buying a service that can
  would break the one rule the feature has. So `ThumbnailFraming` reasons from
  the two things the app knows exactly: who is recorded in the frame
  (`scene_character`), and how the frame was written (the frame sentence at the
  top of `image_prompt`, which is the text that PRODUCED the picture). A wide
  establishing shot makes a poor thumbnail regardless of how good the frame is,
  and story 21 has exactly that flagged as a candidate — scene 82, a chair in an
  empty room, nominated by the same model that wrote the scene.
  **It ranks; it does not decide.** The reasons are printed beside each
  composition and the operator is looking at the actual image, so a bad ranking
  is visibly a bad ranking rather than an unexplained order. Same split as every
  guard here: the check detects, the operator judges.
- **The pairing is the editorial part, and the reversal phase is what made it
  possible.** Two panels from the same character in the same act is one still cut
  in half. The pair score prefers the two ENDS of the arc — an escalation-phase
  still on the left, a search or refusal still on the right — which is how this
  niche's thumbnails actually read, and `acts.phase` is what can answer it. On a
  story outlined before the phase existed it falls back to opposite ends of the
  scene list, which is the same idea with less to go on. Earlier scene left,
  later right: a before-and-after reads the way the language does.
- **Widening is said out loud.** If the flagged pool cannot fill the
  compositions the search widens to every still, and the page says it widened.
  A silent widening would make the Gate 2 flags look respected when they were not.
- The size cap is walked, not assumed. `quality_ladder` steps the MJPEG quality
  down until the file fits and fails loudly if the last rung is still over. At
  1280×720 the first rung measures ~120 KB and the ladder will never be walked —
  but "will never" is a sentence this project has been wrong about before, and a
  limit nothing enforces is a limit in name only.

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
thumbnail_options (json), thumbnail_selected,
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

**The deliverable is also copied out.** `RENDER_DELIVERY_PATH` names a folder
outside the project and the finished file lands there as `<slug>.mp4` — one
findable file per story, rather than twenty files all called `final.mp4` in
twenty scratch directories. The chosen thumbnail lands beside it as `<slug>.jpg`
under the same rules: every refusal about a path this app does not control lives
in `App\Support\DeliveryFolder`, once, because two hand-maintained copies of one
guard is how they come to disagree. Budget for the second copy: it is ~530 MB per story
and it is deliberate. A move would break Gate 3's player, the purge guard and
re-render idempotency, all three of which read the workspace copy.

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
id, title, premise, cast_age_profile (nullable),
narrator_grievance, antagonist_justification,
withheld_information, exposure_moment,
departure, reversal_beats, refusal,
format (enum: single, anthology),
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
id, story_id, sequence (int),
phase (escalation | departure | search | refusal, nullable),
title, summary, escalation_beat,
script (longtext), is_rehook_written (bool),
start_ms (int, nullable — filled after render), duration_ms (int, nullable)
```
`title` doubles as the YouTube chapter title — write it to work as both.

`phase` is persisted rather than derived from `sequence`, because the act SCRIPT
generator branches on it. The previous prompt could only ask "is this the last
act" and answered every other act with "end worse off than it started" — right
for act 2, and the exact opposite of what act 6 of seven needs, where the ground
is being lost by the antagonist. Null on an anthology, where each act is a
self-contained story running the whole arc itself.

`escalation_beat` is what the act costs **and to whom**: the narrator before the
departure, the antagonist after it. One column, two directions, and `phase` is
what says which.

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
- **`duration_ms` is a lossy intermediate and must never be an input to frame
  or sample arithmetic.** It is the honest RAW AUDIO duration and the pace
  guard, the estimates and the operator pages all read it — but a millisecond
  cannot represent where the audio actually ends. Story 21 scene 201:
  ElevenLabs returns `pcm_24000` against a 44.1 kHz render, 360002 samples at
  24 kHz is 15.0000833 s, `duration_ms` stores 15000, and
  `ceil(15000/1000*30)` is exactly 450 frames — 661,500 samples, against audio
  needing 661,504. Four samples over, so `apad` became `atrim` and
  `PadSceneAudio` refused. **The guard was right; the frame count was wrong**,
  and the ceil() guarantee the whole pipeline rests on had been broken three
  steps upstream.
  Intermittent by construction, which is what makes it dangerous: ceil()
  normally leaves up to a frame of headroom and absorbs the loss. It only bites
  when `duration_ms * fps / 1000` lands exactly on an integer, which at 30 fps
  means a duration that is a multiple of 100 ms — about one scene in a hundred.
  Story 21 had one in 270. **Story 9 had none in 186 and shipped on luck rather
  than on correctness**, which is the part worth remembering: a defect this
  shape passes most runs.
  Compute frames from the sample count and the render rate, in integers:
  `App\Support\AudioFrames`. It also owns the source-to-render rate conversion
  and `samplesPerFrame`, because both had been written out separately in
  `PadSceneAudio`, `SceneTimeline` and the guard — three copies of one
  expression, which is three chances for one of them to be corrected alone.
  The millisecond path survives as `forMilliseconds()`, named rather than
  implied, for fixture stories that carry a duration and no samples.

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
DeliverFinalVideo                    [copies final.mp4 to RENDER_DELIVERY_PATH
                                      as <slug>.mp4. Last, and AFTER the purge:
                                      it is the only stage touching a path the
                                      app does not control, and a failure ahead
                                      of the purge would strand ~700 MB of
                                      scratch on a render that succeeded.
                                      Copy, never move — Gate 3's player, the
                                      purge guard and re-render idempotency all
                                      read the workspace copy]
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
- **The art style is one config constant and nothing else may mention a medium.**
  `scenes.art_style` is appended to every prompt by `ImagePromptBuilder`, and the
  script writer is explicitly told not to describe the style, medium, palette or
  rendering. Retuning the channel's look is therefore an edit to one value —
  audited and confirmed. Preview a candidate before adopting it with
  `style:preview <story> --style-file=…`, which overrides the constant for one
  process and never writes it back.
- **Idealised beauty is a property of the medium, so it is a style line.**
  This niche runs on the bishounen/bishoujo treatment, not on photoreal
  proportions in a cel-shaded medium — large expressive eyes with catchlights,
  clean symmetrical features, refined jawlines, glossy strand-rendered hair.
  Antagonists included, and that clause is load-bearing: a generator handed a
  character who is in the wrong will draw them plain or unkempt to say so, and a
  story that telegraphs its villain through their face has given away its own
  reveal. Never named through a real or fictional person — the treatment is
  described, so the look is reproducible from the words and a retune is an edit
  to a sentence.
  It has a price, paid in the same block: idealised faces converge, so hair
  carries MORE of the identification than before, not less. The trio frame in
  `style:preview` is what that is checked against.
- **The style constant describes how age is DRAWN; the story says who is in it.**
  Anime convention renders adults noticeably younger than a photograph does, so
  the first version of the age line — "adults are drawn at their true age,
  never softened toward youth" — answered an anime problem with a photographic
  rule, and the only thing the generator has for "old" is photoreal ageing
  texture. A woman written as late sixties came back at eighty-five with the
  wrinkles and liver spots drawn on. The line now shifts the baseline down about
  a decade and explicitly refuses to compress the range: relative age must stay
  legible and must agree with the narration, because a picture that argues with
  the narrator is worse than one drawn slightly young.
  The rest is casting, not rendering, and one string shared by every story
  cannot carry it. `stories.cast_age_profile` is optional operator text read by
  the extraction prompt — the one place a character's age is decided and frozen.
  It steers only ages the script leaves unstated; where the script states one,
  the script wins.
- **Retuning the style invalidates every reference sheet, and the app says so.**
  A sheet conditions every still its character appears in, so a face drawn in
  the old look pulls 30-90 stills back toward it. `character_references`
  carries a `style_fingerprint` written at generation; a mismatch is a REFUSAL
  at asset dispatch and a NULL is a warning that says "unknown", never "fine".
  See `StyleFingerprint` and `Character::referenceStyleState()`.
- **A per-character description outranks the style constant, so rules about a
  person live in the extraction prompt and in `CharacterTextGuard`.** The cast
  block is assembled AHEAD of the style block and is scoped to one name, and
  that ordering decided two arguments the style lost: `scenes.art_style` said
  "never by wrinkles, creases, liver spots, sagging" through two style previews
  and "deeply lined round face, soft sagging jawline" beat it both times. The
  rule now sits upstream — banned in the prompt and refused by the guard — which
  is the same "a guard must be upstream of the thing it distrusts" that
  `PreflightAssetDispatch` exists for. Hair length and clothing are per-character
  for the same reason and are not house style.
- **Build is no longer an identity or age axis.** Measured, not assumed: a
  character written "broad and thick through the chest" rendered lean, and one
  written "small and frail with rounded stooped shoulders" rendered upright —
  both under the current style, and again with an explicit style clause saying
  stated build is preserved exactly. The clause changed nothing. Idealised
  character art reshapes bodies toward one frame, and the style's own "men are
  tall and sharp-featured" line argues against a stated build directly. That
  line stays — attractiveness is the point of the current look — so build is
  what gives way: the extraction prompt now says DO NOT DESCRIBE BUILD, HEIGHT
  OR FRAME, and age asks for hairline, hair colour and face shape only. Words
  spent on build are worse than absent, because they read as coverage that is
  not there.
  The cost is measured too and is worth knowing: with build gone, a
  forty-year-old lead reads early twenties rather than late twenties, and two
  women thirty years apart in middle age are no longer cleanly orderable. The
  extremes still order correctly, which is what the prompt's group check asks
  for. If middle-age ordering ever matters to a plot, the fix is casting — see
  `stories.cast_age_profile` — not another rendering clause.

- **Two style fixtures, kept deliberately.** `style-preview-fixture` (story 18)
  holds the pre-retune cast and `style-preview-fixture-2` (story 20) the cast
  extracted after the hair, headwear, ageing-texture and build changes. Neither
  is a video and neither is ever re-extracted: they are the measuring stick, and
  a measuring stick that moves measures nothing. Point `style:preview` at both
  when changing the look — the pair is what separates "the style changed" from
  "the descriptions changed", which is a distinction two previews in a row
  could not make.
- **Character descriptions are written as silhouette, not texture.** In an anime
  style at mid-shot and wide-shot distance, "faint smile lines at the corners of
  her eyes" renders as nothing — so a cast built out of surface detail is
  identifiable in close-up and anonymous everywhere else. Hair SHAPE must differ
  across the cast rather than only colour and length, and age must live in
  hairline, face shape and build rather than in wrinkles. Enforced by prompt in
  `characterSystemPrompt()` and by `CharacterTextGuard` on both text fields.
- FFmpeg *filter-graph* path escaping is separate from argument escaping and is
  platform-specific. It lives in exactly one method, `escapeFilterPath()`. See
  "Windows constraints".
- **Evaluating the channel is not the cost of a video.** `CostCategory::Evaluation`
  covers `style:preview`, `images:bakeoff` and `narration:bakeoff`: real spend on
  real files, logged in full, ungated, and the one category kept out of
  `stories.total_cost_usd`. Before it existed all three borrowed a category whose
  gate they then had to work around — a style preview wants the earliest story
  that HAS a cast, and `reference` unlocks two statuses later, so a run generated
  an image, billed for it, and threw a gate violation while writing the row.
  Three docblocks promised a per-video total could exclude this spend "in one
  predicate" and supplied none; `countsTowardVideoCost()` is the predicate.
  Kept visible beside every total by `Story::evaluationSpend()` — logged and
  nowhere on screen is the same defect one level up.
- **The operator console has one stylesheet, and one rule for editing it: no
  refusal, warning or advisory may get quieter.** This project has been saved
  repeatedly by a message being loud, and a restyle is the easiest place in the
  world to lose that — nothing fails, no test goes red, and the page simply
  becomes calmer than the truth. Alerts carry an accent edge, a stronger tint
  and a shadow, and they are deliberately louder than the panels around them.
  Consecutive alerts of the same kind CLUSTER rather than being toned down:
  Gate 2 on a 168-scene story emits fourteen `style_notes` advisories in a row,
  and the fix for a column of identical amber boxes is to close the gaps, never
  to quieten any of them.

  **That rule is measured now, not just stated.** `tools/theme-audit.php`
  reports how far each loud surface separates from the ordinary panel beside
  it, in both themes, and every one of them is a token in the stylesheet so
  the tool reads what the CSS paints rather than carrying its own copy of a
  wash percentage. It caught the money panel at 1.07x on a white ground —
  visually the same surface as the panel above it, on the screen where an
  operator authorises spending, which is `.panel.money`'s original defect
  reintroduced by a theme.

- **Two themes, one set of names, and the raw values live exactly once.** Light
  and dark are a remapping (`--panel: var(--d-panel)`), not a second
  stylesheet. CSS cannot express one dark block answering both
  `[data-theme="dark"]` and `prefers-color-scheme`, so the REMAP is written
  twice — but it holds no literals, and the audit fails if the two copies stop
  being identical.

  **Every status colour has an `-ink` twin, and that split is load-bearing.**
  One token per status served as border, tint AND text while the ground was
  near-black: #f0c258 gold is legible on #171a22. On white it measures 1.8:1.
  A warning whose text silently fails contrast is the single worst thing a
  light theme could introduce here, so the hue and the ink are separate. In
  dark they are the same value and nothing changed.

  The dark theme is checked against its pre-redesign self declaration by
  declaration — `theme-audit.php --against=<baseline>` — because splitting one
  palette into two is exactly the kind of change that gets waved through for
  looking obviously safe, and one mistyped hex in two hundred token lines
  shifts a surface by an amount no reviewer catches and no test fails on.
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
- **The reference-sheet staleness check `ImagePromptBuilder` claimed to have.**
  Its docblock promised since Phase 2 that "if the channel's look is retuned,
  the sheets are stale and the operator is told so rather than the mismatch
  being absorbed silently". Nothing implemented it: no column, no comparison, no
  surface. Found with a live instance — story 9's 38 sheets are painted realism
  and the configured style is now anime. A documented guard is worse than a
  missing one, because it is read as covered.
- **`.panel.money` was written by three blades and defined by nothing.** The
  first instance of this defect found in the STYLESHEET rather than in PHP, and
  the audit had never looked there. Three surfaces write `class="panel money"` —
  the outline write button, the Gate 2 asset dispatch and the new-story estimate
  — and all three are the screen where an operator authorises spending. The rule
  did not exist, so all three rendered as an ordinary panel: the money screens
  looked exactly like the screen above them. Identical in shape to `.alert.err`,
  which had spent a phase rendering every refusal in the default border colour
  for the same reason. **A class the markup asks for and the stylesheet does not
  answer fails silently and looks deliberate**, which is worse than a missing
  method — a missing method throws. Found during the console restyle by
  extracting every class combination the views use and diffing it against the
  rules that exist; that diff is worth re-running when a phase ends.

  **That diff is now `tools/class-audit.php`** rather than a thing somebody
  remembers to do. Re-running it during the visual redesign found three more
  live instances immediately, which is the argument for making it a tool: it
  had been "worth re-running" for a phase and nobody had.

- **`.warnfill` was defined only inside a progress bar, and written on two
  surfaces that have none.** The largest of the three, and a pure instance of
  the false-success shape rather than a cosmetic one.

  `.bar .warnfill` is a fill inside a progress bar. Two places wrote the class
  standalone: the worker-health panel, marked whenever any queue is stale,
  absent or STRANDED, and a story row on the index carrying failed jobs or a
  silent heartbeat. Neither had ever rendered anything. A token-level grep says
  `warnfill` is defined — which is exactly why the audit reduces every rule to
  its SUBJECT compound and asks whether it can reach a given element, rather
  than asking whether the name appears somewhere in the file.

  The panel's explicit alerts still fired underneath it, so the stranded case
  was never invisible; the ambient "something on this page is wrong" was, and
  on the stories index that tint was the only marking a warning row got beyond
  a badge in one cell. Nothing about WHEN it fires changed — only whether
  looking at the page tells you it did.

- **`button.primary` could not match the two anchors that ask for it.** The
  "New story" call to action, on the empty state and above the table: both
  `<a class="primary">`, both rendered as plain blue text links. The front door
  of the app, styled as a footnote, on the page whose job is to say what to do
  next. The button look is element-agnostic now — a control that says it is
  primary is a primary control whatever tag it is made of. The audit reports
  this kind as TAG rather than UNDEFINED, because the rule exists and simply
  cannot reach.

- **`.gates .viewing` was undefined and the blade worked around it.** The gate
  stepper writes `viewing` on the tile for the page you are on, and carried an
  inline `style="border-color: var(--run)"` doing the job its own class was
  already asking for. The workaround is why it went unnoticed for a phase: the
  page looked right, so nobody asked whether the class did anything.

- **`StyleNotesGuard` checked one of the two fields it needed to.** `description`
  carried the identical defects in production the whole time: two leads whose
  hair was "usually" pulled back, and a man whose description said he *walks*
  with a stiffness in one hip — a gait, asking for him mid-stride in the frames
  where he is sitting down. The guard could not fire on the field beside the one
  it watched, and a check that cannot fire is indistinguishable from one that
  passed. Now `CharacterTextGuard`, over both fields.
- **The guard's object list did not contain the object that was in the data.**
  "…suspenders, and a wooden cane" named no carrying verb the guard knew and no
  listed noun, so it passed, and that man held a cane in all thirty-odd of his
  scenes. The list was also matched with `str_contains`, which forced hacks like
  `'mic '` and `'pen '` that then failed at a line end. Whole-word matching now,
  which is what makes it safe to list `stick` next to "lipstick".
- **The console audit — every stage that could only be started from a
  terminal.** Not a dead mechanism this time but a missing caller, which is the
  same seam from the other side. `story:write`, `story:scenes`,
  `render:dispatch`, `render:cancel` and `assets:timings` had no button, and
  story creation had no UI entry point at all — so the app built so an operator
  would not need a terminal required one to begin, and required one again at
  four more points. Each now has a button on the page its decision belongs to,
  reaching the same Action through the same `OperatorAction` predicate.
- **Cast extraction had no `render_jobs` row, so a failed cast was invisible.**
  `DraftSceneListJob::failed()` looked for a `draft_scenes` row to mark failed;
  `DraftScenes` opens that row itself and never got that far, because the job
  dies in `ExtractCharacters`, which runs first and recorded nothing. Three
  dispatches of story 21 died there and the operator's only surface said the
  story had stopped after its act scripts. `RenderStage::ExtractCast` exists
  now and the Action wraps itself in it, with a line per billed attempt — so
  the row says not just that it failed but that it failed twice and bought two
  calls doing it.
- **The retry prompt restated the guard's rules by hand, and the copies
  disagreed.** The guard refused `weathered`; the rejection note listed
  "wrinkles, deeply lined, sagging, liver spots" and did not name it. So an
  extraction rejected for a word was corrected with a note that never mentioned
  the word, re-asked, and produced it again — six billed calls across three
  dispatches, all refused for the same term. `CharacterTextGuard::ruleSummary()`
  generates the note from the lists it actually enforces. A hand-written summary
  of a machine-checked list is a second source of truth that only has to agree
  on the day it is written.
- **`assert()` named the rule and withheld the text.** `textProblems()` — the
  internal retry note nobody reads — carried both. An operator-facing failure
  strictly less informative than an internal one is backwards, and answering
  "on what text did it fire" cost a billed call that should have been a grep.
- **The style constant's ageing-texture rule had no enforcement.** It was
  correct, it was in the right file, and it lost to a description sitting in
  front of it — twice, visibly, in previews that were run to look at something
  else. A rule stated where it cannot win is the documented-guard shape again.
  `CharacterTextGuard::AGEING_TEXTURE` refuses it at extraction now, and the
  first real extraction after the rule went in tried to satisfy it by writing
  "faint smile lines absent" — a negation an image model does not honour, which
  the guard caught. The prompt is the request; the guard is the invariant.
- **`GenerateActScripts::localeWarnings()` was printed only by `story:write`.**
  Computed since Phase 2, surfaced on no page — so the one place a locale
  warning could be acted on was a terminal, on the app built so an operator
  would not need one. It matters more with two settings than it did with one:
  `en-CN`'s warn list is mostly imperial units, which is exactly what a model
  trained on American prose leaks without noticing. Now on the Gate 1 page,
  beside the setting it is judged against.
- `OperatorAction::DispatchRender` **named a caller that consulted nothing.**
  Its `callers()` said "PreviewGate::reject()"; that method compared
  `$status === Rendered` by hand. Same answer that day — which is exactly why it
  could rot. Worse, `reject()` moved the story to `rendering` and then printed
  the dispatch command: a status meaning "a clip batch is in flight" with no
  batch in flight, until somebody opened a terminal. It dispatches now, and a
  refused dispatch leaves the status alone.
- **The Gate 4 checklist asked questions it could not answer.** "Category set"
  is a tick box that cannot say WHICH category, so the answer lived in the
  operator's memory and a video was very nearly published as Gaming. Same for
  the video and caption languages, and for Shorts remixing — all three the same
  value on every upload, none of them written down anywhere. **An item the
  sheet cannot answer is unfalsifiable: you can only agree with it**, which is
  the `target_publish_at` defect one step along — that one asked about
  something that could not exist, this one asks about something that exists
  only in a person's head.
  Fixed the same way: make the thing exist. `youtube.channel` holds the
  channel's upload settings, each checklist item names the value it is asking
  about, and `PublishChecklist` resolves them onto the sheet and into the
  copy-paste block. The tick now means "I entered THIS". One place to change,
  and wrong in a way somebody can SEE rather than wrong in a way somebody has
  to remember.
  The warning about a tick with nothing behind it was hand-written for the
  scheduled time and was true of every per-story item the whole time it named
  one field — so a tick certifying a pinned comment that did not exist passed
  silently, next to a generator that had written one. It asks the question of
  all of them now, which is what makes the next per-story item covered by
  construction rather than by somebody remembering to add a second copy.
  A per-story item with nothing behind it renders "nothing to enter" and never
  a blank: a blank beside a tick box reads as "nothing needed here".

- **The escalation beat never reached the generator that asked for it.** Found
  while wiring `acts.phase` through the same path. `GenerateActScripts` built its
  `ActOutline` from `sequence`, `title` and `summary` only, so `escalationBeat`
  sat at its empty default — while `actPrompt()` printed `COSTS: %s` for every
  act in the context block and `What this act must cost the narrator: %s` for the
  one being written. Both rendered blank. A field required by the outline schema,
  checked by `ValidateOutlineSpine`, editable at Gate 1 and shown on the page had
  been invisible to the call it exists for since Phase 2. The prompt asked for
  something the caller never sent, which is the documented-guard shape with the
  arrow reversed: not a check that cannot fire, but a request that never arrives.
  The fake now records what it was handed, so the next dropped field fails a test
  instead of reading as a prompt that did not work.

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
- **The three Gate 2 surgery tools are still terminal-only.** `scenes:merge`,
  `scenes:recut` and `SplitScene` are the operations that fix a bad scene list,
  and the console does not offer any of them — so the one part of Gate 2 that
  still needs a terminal is the part that edits what Gate 2 is for. Lower
  priority than the stages the console closed, because a bad cut is recoverable
  by re-drafting and a missing dispatch button was not, but it is the same
  defect and it is now the largest remaining instance of it.
- **`characters:verify` and `story:fork` have no button either.** Both are free
  and neither blocks a video, which is the only reason they were left.
- **`OperatorAction::ReopenScenesGate` is consulted by nobody.** Its own
  `callers()` names "ScenesGate::reopen() and its blade"; both call
  `$status->canReopenScenesGate()` directly instead. Same answer today — the
  case delegates to that method — which is exactly why it can drift silently.
- **The extraction repair loop re-asks the same question, and should be scoped
  to the offending field. Designed, deliberately not built.**

  When `CharacterTextGuard` refuses a cast, `ExtractCharacters::extractWithRepair()`
  re-runs the whole extraction with a note appended. That is the same question
  louder, and story 21 showed it failing exactly that way: six billed calls
  across three dispatches, every one refused for `weathered`, and the word
  landed on a DIFFERENT character each time — Song Peiyuan in the failed runs,
  Lu Jianguo in the reproduction. The model is not fixated on a character; it
  reaches for the word for *some* older man, and a fresh sample of eleven
  descriptions gives it a fresh chance every time.

  Three things are wrong with the instrument, not the bound:

  1. It re-asks a prompt that has just been ignored, and takes a new sample.
  2. **The note describes a cast that no longer exists.** Attempt 2 generates
     eleven new descriptions, so "Lu Jianguo's description says weathered" is
     advice about a character attempt 2 may not even produce.
  3. It re-rolls ten good descriptions to fix one adjective, which can
     introduce new violations. The repair can make the cast worse.

  **The design.** Hand the model one field: the exact stored text, the exact
  offending term, and an instruction to rewrite that clause and nothing else.
  Splice the returned field back and re-run the guard on that field alone. A
  different question rather than the same one louder, a fraction of the cost,
  auditable as a one-field diff, and the descriptions that were already right
  are preserved.

  **Branch by violation type.** Every rule the guard currently holds is
  per-field, which is what makes a per-field repair safe *for these*. A
  cast-level check — two women sharing a hair silhouette, an age order that
  does not read — is a property of the group and would still need the whole-cast
  path. The loop should choose its instrument from the kind of violation rather
  than always reaching for the heavy one.

  **Never deterministically.** Stripping `weathered ` with a regex is the
  obvious shortcut and it is wrong: "nothing is regenerated silently" applies to
  text as much as to assets, and a machine-edited description that nobody knows
  was edited is worse than a refusal. The model rewrites the clause; the guard
  checks the result; the operator can see both.

  **Keep the hard refusal.** One scoped repair, then refuse. The existing bound
  is correct.

  **Why it is not built yet, which is the part most likely to be forgotten.**
  The patch that unblocked story 21 was widening the system prompt from
  "weathered skin" to the bare word — and attempt 1 never touches the rejection
  block at all, so the rejection-block half of that fix is UNTESTED. Building
  the scoped repair on top of it would stack a second untested mechanism on the
  first. It waits for a real failure to design against, which is the same reason
  every guard in this file names the instance it was written for.

- **The prompt bans build; nothing enforces it.** Found auditing the rejection
  block against the prompt's ban list. Every other rule in that list has a
  `CharacterTextGuard` category behind it, so a violation is refused and
  retried; `DO NOT DESCRIBE BUILD, HEIGHT OR FRAME` is a request with no
  mechanism, and a description carrying "stocky and broad" passes silently.
  Left open on purpose — a guard on `tall`, `slim` or `heavy` would be
  false-positive-prone in a way the other lists are not, and the cost of a miss
  is wasted words rather than a wrong picture. But it is the documented-guard
  shape and it should be named rather than assumed covered.
- **`assets:generate` prints a `--max-time` that is no longer the sized one.**
  Its closing "if nothing moves" hint still says `--max-time=3600`, which is the
  number that stalled story 21 and which `docs/queue-workers.md` now derives as
  32,400. A hand-written copy of a number that lives somewhere else, agreeing
  only on the day it was written — the same shape as the retry prompt that
  restated the guard's rules and disagreed with them. Found during a paid run
  and deferred for that reason twice now, since the fix is in `app/` and an edit
  there cancels an in-flight batch. Prefer generating the hint from config over
  retyping it a third time.

- **A story does not record the wpm its script was sized against.** Found
  immediately after locale-keying the pace profile. `expectedWpm()` is read live
  from config when the guard runs, so it answers "what do we believe now", while
  the guard needs "what was this script written to". The fix is a
  `stories.sized_against_wpm` column written at outline time and frozen, like
  `locale_profile` and for the same reason.
  Less urgent than it looked, now that en-CN is measured: the two locales are
  1.26% apart, so the gap between "what we believe now" and "what this script
  was sized to" is currently smaller than the pace tolerance by an order of
  magnitude. It becomes real the first time a profile moves by more than a few
  percent, and it should be built before that rather than after.

- **The dispatch preflight's notes do not reach the surface built for deciding
  to spend.** `assets:generate --estimate` prints the itemised bill and exits
  before the preflight runs, so the pace expectation, the aligner probe and the
  style fingerprint appear only on a real dispatch. Nothing is unsafe — they
  still print before anything is queued — but the page whose entire job is to
  inform a spending decision is the one that does not carry them.

- **`CostCategory::isSpendOnAssets()` has no caller.** Found while adding the
  fourth category. It is the only method on that enum nothing consults, and with
  `Evaluation` in the enum its answer is now also ambiguous — evaluation buys a
  file but is not asset spend in the sense the gate means. Use it or drop it.
- **`CostUnit::InputTokens` has no writer.** The Anthropic writer records one row
  per call at `OutputTokens` with the split in `detail`. Either use it or drop it.
- **`providers.whisperx.compute_type`** is documented as "the script passes it
  through" and the PHP side never sends it.

- **`ScriptWriter` is the only provider contract that does not extend
  `ProviderIdentity`.** Every other one does, so every other provider can be
  asked its own name and whether it is a stand-in; neither `ClaudeScriptWriter`
  nor `FakeScriptWriter` implements those methods, so calling them is a fatal
  error rather than a wrong answer.

  Found by the dashboard's provider panel, which needed exactly that question
  answered for the text role. It reports the resolved CLASS and says plainly
  that the instance cannot identify itself, rather than falling back to
  `config('providers.script_writer')` — that substitution is the $8.12 of
  phantom spend, and a summary page is the worst possible place to reintroduce
  it. Left open because widening a provider contract is a pipeline change and
  it was found during a visual redesign; the fix is three methods on two
  classes.

**A guard can be measuring correctly and still be certain about the wrong
thing.** `NarrationPace` compared story 21's en-CN narration against 197 wpm
measured on story 9's en-US script, found +14% on a 12% tolerance, and cancelled
a 270-scene batch at scene 2. Every number in it was right. The KEY was wrong:
reading rate is a property of a voice, AT A SPEED, READING A PARTICULAR KIND OF
PROSE, and the config modelled only the first two — so a measurement of one
setting silently answered a question about another. The fix is the key, not the
tolerance. Widening the tolerance would have been the false-success pattern
exactly: adjusting the measurement until the outcome passes.

**And then the measurement came in and said the key was not the cause.** Story
21's narration finished: en-CN is **199.49 wpm** across 270 scenes against
en-US's 197.00 across 186 — **1.26% apart**. The locale dimension is real and it
is measuring almost nothing. What actually fired was `pace_min_words`, which was
**50** — two scenes. Running-average deviation from each story's OWN final rate,
measured across both finished stories:

| cumulative words | story 9 | story 21 |
|---|---|---|
| 50 | +9.9% | **+12.6%** |
| 400 | +8.8% | +6.9% |
| 800 | +3.9% | +3.5% |
| 1,000 | +2.3% | +1.9% |
| 1,500 | +1.0% | +1.8% |

At 50 words the instrument's own noise is +12.6% against a 12% tolerance. The
guard was measuring where the sentence breaks happened to fall in the first two
scenes. **With en-CN recorded at its true 199, the same two scenes still cancel
the batch** — there is a test that asserts exactly that, because it is the part
most likely to be forgotten. The threshold is now 1,000 words: noise ~2%, a
fifth of the tolerance, reached at scene 30–34, so a systematic drift is still
caught with 85% of a 270-scene run unspent.

**Two things follow, and the second is the general one.**

The first: the key stays, even though it measures 1.26%. An unmeasured pair
still DETECTS and only declines to ENFORCE, so being finer than the effect costs
nothing and self-heals after one story, while collapsing it would make a third
setting enforceable on day one against prose it has never seen. **If a third
locale also lands within ~2%, collapse it** — two agreeing measurements is a
coincidence, three is a finding.

The second: **a correct diagnosis of one defect is not evidence that it was THE
defect.** The locale key was a real problem, correctly identified, properly
fixed — and the batch would have died anyway. Both faults were in the same
`violation()` call and the first one found was assumed to be the cause, because
fixing it made the immediate symptom plausible to have gone. When a guard fires
wrongly, keep looking after the first thing you find is wrong with it.

The config's own docblock had already made the argument, one axis early —
*"a stale expectation is worse than no expectation, because the check built on
it would pass while being wrong"* — written about speed, and just as true of
prose. When a rule is stated about one dimension of a key, ask whether it
applies to the others.

**Separate what a check DETECTS from what it is entitled to DO about it.** The
first attempt at that fix made the guard silent on an unmeasured pair. It was
nearly shipped and it would have removed the story 9 coverage entirely: that run
had no voice profile at all, and the figure it was judged against was the
fallback constant its own script had been sized to. Detection must not depend on
whether the narrator has been profiled — the expected wpm is *the assumption the
script was sized against*, so comparing reality against it is always meaningful.
What varies is what the disagreement PROVES. On a measured pair it proves
something is wrong: stop, at a cost of one scene. On an unmeasured pair it proves
only that the guess was a guess: report it, on the record, and let the run
establish the number. `NarrationPace::isEnforceable()` is that split, and the
suite's existing tests are what caught the mistake.

The same shape recurs in guards: a check that only tests the axis a component is
already strong on will always pass. The Haiku fallback checked that sentence ranges
tiled (counting — Haiku's strong axis) and missed that it chopped scenes too short.
When adding a guard, name the failure mode it is meant to catch and confirm it fires
against a real instance of that failure.

**And check which FIELD it is pointed at, not only which failure.** The style-notes
guard was correct, well-tested and aimed at one of the two columns that carry the
same invariant; the other column had live violations of every rule it enforced. Both
of these are the same question asked twice — *can this check reach the thing it is
supposed to distrust* — and the answer is not implied by the check being right.

### False success is a defect class, not a run of bad luck

Eight times now the app has reported success while something was silently wrong.
Note where the fifth and seventh live: not in the pipeline, but on the PAGE the
operator watches instead of the pipeline.

| # | What was reported | What was true |
|---|---|---|
| 1 | $8.12 of image spend in the ledger | A stand-in generated 186 flat fills; nobody was billed |
| 2 | 186 stills bought | 185 were placeholders from a fake provider |
| 3 | 186 scenes narrated | 117 read at speed 1.0 with NULL speed provenance |
| 4 | An asset run "complete" | 181 alignments had failed inside it |
| 5 | Subtitles and Mux "1/1 done", mux 506 s | Both stages were chained behind a concat that had just failed and never ran; the rows were 21 h old |
| 6 | Story 21's render page: outline ✓, act scripts ✓, nothing after | Three terminal cast-extraction failures and $0.35 of billed calls, recorded nowhere — the stage had no `render_jobs` row to fail |
| 7 | Story 21's asset run in flight: 118 stills done, nothing failed, no stale heartbeat | The `assets` worker had exited at `--max-time` an hour earlier. 152 scenes sat in Redis with nothing listening, and the page had stopped refreshing itself |
| 8 | Story 21's ledger: narration $2.12, reconciling to the vendor's own counter | The credits reconciled; the DOLLARS were half. One multiplier applied twice, in a column nothing external could check |

The individual bugs are all different and every fix for them was correct. The
constant is the reporting, and it has one mechanism behind it:

**And the reconciliation rule earns its place again.** Story 21's narration was
the first run where an external counter was checked against the ledger straight
after a batch. The two agreed exactly — 21,012 characters on both sides — which
is what made it certain that the 42,017 the operator had been quoted was the
estimate's error and not the recorder's. An internal number agreeing with an
internal number proves nothing; that is the whole content of rule 3.

**Then that same external number settled a second disagreement, in the opposite
direction.** One multiplier — 0.5 credits per character — was applied in three
places by three pieces of code that never compared notes, and the same narration
had three prices:

| | story 21's narration | wrong how |
|---|---|---|
| the estimate | 42,017 billable | quantity over by 2x |
| the ledger | 21,193 billable, **$2.12** | USD under by 2x |
| the rate card | **$4.24** | correct, and disagreeing with both |

The vendor's `character-cost` header is **already the billable figure** — 183
characters sent, 92 in the header — and the recorder multiplied it again, so
`detail.credits` read 46 against a quantity of 92 and `usd_cost` came out half.
The estimate did the mirror image: it summed `mb_strlen` and called it billable.
Both were fixed from one function; `AssetRateCard` needed no change because it
had been right the whole time.

**The quantity column is what made this solvable, and it is worth being precise
about why.** It was the only figure that did not come from us — it is the
header, and it reconciled to the vendor's own usage page exactly. Every other
number was internally consistent with something. The regression test asserts an
IDENTITY between the estimate's route to a price and the recorder's, rather than
either against a constant: a constant can be updated to match a bug.

**Historic rows are not rewritten.** `cost_entries` is write-once and a ledger
that edits itself is worth less than one that is wrong in a way you can date.
Story 21's narration is on record at $2.12 and really cost about $4.24 at the
plan rate; the credits figure, which is the one the allowance is actually spent
in, was right all along.

**Absence is read as agreement.** A NULL provenance column means "unknown", and
every check in this codebase correctly refuses to destroy an asset on unknown —
so unknown is preserved, and preserved reads as fine. A stage that never ran
leaves no failure row. A guard that is not in a worker's loaded code cannot fire,
and a check that cannot fire is indistinguishable from a check that passed.

Row 7 is the purest form of it yet, and worth reading closely because **not one
number on that page was wrong.** 118 stills really were done. Nothing really had
failed. No heartbeat really was stale. The page was false as a whole because of
what it structurally could not see: `RenderJob::open()` runs INSIDE the job, so
a scene still queued has no row, and `render_jobs` cannot count a backlog
however carefully it is asked. `$overall['active']` therefore went false with a
third of the run done, the meta refresh came off the page, and the footer said
"Nothing running".

The fix is rule 3 below, applied to a page rather than to a ledger: ask the
queue. `WorkerHealth` now reads `Queue::size()`, which is the one fact on that
panel not derived from our own bookkeeping, and it is the only thing that can
tell *nothing left to do* from *nobody doing it*. Depth alone is not an alarm —
a live worker with 416 jobs behind it is a worker working. Depth **with nobody
listening** is `stranded`, and that gets the red box. A depth that cannot be
read is shown as unreadable and never as zero, because a dead Redis would
otherwise report every queue as calmly empty at the exact moment the instrument
broke.

**And the cause of row 7 was a number sized against the wrong story.**
`--max-time=3600` on `assets` was written when a story was 186 scenes, against
an assumed 30 s per image; a fal call measures 53 s at the median and story 21
is 270 scenes, so one worker needs six hours. The number is now derived from
measured p99s in `docs/queue-workers.md` rather than rounded, and it is
re-derivable when the scene count changes. But sizing only buys margin — the
worker still exits eventually, and the actual fix is that something restarts it.
See the NSSM note below.

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

**A guard that fires is evidence about the guard's INPUT, not only about the
thing it guards.** `PadSceneAudio` refusing at concat was read at first as "the
audio is wrong"; it meant "the frame count handed to me is too small". The
message already said so — it printed the sample count, the rate, the converted
count and the capacity — and the fix was three steps upstream from where the
alarm rang. When a guard fires, check what it was given before checking what it
was checking.

And when a check cannot run, that is a failure, not a pass. An unreadable quota
is reported as unreadable and never as "fine" — the same rule, one level up.

**A warning that nobody can see is not a warning.** The stale-worker refusal is
correct and correctly placed, and it deliberately does not fire on an ABSENT
worker — nothing is lost, the job queues and waits. That was safe while three
terminal windows were open, because the terminals *were* the worker display.
The console removes them, so the console has to carry the reading: worker state
appears beside every dispatch button and in the stories index, from the same
registry the refusal reads, recomputing nothing. Note where the guard did NOT
move — a page rendering its own opinion about staleness would be a check
evaluated downstream of the thing it distrusts, which is rule 1 exactly.

Note also that **NSSM makes staleness more likely, not less.** A hand-started
terminal dies on reboot and comes back with current code; a service up for six
days across four config edits is the stale worker, restarting itself after every
crash somebody might otherwise have noticed. Uptime is therefore shown next to
the fingerprint — not as evidence, but as the thing to look at when the
fingerprint agrees and something is still wrong.

**That trade is worth taking, and row 7 is why.** The services were documented
from Phase 1 and never installed, so every worker on this machine was a terminal
that exited at `--max-time` and did not come back — which is the failure that
stalled a 270-scene run repeatedly and reported it as in flight. The staleness
NSSM adds is REFUSED at dispatch, loudly, by `AssertWorkersCurrent`; the stall
it removes was silent and cost hours per occurrence. A refusal you can read
beats a stall you have to notice. `scripts/install-worker-services.ps1` is the
install, elevated and idempotent, and the only remaining discipline is
`queue:restart` after a config or provider change.

---

## Out of scope

Do not build these until asked, and do not add scaffolding "for later":

- YouTube upload API integration (the app outputs a file and a metadata sheet)
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
