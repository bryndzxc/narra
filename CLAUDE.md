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
- Roughly 5,900–7,900 words of narration — 30–40 minutes at the **measured 197
  wpm**, not the 160 that was assumed for a phase. The band moved because the
  rate it is derived from was corrected, which is this section's own rule
  working: runtime is the product, the word band follows it, and if they ever
  diverge the band is what moves.

**Runtime is the product; the word band is derived from it.** That principle
stands. The NUMBER it was applied with did not: 160 wpm was chosen to reconcile
two targets in this spec that contradicted each other, and it was never compared
against a vendor — it could not be, because the only synthesizer that existed was
a fake that derives its duration from the same constant, so the two agreed by
construction and the agreement proved nothing.

The measured figure is **197 wpm** (en-US, 186 real scenes) and **199** (en-CN,
270 scenes). Sizing at 160 asks for 5,600 words, which that narrator reads in
**28.4 minutes** — under the floor before a word is written. Every script this
pipeline produced was short by construction, and story 9 cleared it by 21 seconds
only on a 3% generation overshoot.

Keep **one** answer to "how many words", and it is `App\Support\ScriptSizing`.
Four places used to derive something from the raw constant — the word target, the
dispatch estimate, two prompt figures, and `story:write`'s reported runtime — and
a constant corrected in one of four places is the shape that gave one narration
three different prices. The constant survives as the FALLBACK only, read by
`NarrationPace`, the TTS fake (which has no real voice to be measured) and
`RunFingerprint` (which records it as provenance). A test names those three and
fails on a fourth.
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

   **The act count moved from six to seven for this, and has since moved back
   to six — for a reason that has nothing to do with the phases, and both facts
   belong on the record.**

   Six -> seven was made HERE, for the reversal: the arc had been escalation ->
   exposure -> end, and the departure, the search and the refusal needed
   somewhere to go. That reasoning is untouched and is why the phases exist at
   all. What it could not know is how long an act actually comes back, because
   nobody had measured it: the writer produces **~1,100 words almost regardless
   of what the prompt asks for**. Story 21 was asked for 800 and wrote 1,152; a
   probe on current code was asked for 985 and wrote 1,123; the fitted slope
   across five observations is **+0.30**, so a hundred words more asked buys
   about thirty. **The word target is advisory. The act count is not** — it
   multiplies a length the prompt cannot argue with. Seven acts of natural
   length is ~7,900 words and 39.9 minutes against a 30-40 window; six is 6,738
   and 34.2.

   **Six costs one escalation act and no phase.** `ActPhase::planFor()` gives
   escalation 1-3, departure 4, search 5, refusal 6 — the reversal still
   occupies three acts of six. The departure is held to at most `count - 2` so
   the search and the refusal always have an act each; a search with nowhere to
   run and a refusal in the same act as the leaving is the compressed ending
   this whole structure exists to replace. (That clamp is the GUARANTEE rather
   than the binding term at every count: at six and seven the two-thirds point
   already lands correctly, and the clamp is what actually bites at four and
   five acts.) What gives ground is the escalation, four beats down to three —
   25% of it, not a missing phase.

   **Both counts put three of five measured stories in window; they fail on
   opposite sides.** Six lands two under the floor, seven lands two over the
   ceiling. This section's own rule decides it: the floor is a preference and
   the 8-minute mid-roll threshold is the only law, and the reference channels
   run 44 and 54 minutes. Over the ceiling costs nothing measurable; under the
   floor costs ad density.

   **Story 9 and story 21 are not regenerated.** Their outlines predate the phase
   and Gate 1 says exactly that, once, as a warning naming what is missing —
   rather than as three "missing field" problems on a shipped video. An outline
   somebody has started fixing by hand gets the ordinary per-field checks back.

3b. **THE OPENING IS FIVE BEATS, AND THE FINDING BEHIND IT IS THAT WE ALREADY
   HAD FOUR OF THEM.** Both shipped stories were read against the beats this
   niche's openings actually run, and the result is not what the fix was
   expected to be:

   | beat | rent-will (12) | my-wife (21) | wanted |
   |---|---|---|---|
   | the betrayal, dramatised | never in 40 scenes | never in 40 scenes | ≤ 0:20 |
   | evidence in exact words | 6:44 | 2:08 | ≤ 0:40 |
   | one small cold action | 3:24 | 3:09 | ≤ 0:40 |
   | the promise | the exposure | the exposure, 3:35 | the departure |

   **Four of the five exist in both stories, written well, and every one of
   them lands two to seven minutes late.** Story 21 has the best cold action in
   the database — *I said, "Have a good trip. I'll take you to the airport."* —
   at 3:09. Story 12 opens a spreadsheet and names it MOM EXPENSES 2020 at
   3:24, and has no antagonist speech at all until 6:44, while
   `antagonist_justification` holds *"You're the only person in this family who
   could look at a pregnant woman and see a tenant"*, used nowhere.

   **That is not a writer who cannot do this.** It is a writer with no
   instruction about where the opening starts, writing the chronological
   beginning, because context is what you get by default — the story begins
   with a household, so the video begins with a household. Story 12 opens on a
   pot boiled black on a stove in March 2020; story 21 opens on the square
   meterage of an apartment. Both are the correct first thing that happened.

   It sets what the fix is FOR. Nothing here asks for better writing and
   nothing regenerates a story: the outline gains an answer to a question
   nobody had asked it, and act 1 is handed that answer instead of four
   sentences of what not to do.

   `scenes.is_hook` is the marker that made this invisible. It says WHICH scene
   the hook is and has never once asked whether it does the job — a flag with
   no contract behind it, which is the same shape as a documented guard nothing
   implements, one step further out.

   **The five beats**, on `stories.hook`:

   - **One sentence of setup.** One. Never a sentence about the video itself —
     story 12's scene 2 is *"I want to start there, at the yes, because
     everything after it makes more sense…"*, a narrator explaining structure
     inside the twenty seconds.
   - **The betrayal inside ~20 seconds**, dramatised rather than summarised.
   - **Evidence in EXACT WORDS**, quoted.
   - **One small, cold action** by the narrator. Not a confrontation — that is
     the final act, and spending it here spends the video.
   - **A closing line promising the DEPARTURE**, not revenge and not exposure.

   **Hoisting is COPYING, not moving, and the prompt says so in both
   directions.** The hook draws on `antagonist_justification`; the act keeps
   it. In this genre the same line lands twice — once in the first thirty
   seconds as one quoted sentence of bait, once in act 2 or 3 played out at
   length in the room it was said in — and the second landing is stronger for
   the first. A generator told to "use it in the hook" spends it and leaves the
   act paraphrasing itself, so the instruction names the reuse explicitly.

   **The twenty seconds is measured at the story's own frozen sizing rate, and
   one place owns the conversion.** `ScriptSizing::wordsForSeconds()`. It is a
   SIZING question, not a pace question: the deadline is an instruction to the
   writer about how much text it may spend, and it rides in the same act 1
   prompt that already carries a word target from `targetWordsPerAct()`.
   Deriving one from `wpmFor()` and the other from `bestKnownWpm()` would put
   two beliefs about one narration inside a single string — the $2.12 / $4.24 /
   42,017 shape reproduced in one file, which is harder to see than across
   three, not easier.

   | | rate | 20s budget | plays as |
   |---|---|---|---|
   | stories 9, 12, 20, 21 | 160, frozen | 53 words | 16.0 s |
   | a story written today | 197 | 66 words | 20.1 s |

   What it does NOT claim is that the finished video states the betrayal inside
   twenty seconds. It claims the SCRIPT WAS WRITTEN TO, and those agree exactly
   as well as the sizing rate does — `NarrationPace` is already the thing that
   measures the disagreement, and this is the same split `ScriptSizing` opens by
   drawing. The direction is the safe one wherever they differ, which is every
   shipped story: `bestKnownWpm()` takes the highest measured rate, so sizing
   sits at or below reading. Checked against real audio rather than assumed —
   53 words lands mid-scene-2 of story 21, and scene 2 ends at **00:20.1
   measured**.

   **Gate 1 checks the last beat and only the last beat.** By overlap against
   `departure`, exactly as `refusal` is checked against the moments it answers,
   reporting WHICH sentence of the departure the hook promises — "it promises
   something" is worth less in front of an approve button than "it promises the
   part where nobody is given an address".

   The other four are deliberately unchecked and that is worth saying so the
   field is not read as covered: the outline holds a paragraph DESCRIBING the
   opening, not the opening itself, so counting its sentences would be counting
   the wrong text. The fifth beat is different in kind — it is a claim about
   WHICH VIDEO this is, and the story already carries the answer in another
   column, so the two can be compared. **A hook promising revenge on a story
   whose payoff is a refusal is the same mismatch class as 3a, not a missing
   field.** It is the one beat that can be wrong rather than merely weak, and a
   closing line reaching the exposure is named as that rather than as promising
   nothing: those are different repairs.

   **Stories 9, 12, 20 and 21 get the 3a treatment — four stories, not the two
   that were noticed.** Every outline written before the reversal phase existed
   was written before this field existed too, so the blanket warning names the
   hook alongside the departure, the search and the refusal, once. The overlap
   check returns before it looks at anything when there is no departure to
   promise against: reporting "the hook promises nothing" on a story that has
   nothing to promise would be a second finding about an absence the line above
   it already reported, and not one the operator can act on without
   regenerating the outline. An operator who types a hook in by hand ends the
   blanket excuse and gets the ordinary per-field checks back, which is the
   behaviour `departure` already had, extended rather than written twice.

   **Story 22 is NOT in that set and correctly gets a problem.** It was
   outlined after the reversal phase and before the hook, so it is a state the
   legacy predicate cannot detect and should not — it is a fixture at
   `outlined`, editable, and the repair is free.

   **The half of the guard that no test could reach, found by a drill
   PASSING.** `checkHook()` returns early when the hook is empty OR the
   departure is. Removing the departure half left every hook test green,
   because the only fixture exercising that return had an empty hook as well
   and stopped on the first clause. The state is real — a hook typed in at Gate
   1 on a story whose departure is still empty — and it now has its own case.
   That is the ninth instance of "the detector was right and the input it was
   handed could not contain the defect", and the first one caught by running
   the drill rather than by an unrelated change stumbling over it. **Suspect
   the drill first** paid for itself twice in this change: the other passing
   drill was `hook: '' ?: '…'`, which PHP evaluates to the original string, so
   the patch was a no-op pretending to be a defect.

   **The red/green pair keeps its own fixture, and that is asserted rather than
   assumed.** A hook mismatch needs three things at once — a departure, an
   exposure distinct from it, and a refusal payoff — and
   `GateLayoutContractTest::pageFixtureFor()` is deliberately a pre-phase story
   with none of them, so a pair built on it would have been green in both
   halves. `GuardsGoRedTest::test_the_hook_fixture_can_express_the_mismatch`
   asserts all three are present, that RED and GREEN differ in their closing
   line and nothing else, and that the shared fixture cannot hold this — so
   nobody later consolidates onto it and quietly makes both halves vacuous.
   The fixture lesson applied BEFORE the third time rather than after it.

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
hook,
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
  could not make. Both carry `is_fixture`, so the console stops counting them as
  work that has stalled — see the flag's own entry under Conventions.
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

- **The console's ground is Nocturne's blue-grey, and that reverses a
  documented decision — read this before judging a still.** The palette was
  moved to `#161826` / `#1c1e2b` when the design direction was settled. The
  previous ground was a NEUTRAL charcoal `#0e0f13`, chosen deliberately because
  the blue-black before it "was saturated enough to tint every still on the
  Gate 2 page, which matters here more than it would elsewhere: the operator is
  judging artwork against it for an hour at a time."

  That risk is real and is not resolved — it is accepted, with the fix pre-named
  so nobody has to rediscover it. `#161826` is about three times further from
  neutral than `#0e0f13` on the blue axis. If stills start reading cool on Gate
  2 or on the faces contact sheet, the revert is two lines: `--d-bg` and
  `--d-panel` back to `#0e0f13` / `#171a22`. Everything else in the palette —
  the status ramps, the inks, the alarm band — is independent of it.

  **The inks were re-measured against the new ground rather than carried over,
  and three of them had stopped clearing 4.5:1**: `--d-meta` (labels and every
  `th`) at 4.27, `--d-fail-ink` on a badge at 4.44, and the light absent-band
  text at 3.51. None of those would have failed a test or looked wrong in a
  screenshot. Adopting a palette without re-running the contrast pass is how a
  warning goes quiet while looking deliberate.

- **A stale worker restarts itself, and the guard that refuses it is untouched.**
  `AssertWorkersCurrent` still refuses a spend into stale workers, in the
  dispatching process, as loudly as before. What changed is that the machine no
  longer *sits* in the refused state: a worker whose sealed code marker no
  longer matches the disk exits between jobs, and NSSM's `AppExit Default
  Restart` brings it back current.

  The reason this was worth building is not convenience. The panel that went red
  is the one read before authorising a spend, and a red meaning "somebody saved
  a file" is indistinguishable from a red meaning "your pipeline has stopped".
  **An alarm that fires for something the reader cannot act on is the cheapest
  way to teach them to ignore it** — the same argument as a section that always
  contains something it should not.

  **Why recomputing the marker is safe here when `RunFingerprint` says at length
  that it is not.** That warning is about SELF-CERTIFICATION: a stale worker
  reading the new files and announcing itself current would defeat the guard
  entirely, and the incident behind the design cost 117 scenes at the wrong
  speed. `codeOnDiskNow()` has the opposite polarity — it is used only to decide
  to DIE. A wrong "I am current" costs a batch; a wrong "I am stale" costs a
  restart. What a worker ANNOUNCES is still the sealed marker, and
  `StaleWorkerRestartTest` asserts exactly that; if that test goes red the
  fingerprint guard is over.

  Three bounds, all in `StaleWorkerRestart`: it never fires while ANY queue on
  the machine holds work, so a batch in flight is never interrupted and cannot
  be split across two code versions; the exit goes through the cache flag
  `queue:restart` sets, which is the only graceful stop available without
  `pcntl`; and it cannot loop, because after a restart the sealed marker IS the
  disk marker. Off in production, where the deploy restarts workers.

  **The first bound said "the queue" and meant the worker's own, and that was
  false.** The bound was evaluated per worker; the stop is `queue:restart`,
  which is a machine-wide broadcast. So an idle worker on an empty queue stood
  down correctly by its own lights and took every busy worker with it. On a real
  run that is the render worker idling while a 270-scene assets batch is in
  flight, somebody saves a file, and the batch silently changes code version
  half way through — the exact split the bound exists to prevent, produced by
  the bound's own mechanism.

  **Rehearse it; do not believe it.** `php artisan workers:drill idle` and
  `workers:drill busy` are the deliberate triggers, and `busy` found the defect
  above on its first run. The unit tests could not: `queueDepthIs()` answers one
  depth for every queue, so every test written with it describes a machine that
  is uniformly busy or uniformly idle, and the failure lives in between — mine
  empty, my neighbour's full. **It was not untested, it was inexpressible**,
  which is a sharper version of "a check that cannot fire is indistinguishable
  from a check that passed": a fixture that cannot describe the failing state
  makes the whole suite blind to it however many tests are added.

  **And the mechanism cannot bootstrap itself.** A worker that booted before
  `StaleWorkerRestart` existed has no listener, so it cannot notice anything and
  will sit stale forever — which is how it was found: all three workers were
  stale for half an hour with the feature merged and doing nothing. The first
  restart after adding or moving the listener is always manual. Same rule as the
  incident this whole area exists for: a guard that is not in a worker's loaded
  code cannot fire, and that applies to the guard that restarts stale workers.

- **Never write `@php` or `@endphp` inside a blade comment, and never put the
  inline parenthesised form above a block.** Blade's raw-php-block pass runs
  BEFORE directives compile and pairs the first opener with the next closer over
  the RAW file. It does not know the inline form exists, and it does not know a
  comment is a comment.

  Both mistakes were made within ten minutes of each other and both took every
  gate page in the console down. Neither announced itself: the symptom was
  `Undefined variable $grouped` a hundred lines below the damage, on every page
  that rendered the component, because the block that defined it had never
  compiled. A parse error would have been kinder — this reads as an application
  bug, and the test suite reports it as fifty unrelated view failures.

  `tools/blade-php-scan.php` refuses both shapes. It was verified by
  reintroducing the defect and watching it fire, which is the standing rule for
  a new guard here: name the failure mode and confirm it catches a real instance.

- **`stories.is_fixture` — a story kept to be measured against, not published.**
  Three of them exist and every operator surface was counting them as
  outstanding work: `sample-story` is parked at `rendered`, which made it a
  PERMANENT resident of "Waiting on you" — the one section of the dashboard
  that is supposed to be the only actionable thing on it — while
  `style-preview-fixture` and `style-preview-fixture-2` sat forever in "Not
  moving", a section whose entire meaning is "this should be moving and is not".

  **A section that always contains something it should not teaches you to skim
  it, and you skim it right past the day something real lands there.** Same
  failure as an alarm that is always on: not a wrong number, a true one that has
  stopped being read.

  It is a column rather than a slug prefix or a title match, because those are
  guesses about intent that a rename breaks silently. `fixture_note` is a second
  column because the reason genuinely differs — one is a render-pipeline
  fixture, two are cast measuring sticks — and the story's own page should say
  WHICH without the reader going to look it up.

  **Hidden from the queue of things to do, never from the app.** A flag that
  made a story vanish would trade one silent wrongness for another: someone
  looking for the fixture would find nothing and have no way to learn why. It
  keeps its page, its costs and its row on the index, it is badged there, and
  its own page states plainly that it is a fixture and why it never advances —
  so the absence from every count is explained where somebody would go looking.

- **The dashboard's layout is a function of its state, not a constant.** It was
  drawn for the busy case — three columns, an alarm band, a scene grid, wide
  panels — and most of the time none of that is true. The busy layout with
  nothing in it is not a calm page: it is the same containers at the same size
  holding gaps, and empty ones compete for attention with the one section that
  can be acted on. "In flight: idle" took a full column to say nothing while
  seven abandoned drafts outweighed the three gates that were the only
  actionable thing on the screen.

  So when nothing is running, nothing is broken and no queue depth is
  unreadable, `.dash.quiet` gives the decisions the width the other two columns
  were using and collapses what is NOT happening into one line with a
  disclosure. Nothing is dropped: anything that has actually failed is an alert
  at the top of the page, because a failure is not a quiet state — which is
  also why an unreadable depth counts against quiet even though no queue is
  troubled.

  The test is "with nothing running, the page answers *what should I do next*
  in the first screenful". A test suite cannot measure a screenful, so
  `DashboardTest` asserts the structural properties that produce one — quiet
  mode set, decisions first in the document, the idle sections a strip rather
  than cards — and says that is what it is doing.

- **The same rule at two smaller sizes: a ROW lays out against the groups that
  have content, and a SENTENCE names an action only if the action is
  available.** Both are `.dash.quiet` below page resolution, and both were
  shipped on Gate 1 after being fixed on the dashboard and on Gate 2 — see the
  entry in "Where bugs actually live" for why the tests written first could not
  see either.

  Mechanically: a decision or advisory row is `x-gate-row` holding
  `x-gate-group`s, never a hand-written `.gatecols` with hand-written wrappers.
  A group with an empty slot renders no element and the grid cuts no track for
  it. And any clause naming an action comes from `App\Support\GateVoice`, which
  holds both phrasings and picks from the gate and the status —
  never from a string written at a call site, because a fix at a call site
  cannot reach the sentence in the next alert down. Both are asserted over
  every gate page at every status in `GateLayoutContractTest`.

- **The alarm band is the one saturated flood in the console.** Everything else
  that means something is a wash on a panel — 1.1x to 1.3x from the panel
  beside it, which is enough when it is a box among boxes. A stopped pipeline is
  not a box among boxes: the band is full-bleed under the chrome, a saturated
  red ground with near-white text, and it measures 7.2x from the panel in light.
  In dark the luminance figure understates it, because the separation there is
  chromatic — a saturated red against a desaturated blue-grey.

  It replaced three stacked per-queue alert boxes and every fact survived the
  consolidation: each queue named, each pending count printed, each command
  pasteable, the health table carried along. Only the ~60 words of shared
  explanation stopped repeating. `.band.warn` is the ABSENT case in amber,
  never merged with the alarm — stranded means the pipeline has stopped now,
  absent means nobody is listening to an empty queue, and they want different
  reactions.

- **The accent is what you PRESS; the status ramps are what you are TOLD.**
  Before this, a link, an in-progress badge and a running progress bar were all
  the same blue. `--accent` now carries navigation, links and the focus ring;
  `--ok/run/fail/warn/money` carry state and nothing else. It is the reason the
  console can be calm and still shout.

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
- **THE APP HAS NO PUBLICATION EVENT, BECAUSE IT NEVER UPLOADS. Nothing may
  report one, and nothing downstream should go looking for one.** The pipeline
  ends at a file and a metadata sheet; the human uploads, sets the synthetic
  content disclosure and schedules. That decision is non-negotiable #2, and this
  is its consequence in the schema: the only publication event happens in a
  browser this app never sees, so there is no column that holds it and no column
  that could.

  It was worth writing down because a page had already invented one. Gate 4's
  banner read "Published on \<date\>" from `stories.updated_at` — **two defects
  in one sentence, and only the first was position.** The condition was wrong,
  which the position axis caught. The figure was wrong in EVERY state, which no
  capability can express: `updated_at` moves on any write, and
  `CostEntry::created` increments `stories.total_cost_usd`, which is a write. On
  story 9 it resolved to `2026-09-02 02:16:15` — to the second, the moment a
  `fal` **style preview** was billed, a day after the sheet was approved. That
  is `CostCategory::Evaluation` spend, the one category deliberately kept OUT of
  a video's cost, dating that video's publication. And it was not settled: the
  next preview run against story 9 would have moved it again.

  What the banner says now is what the app actually did — it crossed its own
  gate — through the same `GateVoice` clause Gate 1's locked banner uses, so it
  is a checked position claim rather than a hand-written one. **No column was
  substituted, and that is the point rather than a shortcut.** The three honest
  options were: record the gate crossing in a real column, say nothing about a
  date, or let the operator type the real one. The second was taken because it
  costs nothing and claims nothing; `stories.gate_approved_at` is a reasonable
  thing to want later and is not a prerequisite for anything.

  **The general form: when a figure cannot be stood behind, remove the figure —
  never find a nearby column that is the right TYPE.** `updated_at` is a
  timestamp and a publication date is a timestamp, which is exactly why this
  read as reasonable for a phase. It is the checklist rule with the arrow
  pointing outward: an item about something that cannot exist is the same defect
  as a form with no producer, so make the thing exist or stop asking.

  A registered claim fragment is NOT protection here, and the one that existed
  was removed with the sentence. `publishedOn` could only ever answer "may this
  STATE say this"; it had no opinion about whether the date was a date. Keeping
  it for a sentence no page emits would also have been the dead-mechanism seam.
  This paragraph is what replaces it — see the axis question, where a claim
  about a FIGURE is the third axis nothing currently checks.

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

- **NOTHING ASKS WHICH OTHER CALLERS READ A FIELD, AND THAT HAS NOW COST TWICE.
  THE ENTRY ABOVE IS HALF OF THIS ONE.** Worth reading as a single finding rather
  than as two unlucky bugs, because the two instances look nothing alike and are
  the same mistake:

  | | the field | the consumer that was wired | the consumer nobody asked about |
  |---|---|---|---|
  | 1 | `CostUnit::TotalTokens` | the PHP enum and every reader of it | eleven migrations that build their MySQL ENUM from `cases()` |
  | 2 | `acts.escalation_beat` | `GenerateActScripts`, fixed and written up as closed | `DraftScenes`, which decides what 150-250 pictures contain |

  **Instance 2 is the sharper one, because the fix for it was already in this
  file, marked done.** The entry above closes with "the fake now records what it
  was handed, so the next dropped field fails a test". That was true of the ACT
  path. `FakeScriptWriter::scenes()` recorded the act sequence, the sentence
  count, the cast size and the target — and not the phase, the beat or the
  spine, so nothing could see that the scene generator was never handed them.

  And the scene call had been receiving `Act $act` and `Story $story` the whole
  time. This was not a DTO dropping a field on the way, as it was for the act
  path; the phase, the beat and the entire genre spine were sitting on the two
  objects already in the argument list, unread. **A fix applied at one call site
  and recorded as closed reads as covered** — the same sentence as
  `ImagePromptBuilder`'s staleness check and as the migration lesson that reached
  `category` and not `unit`.

  What is in place now, and it is deliberately not a tool:

  - `sceneContext()` prints the phase, the beat and the grievance/justification
    into the scene call. Free, populated on every story in the database, and
    `escalation_beat` reaches the pre-phase stories that have no `phase`.
  - `FakeScriptWriter::scenes()` records all of it, the way the act path does.
  - `OutlineSpineTest::test_the_scene_writer_is_handed_the_phase_the_beat_and_the_spine`
    sits directly beneath the act-path version so the pair is visible as a pair.
    Drilled: null the beat in the fake and it goes red.

  **The practice, which is cheap and is what the table above would have caught:
  when a field is wired to one consumer, assert its arrival at EVERY consumer in
  the same change.** Not a sweep, not a linter — a question asked once, while the
  change is open: who else reads this, and does anything fail if they stop? A
  speculative tool here would be the documented-guard shape again; what makes
  this checkable is that each consumer gets an arrival assertion at the moment it
  is wired.

- **A PARTIAL SCENE RE-DRAFT COLLIDED WITH ITSELF, AND ITS OWN TEST FILE COULD
  NOT EXPRESS THE FAILURE.** `story:scenes --acts=` had never worked on a real
  story. `DraftScenes::persist()` parks the NEW rows at `PARK_BASE + 1 ..
  PARK_BASE + N`; `renumberByAct()` then parked EVERYTHING at `PARK_BASE +
  $index`, walking the untouched earlier acts' scenes straight back through the
  band the new rows were still sitting in. Story 12 died on `Duplicate entry
  '12-30001'` after billing two model calls for the act it then rolled back.

  Its six green tests are entry 10 in the self-defeating-checks table, where the
  fixture lesson belongs — and it is a DIFFERENT mechanism from the rest of that
  table, which is the reason it is worth reading there rather than here.

  The renumber parks above `max(sequence)` now rather than at a constant, with a
  refusal if that would overflow `unsignedSmallInteger`.

- `stories.target_publish_at` — two timezone helpers and a display block on the
  index, and no input anywhere, so the column was null on every story and the
  block never rendered. Meanwhile the Gate 4 checklist asked the operator to
  confirm a scheduled publish time the app had no way to hold. **A checklist
  item about something that cannot exist is the same defect as a form with no
  producer** — the fix is to make the thing exist or to stop asking, never to
  leave the question there.

- **A SCHEMA GENERATED FROM THE CODE UNDER TEST AGREES WITH IT BY CONSTRUCTION,
  AND THAT AGREEMENT PROVES NOTHING. Twelfth false success, first one in the
  DDL.** `CostUnit::TotalTokens` was added in code with no migration. The first
  real outline after it — story 23, render job #4703 — ran 87 seconds against
  claude-opus-5, completed, and died writing its cost row:

  ```
  SQLSTATE[01000]: Warning: 1265 Data truncated for column 'unit' at row 1
  ```

  `RecordProviderCost` runs before the transaction that writes the acts, so the
  story got no outline either. **Billed spend, no ledger row, no product** —
  non-negotiable #4 failing in the exact way it is written to prevent.

  **Why 943 tests, four clean audits, known-answer fixtures and eight drills
  were all green.** Not one of them was wrong. Eleven columns build their MySQL
  ENUM from the PHP enum at migration time:

  ```php
  $table->enum('unit', array_column(CostUnit::cases(), 'value'));
  ```

  `RefreshDatabase` re-runs the migrations, so **the test database's column is
  generated from the enum under test and cannot disagree with it.** The two
  databases on this machine had literally different columns:

  ```
  narra       enum('input_tokens','output_tokens','characters',...)   built Aug 28
  narra_test  enum('total_tokens','output_tokens','characters',...)   built just now
  ```

  That is the fake TTS deriving its duration from the constant it was meant to
  check, moved into the schema. No test in this suite could ever have caught it,
  and adding one that asserts "every enum fits its column" would be worse than
  nothing — it would pass on every machine forever, including on the morning
  production could not write a row, and read as coverage of the one thing
  nothing covers.

  **THE FINDING WAS ALREADY WRITTEN DOWN, TWO DAYS EARLIER, IN THE SAME TABLE.**
  `add_evaluation_category_to_cost_entries` (2026-09-03) closes with: *"The
  value list is written out literally rather than read from the enum. The two
  migrations before this one call `CostCategory::cases()`, which means their
  meaning changes every time a case is added — a migration that is never edited
  but does not say the same thing twice."* Correct, complete, and applied only
  to `category`. Three files later the next enum change walked into the identical
  defect on `unit`. **One instance fixed by hand is not a mechanism**, and a
  documented finding with nothing enforcing it reads as covered — the same
  sentence as `ImagePromptBuilder`'s staleness check and Gate 2's advisory
  heading, this time about a migration.

  **The mechanism is `php artisan schema:enum-drift`, and it is a COMMAND rather
  than a test or a `tools/` script for one reason: it reads the live column.**
  Rule 3 — keep one number that we did not compute — applied to DDL. Everything
  else in this area is derived from the enum and therefore agrees with it. Run
  it after adding or removing an enum case, and at the end of a phase beside the
  four static audits.

  It reports two directions and only one is severe. **CODE AHEAD** — the enum
  can produce a value the column cannot hold — sets a non-zero exit, because
  writing it truncates. **COLUMN AHEAD** is reported and never fatal: that is
  what a retired case looks like, `output_tokens` has 107 rows and no writer and
  must stay, and a severe category full of findings nobody can act on is a
  severe category that stops being read.

  Swept at the time of the fix: **exactly one of the twelve enum columns was
  drifting**, and it was the one that failed. The other eleven are in step, not
  by design but because every enum change since August happened to arrive with
  its own `->change()` migration.

  **The first version of the sweep reported all twelve as drifting**, and it was
  nearly reported that way. MySQL returned the metadata column under a name the
  probe did not read, `column_type` came back null on every row, and every enum
  case therefore looked absent from its column. **A detector that reports
  everything is worth less than one that reports nothing**, because the severe
  category is the one that gets acted on. The query aliases the column now, and
  an unreadable column returns null and is reported as UNREADABLE — never as an
  empty list, which is what made the false sweep look plausible.

  **The lost spend was recoverable in full, and from an unlikely place.** Laravel
  interpolates the bindings into a `QueryException` message and `RenderJob::record`
  stores that message verbatim, so every column of the destroyed row survived in
  `render_jobs.error`: 10,784 total tokens, $0.152177, the four-way token split,
  and the original timestamp. It checks against itself — 2266 + 5575 + 0 + 2943 =
  10,784 — so the figures did not have to be inferred from a comparable call.

  **Restoring it is not an edit and the distinction is load-bearing.** The
  write-once rule is why story 21's narration still reads $2.12 when it really
  cost $4.24: a figure the ledger states is never rewritten. This wrote a row
  that was never written, for a call that certainly happened, at its own
  timestamp. The row carries `restored_from` in `detail`, because a reconstructed
  row that looks identical to a directly-written one is a small false success of
  its own — the figures are trustworthy and the provenance is different, and only
  the row can say so.

  **A schema literal is frozen; a predicate is live.** The backfill needed the
  set of categories that count toward a video's cost, and reads it from
  `CostCategory::countsTowardVideoCost()` rather than retyping it — while the
  migration beside it writes its ENUM values out by hand. Those look
  contradictory and are the same rule: a schema literal describes what the column
  was made to hold on the day it was made and must never move, and a predicate
  has exactly one right answer today. Retyping the predicate is the shape that
  gave one narration three prices.

- **Gate 1's retry EXISTS. What is missing is the failure.** Worth correcting on
  the record, because the reasonable read of the incident was "a red panel and no
  button" and it is the other way round.

  `OperatorAction::WriteScript` is permitted at `draft`, `askToWrite` renders on
  story 23 right now, and `WriteStoryJob` re-runs the outline whenever the story
  has no acts — so the failed run is re-runnable today, through the same button
  that started it, and pressing it after a complete run queues nothing to bill.

  **What Gate 1 does not do is read `render_jobs`.** Job #4703 is a `failed` row
  with the full error on it; the gate page mentions neither the failure nor the
  error, so a story whose outline died 87 seconds and fifteen cents in looks
  exactly like a story nobody has started. That is false-success row 6 —
  "outline ✓, act scripts ✓, nothing after" — with the arrow reversed: there the
  stage wrote no row, here the row exists and the page does not look at it.
  `$this->problem` holds a dispatch failure only for the life of the component,
  so a failure inside a worker, or any page reload, erases it.

  `/renders/{slug}` shows all of it, including the error text. So the
  information is one click away and on the wrong page: the decision surface says
  nothing and the progress page says everything.

  **What it would take**, not built: a `failedStages()` read on `OutlineGate`
  mirroring `ScenesGate::failedScenes()` — the open `render_jobs` rows for
  `outline` and `act_scripts` with `status = failed` — surfaced as an `.alert.err
  .wide` above the decision, naming the stage, the error and the time, with the
  existing write button as its action. No new Action, no new job, no capability
  change; the button and the re-run path are already there and already correct.
  The one judgement call is whether a failure older than the last successful run
  of the same stage should be shown at all, which is why this is a report and not
  a patch.

- **A REMEDY THE STAGE DOES NOT HAVE, PLUS AN ENV VAR THIS APP DOES NOT READ —
  in one sentence, on all eight operations.** `generate_outline` hit its 16,000
  output ceiling on story 23 and said:

  > Raise ANTHROPIC_MAX_TOKENS or lower the per-act word target — a truncated
  > act cannot be salvaged and re-running it costs the same again.

  Three things wrong, and the middle one is the expensive one.

  - **The outline has no per-act word target.** That lever belongs to
    `generate_act_script`. The message was written for that stage and inherited
    by seven others — the same "fixed at one call site, so it cannot reach the
    next" shape as Gate 2's advisory heading before `GateVoice`.
  - **`ANTHROPIC_MAX_TOKENS` is not a variable this app reads.** Every ceiling
    is suffixed: `_OUTLINE`, `_ACT_SCRIPT`, `_SCENES`. Setting the name in the
    message changes nothing, **silently**, so the remedy looks applied and the
    next run fails identically. An operator can spend an hour and a second
    billed call proving the advice does nothing.
  - "a truncated ACT" is the wrong noun on six of the eight.

  **A message naming a remedy the stage does not have is worse than no
  message.** It is confident, specific, and points somewhere there is nothing to
  find — the checklist-item-about-something-that-cannot-exist defect, moved into
  an exception.

  `truncation_remedy` now sits beside each operation's `max_tokens` in
  `config/providers.php`, so the advice and the number cannot drift and the env
  var named is the one written on the line above it. A `match` in the thrower
  would have been a second copy of the roster, which is how `assets:generate`
  came to print a `--max-time` that had stopped being the sized one. An
  operation with no remedy configured **says so** rather than inventing a
  plausible generic one — inventing one is precisely how the old message read.

  **The drill that mattered passed first.** Five cases went red; restoring the
  old inline `sprintf` at the throw site did not, because every assertion
  reflected into `truncationMessage()` and none checked that the thrower calls
  it. The builder was correct, well tested, and no longer reachable —
  `escalation_beat` reaching a prompt that never sent it, in a test file. Two
  assertions cover it now: no ceiling env var may be named anywhere in `app/`
  code (which would have caught the original defect), and the `max_tokens`
  branch must delegate. Drilled three ways, including a revert that names no env
  var at all, per the rule that a text guard is drilled with the input written
  the OTHER way.

- **THE HOOK DID NOT CAUSE THE TRUNCATION, AND THE RUN THAT PROVES IT IS THE ONE
  THAT FAILED FIRST.** The reasonable hypothesis was that the spine going from
  seven fields to eight pushed the outline over. Measured, it did not:

  | | acts | spine | output tokens | of the 16,000 ceiling |
  |---|---|---|---|---|
  | story 22 (fitted) | 7 | 7 fields | 5,198 | 32% |
  | story 23 run 1 (fitted) | 6 | **8 fields, hook live** | 5,575 | 35% |
  | story 23 run 2 (truncated) | 6 | 8 fields, hook live | >16,000 | 100% |

  **Run 1 already had the hook and finished at a third of the ceiling.** Same
  code, same story, same prompt, same act count, twenty-two minutes apart. The
  difference between 5,575 and 16,000+ is a 2.9x overshoot on identical input,
  which is the generation-variance item this file already has open — story 21
  overshooting its word target by 44% is the same defect, milder.

  The component sizes, measured on story 22's real output at 2.98 chars per
  output token:

  | part | tokens | of ceiling |
  |---|---|---|
  | one spine field (avg of seven) | 299 | 1.9% |
  | **the hook** | **~200-300** | **~1.9%** |
  | one act | 417 | 2.6% |
  | six-act body | 2,500 | 16% |
  | eight-field spine | 2,392 | 15% |
  | whole outline | ~4,900 | 31% |

  So the hook is about **one fiftieth of the ceiling** and roughly **6% of a
  typical outline**. It cannot account for a 3x overshoot, and the field was
  present in the run that fitted.

  **I nearly reported the opposite, from a timezone.** The app writes
  `render_jobs.started_at` in UTC and the file mtimes are +08:00, so the two runs
  (01:11 and 01:33 UTC) looked like they PREDATED the hook migration (08:22
  local) by seven hours, which would have made the field impossible as a cause
  for a different and wrong reason. Converted, both runs are 09:11 and 09:33
  local — after it. Same conclusion, opposite reasoning, and the wrong version
  was one sentence from being written down. **Comparisons must happen in one
  frame** is already this file's rule about centiseconds against milliseconds;
  it applies to clocks.

  **The remedy is therefore not a tighter hook schema and not splitting the
  call**, and the numbers say why rather than a preference:

  - **Re-run.** 31% mean occupancy with one observed excursion to 100%. Costs
    ~$0.15 and is the only stage where a re-run is a reasonable first move,
    which is what its remedy now says.
  - **Tighter hook schema** would recover ~300 tokens, 1.9% of the ceiling. It
    buys nothing against a 3x overshoot and would cost the beats the field
    exists for.
  - **Splitting the outline call** is what this file says about SCRIPTS, and the
    reason does not transfer: a 7,000-word script cannot fit one call at any
    ceiling, whereas an outline occupies a third of one. Splitting would add a
    second billed call and a coherence seam — the spine and the acts are written
    against each other — to solve a variance problem that a re-run solves for
    fifteen cents.
  - **Raising `ANTHROPIC_MAX_TOKENS_OUTLINE`** is available and is not free: the
    truncated call was billed at the ceiling, so a higher ceiling makes the
    failure mode more expensive rather than less. It is the third option, not
    the first.

- **`max_tokens` BOUNDS OUTPUT ONLY, so an eight-field spine moves no downstream
  stage toward its ceiling.** Worth stating because the question is natural and
  the answer is structural rather than lucky: the act-script call carries the
  outline in its PROMPT, and prompt growth is input, which `max_tokens` does not
  constrain.

  Measured on the real prompt builder: the hook adds 974 chars (~270 input
  tokens) to the ACT 1 prompt and **exactly zero** to act 2 — `hookInstruction()`
  runs only for act 1, and the act prompt's spine block carries four fields, not
  eight, with departure/reversal/refusal arriving per phase through
  `endingFor()`. It is a small input cost on one call per story.

  What the sweep did find is unrelated to the hook and worth knowing:

  | stage | ceiling | worst measured output | |
  |---|---|---|---|
  | `draft_scenes` | 16,000 | **14,031** | **88% — story 21 act 3** |
  | `generate_outline` | 16,000 | 5,575 | 35% |
  | `generate_act_script` | 16,000 | 2,636 | 16% |

  **`draft_scenes` is the stage actually close to truncating**, and it has been
  since before the hook existed. Its output scales with the ACT it is cutting up,
  which is upstream of anything that stage controls — so its remedy names the act
  length rather than any knob at the scene stage. A truncated scene list there
  costs the act call again.

- **A SPEND BUTTON THAT DOES NOT ACKNOWLEDGE THE CLICK IS ASKING TO BE PRESSED
  TWICE, AND EVERY PRESS IS FOUR BILLED IMAGES.** Found by using the cast panel,
  not by a check.

  Generation is synchronous on that screen for a good reason — the operator is
  sitting in front of it waiting to choose a face — and nothing said so while it
  ran. Measured across 62 real candidate images: **36 seconds per candidate at
  the median, 53 at the worst, so a four-candidate sheet holds the browser for
  about 144 seconds** with a live button and an unchanged row. That is
  indistinguishable from a click that never landed, and the reasonable response
  to it is another click.

  **Two halves, and they are not alternatives.**

  - The **acknowledgement**: `wire:loading` disables both controls and an
    `.alert.run` says what is running and roughly how long. It makes a second
    press unlikely. The targets are NAMED — a bare `wire:loading` fires on any
    request the component makes, so picking a candidate elsewhere would grey out
    a spend button that is not running.
  - The **claim**: an atomic lock, taken in `GenerateCharacterSheet`. It makes a
    second press impossible.

  **Component state cannot do the second job, and it looks like it can.** The
  obvious fix is to consume `$this->confirming` at the top of the handler.
  Livewire sends a serialised snapshot with every request, so two clicks fired
  before the first response arrive as two requests carrying the SAME snapshot,
  each with the flag still armed. Whatever the component believes, it believes
  twice. A cache lock is outside the request and is the only thing that can see
  the other one — and it also covers what no UI state could reach: a second tab,
  a second operator, and `characters:sheets` running in a terminal.

  **It lives in the Action, not the component**, because the console command is
  a caller the component cannot see. Guard upstream of the thing it distrusts.

  **It expires, and that is not a detail.** A lock with no expiry converts a
  crashed request into a character that can never be generated again, with
  nothing on screen explaining why — a worse failure than the one being fixed
  and silent in the way this project keeps paying for. 1,800s, an order of
  magnitude above the measured 144s sheet, and released in a `finally` so the
  ordinary path never waits for it.

  **The refusal is a NOTICE, not a problem.** It means the first press landed and
  nothing extra was bought. Rendering it red would tell an operator something
  went wrong when the opposite is true, and pressing twice was what the screen
  invited.

- **THE STALE ROW DID NOT REPRODUCE, AND THE REASON IT CANNOT IS THE USEFUL
  PART.** The report was that the sheet exists and the row still says NO SHEET
  until a manual reload. It does not, in any test I could build, and I can now
  say why: Livewire computed properties are cached **per request**, and each
  `call()` is its own request, so `cast()` is first evaluated during the render
  that FOLLOWS generation and re-queries by construction.

  That has a sharp consequence for the fix: **`forget()` cannot be what makes
  the badge update, and a drill proved it.** Deleting its body leaves both
  behaviour tests green. It is defensive, not load-bearing, and the tests are
  regression tests on user-visible behaviour rather than tests of the mechanism.
  Saying so in the docblock matters, because a passing test that cannot fail
  reads as coverage of something it never touches.

  What WAS wrong and is now fixed: `forget()` did not refresh `$this->story`
  itself. Both `EstimateCharacterSheets` and `ValidateCharacterSheets` are
  handed that instance, and neither can tell a stale loaded relation from a
  fresh one.

  Since the badge does follow a COMPLETED request, the reported symptom points
  at a request that did not complete — which is the first item's silence, seen
  from the other end. `max_execution_time` is 36,000 here so PHP is not killing
  it; a 144-second synchronous request is simply long enough for a browser, a
  proxy or an operator to give up on. **The durable fix is not to hold the
  request for 144 seconds at all**, and that is a real redesign of a
  deliberately synchronous screen, so it is named here rather than done.

- **`class-audit` CAUGHT `.alert.run` AS A `COMBO` THE MOMENT IT WAS WRITTEN.**
  `.run` existed only as `.badge.run` and as a progress-bar fill, so the first
  surface to need "this is happening" in alert form asked for a class the
  stylesheet answered only on a badge — and would have rendered as an ordinary
  alert with no accent. **That is `.panel.money` exactly**, caught during the
  work instead of a phase later, which is the argument for running the audits
  while building rather than after.

  It is a real gap rather than a naming slip: the console had no in-flight alert
  variant at all. `.alert.run` is defined now with `--run`, the status ramp that
  already means in-progress, measured at 1.183x light and 1.251x dark from the
  panel beside it — inside the band every other alert variant occupies.

  **And `theme-audit` immediately caught the token landing in the wrong block.**
  The dark remap is written twice on purpose, and my edit put both copies inside
  the `prefers-color-scheme` block, leaving `[data-theme="dark"]` without one —
  so an explicit toggle would have rendered the light tint on a dark ground at
  14:1. The audit reported `DIVERGED` and named the token. This is the "one
  mistyped hex in two hundred token lines" case the `--against` differ exists
  for, working on the first run after the change.

  The one `GONE` in the diff is the neutral fallback selector gaining
  `:not(.run)` — a rename with a matching `NEW`, declarations byte-identical,
  verified rather than assumed. The comma-merge lesson in reverse: `GONE` plus a
  matching `NEW` of the same rule is a rename, and only reading them together
  says which.

- **THE CANDIDATE COUNT IS ALREADY CONFIGURABLE EVERYWHERE EXCEPT THE SCREEN
  THAT SPENDS IT.** `characters.candidates` (env `CHARACTER_CANDIDATES`,
  default 4), `characters.max_candidates` (6), `--candidates=` on
  `characters:sheets`, and a `?int $candidates` parameter on the Action. The one
  caller that passes nothing is the Livewire component, so the operator sitting
  in front of the bill is the only person who cannot change it. **The
  console-audit shape again: a capability that exists with no button.**

  What it is worth, measured:

  | | story 9 | story 21 |
  |---|---|---|
  | sheets, at 4 candidates | $1.33 | $1.365 |
  | at 2 | $0.67 | $0.68 |
  | video total | $12.54 | $15.24 |
  | sheets as a share | 10.6% | 9.0% |

  So halving the count saves about **$0.68 per video, ~5% of the total** — real,
  and worth putting behind a control rather than an env var. It is not the
  biggest line on the video: `generate_image` is $6.51 and $9.45. It IS the
  biggest thing on THIS screen, which is what was asked.

  The cost of exposing it is small and the risk is worth naming: fewer
  candidates is a worse choice, not a cheaper one, and the sheet exists to find
  a face worth holding across 150-250 stills. A control that defaults to 4 and
  can be dropped to 2 for a minor character is the shape; a global default of 2
  would be a quality decision disguised as a saving.

- **NO THRESHOLD ON WHO GETS A SHEET, AND A THIRD OF THE CAST IS IN FIVE SCENES
  OR FEWER.** A character in 120 scenes and a character in 4 get the same
  offer, because the app has no notion of how much consistency is worth.

  Measured across 39 characters that appear in at least one scene:

  | scenes | characters |
  |---|---|
  | 1-2 | 0 |
  | 3-5 | 13 |
  | 6-10 | 11 |
  | 11-25 | 5 |
  | 26-60 | 5 |
  | 61+ | 5 |

  Median 8, mean 23 — a long tail with a heavy head. Story 9 spent $0.56 of its
  $1.33 on four characters appearing in 18 scenes of 186.

  What a threshold would look like, per story:

  | cutoff | story 9 skipped / saved | story 21 skipped / saved |
  |---|---|---|
  | < 5 scenes | 3 chars, $0.42, 12 scenes | 0 chars, $0 |
  | < 8 | 4 chars, $0.56, 18 scenes | 2 chars, $0.245, 10 scenes |
  | < 10 | 6 chars, $0.84, 35 scenes | 3 chars, $0.385, 19 scenes |
  | < 15 | 7 chars, $0.98, 45 scenes | 5 chars, $0.665, 46 scenes |

  **Where the number would have to come from, and it is not this table.** These
  are savings, and savings alone would argue for a very high cutoff. The
  question the threshold actually asks is *at how many stills does a
  description-only face drift visibly enough to matter*, and **nothing in this
  app has measured that.** The drift is the entire reason references exist —
  "a face drawn from text looks right on its own and drifts across the video,
  and the drift is not visible until every still has been paid for" — so a
  number picked off the cost column would be moving the measurement until the
  outcome passes, which is the pattern this file names at story 9's runtime.

  The honest experiment is cheap and has not been run: take one shipped story,
  generate the stills for a 4-scene character from the description alone, and
  look at them beside the same character's referenced stills. That is a handful
  of images against a `CostCategory::Evaluation` row — the category that exists
  for exactly this kind of question.

  Two constraints on any threshold, whatever the number:

  - **`MissingCharacterReferenceException` currently refuses a still whose
    character has no reference.** A threshold is not just a hidden button; it is
    a second legal state — "deliberately unreferenced" — and that refusal has to
    learn the difference between it and "nobody generated this yet". Otherwise
    the threshold silently blocks the scenes it was meant to make cheaper.
  - **The offer should get quieter, never disappear.** A character with no
    button is a character an operator cannot give a face to when the story turns
    out to need one, and this screen already has the right register for it: the
    unused-cast advisory says why no sheet is required rather than hiding the
    row.

- **THE FACES WERE INERT BECAUSE 77.5% OF PEOPLED FRAMES NEVER ASKED FOR AN
  EXPRESSION. Measured before and after, and the prompt rule was not what fixed
  it.** Found by watching a finished video; the stills read blank while the
  narration moved through betrayal, departure and refusal.

  The system prompt had asked for expression in prose since Phase 2 — *"who is
  in it, where they are, what their expression and posture are"*. Measured
  across 657 peopled frames in four finished stories, what actually arrived:

  | | before | after |
  |---|---|---|
  | mention a face at all | 44.4% | 84.8% |
  | **name what the face is DOING** | **22.5%** | **62.4%** |
  | no expression instruction at all | 77.5% | 37.6% |
  | overt affect (tears, shouting, trembling) | 1.4% | 3.2% |

  Measured with one parser throughout, on story 12 re-drafted whole. Two figures
  underneath that one: **114 of 125 peopled frames carry an expression block**
  (91.2%) — the 62.4% is the conservative reading, counting only frames where a
  fixed vocabulary recognises a face and a state in the same clause. And the
  11-frame gap is not a miss: those are wide establishing shots where the
  expression is deliberately suppressed. Over the frames actually eligible for
  one, the parser figure is **68.4%**.

  **Two changes were made and only one of them did anything**, which is why they
  were sequenced rather than shipped together:

  1. **A prompt rule banning hedged wording** — "slightly", "faintly", "barely"
     applied to a face, which the expression-axis run measured as rendering
     nothing at all. Free, correctly aimed, and it targets **3.2%** of peopled
     frames. Re-drafting one act against it moved nothing measurable, and could
     not have: at a 3.2% base rate an 18-frame act expects 0.6 instances.
  2. **`expression` as a REQUIRED field on the scene schema**, beside
     `motion_preset`, with the prose instruction replaced by an instruction to
     name the visible expression plainly. This is what moved 22.5% to 72.4%.

  The ratio is the lesson: the schema field's surface is **24x** the prompt
  rule's. This is the `antagonist_justification` argument holding a second time
  — *a model asked in prose for five things will reliably give four when one is
  awkward* — and the corollary that a prompt rule is worth what the field behind
  it is worth, which for a field that does not exist is nothing.

  **The hedge ban did NOT work and got worse, and that is not a rounding error.**
  Hedged expressions rose from 3.2% to 15.3% of peopled frames; per
  expression-bearing frame, from 14% to 21%. So the rule is being ignored at a
  slightly higher rate now that there are far more expressions to ignore it in.
  It is a prompt request with no mechanism — the shape this file names as the
  documented-guard defect — and the next step, if it matters, is a Gate 2
  advisory rather than a louder sentence. Left open deliberately: a hedged
  expression is a weak picture, not a wrong one, and the guard would be
  false-positive-prone in exactly the way `CharacterTextGuard`'s build rule is.

  **The expression is spliced into the FRAME section**, not added as a fifth
  block, so `ThumbnailFraming`'s shot scale, the narration-overlap check and the
  close-frame setting advisory all still see it. It is suppressed on cutaways —
  a frame with nobody in it that carried an expression would be describing a
  face the picture does not contain, and that is a quarter of a real story.

  **THE EXPRESSION GETS ITS OWN PROMPT SECTION, AND THAT REVERSES THE FIRST
  DESIGN ON MEASURED GROUNDS.** It was first joined into the FRAME section, on
  the reasoning that everything asking "how was this picture framed" reads the
  frame — `ThumbnailFraming`'s shot scale, the narration-overlap check, the
  close-frame setting advisory — so hiding it from all three would be a loss.

  The opposite was true, and one of the three was actively broken by it.
  `ThumbnailFraming::WIDE_MARKERS` contains `'wide'`, matched whole-word, so an
  expression reading "eyes wide" or "mouth wide open" classified its own frame
  as a WIDE ESTABLISHING SHOT. Measured on story 12: **6 of 98 peopled frames,
  and they were the best reaction shots in the story** — *"Beaming, eyes
  crinkled"*, *"her hand pressed over her mouth, eyes wide"* — each scoring -25
  for a thumbnail instead of +30. Exactly backwards, on the frames the thumbnail
  feature exists to find.

  ---------------------------------------------------------------------------
  **THE GENERAL FORM: ANYTHING APPENDED INTO A SHARED STRING BECOMES INPUT TO
  EVERY PARSER THAT READS THAT STRING, AND THE CALL SITE CANNOT SEE WHO THEY
  ARE.**
  ---------------------------------------------------------------------------

  This is not a `WIDE_MARKERS` bug and reading it as one would waste it. The
  marker list is fine; `'wide'` is a reasonable word for "wide shot" and it is
  matched whole-word. What went wrong is that a NEW FIELD was spliced into a
  string that three unrelated readers parse by keyword, and nothing at the
  splice point says so.

  `image_prompt` is not a value, it is a channel. Four things write into it —
  the frame, the expression, the cast block, the style — and at least four read
  it back out by pattern: `ThumbnailFraming` for shot scale,
  `ValidateSceneDrafts` for narration overlap and for the close-frame setting
  advisory, `ScenesGate::styleBlock()` for the stored art style,
  `ImagePromptBuilder::frameFrom()` for the frame itself. **A writer cannot
  enumerate its readers from where it stands**, so "append it to the frame, the
  readers want to see it" was a guess about four call sites made from one.

  The expression happened to contain a word one of them treats as a shot marker.
  It could as easily have contained "described exactly" and broken the cast
  check, or a narration word and inflated the overlap ratio. The specific
  collision is luck; the exposure is structural.

  What follows, and it is cheap:

  - **A new field gets its own section, not a splice into an existing one.**
    Sections are separated by a blank line and that convention is
    `ImagePromptBuilder`'s to state, so a section is addressable and a splice is
    not.
  - **Every section that can be read back gets a labelled inverse in the class
    that writes it** — `frameFrom()`, now `expressionFrom()`. A caller matching
    the label itself is a second copy of a string only one method writes.
  - **When splicing is genuinely wanted, name the readers in the change.** Same
    question as the DTO-consumer finding above, one layer down: who else parses
    this string, and does anything break if its vocabulary grows?

  An expression is a fact about the SUBJECT, not about the SHOT, which is why it
  was never frame content in the first place.

  **An expression is not spent on a shot that cannot show it.** Suppressed on
  cutaways, and on a wide establishing shot that names no face — story 12
  produced *"A modest single-story house on Ridgeline Drive seen from the street
  … brows drawn together"*, an expression on a building inside a 25-45 word
  budget. The second condition is deliberately narrower than "the shot is wide",
  because `WIDE_MARKERS` is tuned for a ranking where a false `wide` is cheap and
  here it would DELETE a real expression from a real close-up: 'empty' matched a
  print-shop counter and 'the street' matched a frame whose subject stands in it.
  A frame naming a face keeps its expression however the shot was marked. Read
  off the model's own `frame` field before assembly, so the rule cannot see the
  expression it is deciding about.

  **The hedge ban is a Gate 2 advisory now, not a sentence in a prompt.** It was
  shipped as a prompt bullet and measured: hedged expressions went from 3.2% to
  15.3% of peopled frames — 14% to 21% per expression-bearing frame — so the
  forbidden thing became five times more common while the rule was in place. **A
  prompt request with no mechanism reads as a guard while doing nothing**, which
  is worse than not asking, because the sentence looks like coverage. The prompt
  keeps the request and `checkExpressionsAreNotHedged()` is the invariant, which
  is the same split `CharacterTextGuard` uses. It reads the EXPRESSION BLOCK and
  never the frame: "dust faint on the drawer's edge" is not a hedged expression,
  and scoring the whole frame is what over-counted the original figure by 4x.

- **STORY 12 WAS A MIXED STORY AND IS NOT ANY MORE. It is the measurement
  target.** Kept on the record because the mixed state is the kind of thing that
  is invisible in the database and would otherwise be rediscovered.

  For one pass its acts were drafted under three different code versions — act 3
  under the hedge ban alone, the rest under the ban plus the `expression` field,
  and none of them under the section split. Two consequences, both real while it
  lasted: act 3 read 33.3% expression coverage against 72.4% elsewhere, which
  was **a code version and not an effect**; and 0 of 173 scenes carried a
  readable expression block, so `expressionFrom()` returned '' everywhere and
  the hedged-expression advisory **could not fire at all** — a check that cannot
  fire is indistinguishable from one that passed, on the story it was written
  from.

  All six acts were then re-drafted together, at 12 calls and $0.4664, and it is
  now one format throughout. The general rule the episode is worth remembering
  for: **when a generator changes under a story, the story is evidence about two
  code versions and neither cleanly.** Re-draft the whole thing or compare only
  against an external baseline, never act-to-act.

  Story 12 is at `scenes_drafted` with zero paid asset spend, which is what makes
  it the right target: text re-drafts cost cents and invalidate nothing.

- **WARDROBE TRAVELS WITH THE REFERENCE SHEET, AND IT WILL NOT FIX ITSELF WHEN
  EXPRESSION DOES.** Found in the expression-axis run, which was measuring
  something else. Every referenced rung came back in the sheet's tan jacket and
  beige shirt; the one unreferenced control came back in a white shirt. In story
  21 the same thing is live and visible: Lu Wenbin stands under a streetlamp at
  night in act 6 wearing the tan shirt from his reference portrait, and his
  `style_notes` say only "Plain collared shirts and dark trousers, sleeves rolled
  to the forearm" — no colour, no garment.

  **Same class as the close-frame setting bleed, different remedy.** Both are the
  edit endpoint filling a gap from the only picture it was handed. The setting
  case is fixed in the FRAME — name the room and the model stops reaching for the
  grey void — and `ValidateSceneDrafts::checkCloseFramesNameTheirSetting()` now
  reports it. Wardrobe cannot be fixed the same way without undoing the thing the
  sheet is for: a frame that re-describes the clothes per scene is a frame
  re-describing the character, which is what `ImagePromptBuilder` exists to
  prevent.

  Why it matters beyond tidiness: this format runs 30-40 minutes across weeks or
  years of story time. A narrator who wears one shirt from the betrayal to the
  refusal reads as a single afternoon, and the departure — the structural centre
  of the arc — is the moment a change of clothes would carry the most.

  Three shapes it could take, none built and none obviously right: a per-scene
  wardrobe field on the scene (a second thing for the operator to review, 150-250
  times); a per-ACT wardrobe line, since acts already map to time and phase and
  there are only six or seven of them; or a reference sheet deliberately drawn in
  neutral clothing so there is less to bleed. The last is the cheapest to test
  and the only one that costs nothing per scene — and it is a `reference_frame`
  edit, which is inside `StyleFingerprint` and therefore stales every sheet.

- **THE PROGRESS PAGE COULD NOT SEE A BATCH THAT HAD NOT STARTED, AND THE CANCEL
  PATH COULD. FOURTH INSTANCE OF A FIX APPLIED AT ONE CALL SITE.** Story 23 sat
  at `assets_generating` with **550 jobs live on the assets queue** and
  `/renders/{slug}` said *"No batches recorded for this story"* and showed no
  asset stage at all.

  Two separate causes, both structural, and only one of them is now fixed:

  1. **No asset stage.** `RenderJob::open()` runs INSIDE the job, so a queued
     job has no row. 550 queued, 0 started, 0 rows, empty stage list. This is
     false-success row 7 showing its other face — there a partial run read as
     complete, here a full backlog reads as nothing at all. **Unfixed**, and
     genuinely hard: the stage list is built from `render_jobs`, and the thing
     it needs to count has not written one.
  2. **"No batches recorded".** `RenderProgress::batches()` resolved batch ids
     ONLY through `render_jobs.batch_id`, so no rows meant no ids meant an early
     `return []`. **Fixed**: it now also matches `job_batches.name` against
     `scene-assets:{slug}` and friends.

  **The sharp part is that `CancelRenderBatch::batchIds()` has had exactly that
  fallback all along.** Cancelling worked on the batch the page could not
  display, because the cancel path already knew that an unstarted batch has to
  be found by name. One author, two call sites, one of them taught. That is the
  same shape as `escalation_beat` reaching `GenerateActScripts` and not
  `DraftScenes`, and as the migration lesson that reached `category` and not
  `unit` — **a fix applied at one call site reads as covered.**

  `BATCH_PREFIXES` is shared from `CancelRenderBatch` rather than retyped, so
  the two cannot drift. The one deliberate DIFFERENCE is that the page does not
  filter out finished or cancelled batches: cancelling wants what it can still
  stop, and a progress page wants the history, because a batch somebody called
  off is the thing an operator most wants to see rather than the thing to hide.

  Both halves are drilled in `RenderProgressPageTest`, and the fixture writes NO
  `render_jobs` rows on purpose — that absence IS the defect, and a fixture that
  wrote one could not express it.

- **A WORKER THAT SAYS CURRENT AND DOES NOTHING — THE STATE THE WORKER PANEL
  EXISTS FOR, AND IT WAS INVISIBLE. NOT DIAGNOSED.** Recorded because it is
  unexplained, not because it is understood.

  Story 23's asset batch was dispatched at 10:28 UTC. At 10:37 the assets worker
  was alive (pid 16492), heartbeating 40 seconds old, code marker matching disk,
  5h54m uptime — and it had consumed **zero of 550 jobs**. `WorkerHealth`
  reported, in its own words:

  > *1 worker(s) on "assets" agree with this process, working through 550 queued
  > job(s).*

  Every clause is true and the sentence as a whole is false. It is assembled
  from two facts — a heartbeat exists, and a queue has depth — **neither of which
  is evidence that a job was ever consumed.** That is the same construction as
  the "118 stills done, nothing failed" page: right numbers, wrong state.

  **What was ruled out**, so the next person does not repeat it:

  | hypothesis | evidence against |
  |---|---|
  | misconfigured queue name | NSSM `AppParameters` reads `queue:work redis --queue=assets --tries=3 --max-time=32400` |
  | recycled at `--max-time` | 5h54m uptime against a 9h ceiling |
  | stale-code stand-down | disk marker `cba9dab8…` matches the worker's |
  | restart loop | pid stable across the whole window |
  | jobs failing | `worker-assets.log` has no entry since 2026-09-03; `failed_jobs` none since 09-05; batch `failed_jobs` = 0 |
  | job stuck mid-flight | `queues:assets:reserved` does not exist — nothing was ever picked up |

  So: correctly configured, alive, current, not looping, not failing, not
  holding anything — and not working.

  **A RESTART FIXED IT, AND `--max-time` WAS NOT THE REASON.** The worker was
  restarted and the 550 jobs drained to zero within minutes — nothing bought, no
  new `failed_jobs`, because the batch had been cancelled first and the framework
  skips a cancelled batch's jobs. So the restart is confirmed as what unblocked
  consumption.

  The obvious explanation was that it had passed `--max-time` and become the
  documented exit-and-never-return worker. **It had not.** The assets service
  runs `--max-time=32400`, which is nine hours; the process had been up **5h
  54m**, about two-thirds through its window. The arithmetic rules it out, and
  it is worth stating because the hypothesis is extremely plausible and wrong —
  `--max-time` exhaustion IS a real failure here (story 21's assets worker
  exited at `--max-time=3600` and stalled a 270-scene run), which is exactly why
  it is the first thing anyone will reach for the next time this happens.

  **The mechanism is still unknown and the evidence is now gone**: the process
  was replaced and the queue drained, so the state cannot be inspected. The
  remaining hypothesis nothing has tested is a Redis connection that had gone
  half-open across ~6 idle hours, where a poll returns empty forever while the
  worker loop keeps ticking and heartbeating — which would fit every observation
  above, and is a guess.

  **Do not name the state after the diagnosis.** The panel needs a word for what
  was OBSERVED — a worker listening and taking nothing — not for a cause that
  turned out to be false. See below.

  **What the panel would need to say it, and why it could not.** Every figure on
  it was a LEVEL — a heartbeat age, a queue depth, an uptime. The state that
  actually occurred looks like a RATE: a fresh heartbeat while the depth does
  not fall. The panel had no memory of a previous reading, so it structurally
  could not express "listening and taking nothing"; it could only say a worker
  is there and a queue is deep, which is what it said, and which read as
  healthy.

  That is the third thing this panel cannot distinguish. The 2026-09-03 entry
  says it exists to tell *nothing left to do* from *nobody doing it*, and
  `stranded` covers depth-with-nobody-listening. The gap is
  **depth-with-somebody-listening-and-not-consuming**, and it is the worst of
  the three because every component reads healthy.

  **`oldest_boot` is read and displayed and nothing branches on it**, which is
  worth noting separately: a worker's position within its `--max-time` window is
  known to the panel and used for nothing. Surfacing "4h left of 9h" is cheap
  and defensible on its own merits — it just was not this defect, and building
  it as though it were would be naming a state after a refuted diagnosis.

  ---------------------------------------------------------------------------
  **THE SIGNAL, NOT THE DIAGNOSIS. `Looping` FIRES ON THE EMPTY POLLS, SO A
  FRESH HEARTBEAT HAS NEVER MEANT WORK IS BEING CONSUMED.**
  ---------------------------------------------------------------------------

  This entry used to close "deliberately not built, waiting for the second
  instance". Half of that judgement was right and half of it conflated two
  different things, and separating them is what made the state buildable
  without guessing at a cause.

  **The cause is still unknown and nothing below claims otherwise.** What was
  found is not why the worker stopped consuming. It is why the panel could not
  SEE that it had:

  `AppServiceProvider::announceWorker()` heartbeat on `Looping`, and Laravel
  dispatches `Looping` on **every poll of the queue, including the empty ones**.
  So `live = 1`, `state = ok` and a fresh heartbeat all mean exactly one thing —
  the loop is turning — and not one of them has ever meant a job was taken. The
  panel's central signal could not tell working from idling with a full queue.
  That is a fact about the instrument, checkable against the framework, and it
  needed no theory about Redis or `--max-time` at all.

  **And it is not a RATE, which is why no cross-request memory was needed.**
  The first reading of this said the panel would have to remember a previous
  depth. It does not: it needs a second CLOCK. `JobProcessing` now writes
  `last_job_at` beside the heartbeat, so "heartbeat fresh, depth > 0, last job
  old" is a pure read of one snapshot. The temptation was to have a page
  remember what it saw last time, which would have made every reading depend on
  when the page was last loaded — on a page that deliberately stops refreshing
  itself when nothing is running.

  **Two clocks, because one cannot do it.** `seen_at` is ticked by a poll, by a
  job start AND from inside a long job by `RenderJob::heartbeat()` — which is
  precisely what makes it a good liveness signal and a useless activity one. So
  `looped_at` is written only by a poll and `last_job_at` only by a job start.
  A worker polling and taking nothing has a fresh poll and an old job start; a
  worker forty minutes into a mux has the reverse and reads as busy, correctly.
  If the in-job beat ever claimed to be a poll, this alarm would fire on the
  longest thing the pipeline does — there is a test for exactly that, and a
  drill that turns it red.

  **`WorkerHealth::NOT_CONSUMING`, badged "taking nothing", and the name is the
  careful part.** It says what a reading can support and nothing more. It is not
  `wedged`, not `half_open_redis`, and not `max_time_exhausted` — that last one
  is the plausible name that was nearly used and is REFUTED for this incident,
  because the worker was 5h54m into a 9h window. **A state named after a
  diagnosis that turns out to be wrong is worse than one named after the
  symptom, because the name then argues against the next investigation.**

  **An entry with no clocks is UNKNOWN and is not accused.** A worker that
  booted before this signal existed cannot answer, and reporting it as taking
  nothing would be inventing a reading — the over-report that retires a
  detector. It is not absence read as agreement either: such a worker booted on
  code that no longer matches disk, so it is already STALE, which is checked
  first and is louder. The blind window is one worker restart long.

  **Rehearsable.** `WORKER_POLL_FRESH_SECONDS` (60) and
  `WORKER_JOB_IDLE_SECONDS` (120) are env-tunable for the same reason the stale
  threshold is: an alarm nobody can trigger on purpose is not an alarm. They are
  tuned against the OBSERVATION and not against a cause, so if the cause is ever
  found the numbers may want revisiting and the name should not.

  **What is still open, stated so it is not read as closed.** Why that worker
  stopped consuming has never been established. The process was replaced to
  unblock the pipeline and the evidence went with it; the surviving hypothesis
  — a Redis connection gone half-open across ~6 idle hours, where a poll returns
  empty forever while the loop keeps ticking — fits every observation and is a
  guess. Nothing in the code says it. **An unexplained failure written down as
  unexplained is worth more than a plausible name**, because the name is what
  the next person will test instead of looking.

- **THE GUARD THAT WOULD HAVE CAUGHT IT EXISTS, ASKS EXACTLY THE RIGHT FIVE
  QUESTIONS, AND NOTHING CALLED IT. THE CONSOLE-AUDIT SHAPE LANDING ON THE MONEY
  BUTTON.** This is the sharper of the two findings from story 23 and it leads,
  because the second one is a missing check and this one is a check that was
  already written, already correct, and already free.

  `narration:preflight` has asked five questions since Phase 2, none of which
  spends anything:

  | | the question | what it catches |
  |---|---|---|
  | 1 | what is ACTUALLY bound, resolved from the container | the run where a missing `PROVIDER_IMAGE_GENERATOR` sent 186 stills to a stand-in while every screen named a vendor and $8.12 went into the ledger against calls nobody made |
  | 2 | is the voice real | a null `voice_id`, and an id that is not on the account |
  | 3 | does it FIT | the allowance question a cost estimate structurally cannot ask |
  | 4 | does the aligner run | the free stage that breaks second, after the paid stage it depends on |
  | 5 | do the workers agree | a `.env` edit that has not reached a running worker |

  Its own docblock states question 2 as a hard block, in these words: *"The story
  has no voice_id. Run `php artisan voices:list --set=… --voice=<id>`."*

  **Grepping `app/` and `resources/` for a caller returns nothing.** Not one. A
  terminal command, on the app built so an operator would not need a terminal,
  guarding the button that authorises 150-250 paid stills and a narration run.

  That is the console audit's own shape — a capability that exists with no
  button — but it is the worst instance of it found so far, and the reason is
  the direction. Every earlier one was a capability an operator could not
  REACH: `story:write` and `render:dispatch` had no button, so the work could
  not be started without a terminal. This one is a GUARD. A missing button on an
  action means the work does not happen. A missing button on a guard means the
  work happens **unguarded**, which looks exactly like everything being fine.

  **What story 23 paid for it.** Gate 2 approved, assets dispatched, 256 stills
  bought, and then 257 identical `scene_narration` failure rows in five seconds
  — every one of them a worker picking up a job, loading a story, and
  re-discovering that `voice_id` was null. Question 2 would have said it once,
  for free, before anything queued.

  **Both halves were built, and they are not alternatives.**

  - Questions 1-3 now run INSIDE `PreflightAssetDispatch`, so pressing Generate
    cannot skip them. 4 and 5 were already there.
  - Gate 2 gained a **Check without spending** button that runs the same Action
    and prints what it says. That is the other half of what the command was for:
    the questions asked before committing, rather than as a condition of
    committing.

  **The same Action, not a second copy of the questions.** A check that agreed
  with the dispatch only on the day it was written is the shape that gave one
  narration three prices.

  **And the money press was not catching the refusal.** `alignTimings()` and
  `draftScenes()` both caught `DispatchRefusedException`; `generateAssets()` did
  not — so every refusal the preflight could already raise reached the operator
  as a stack trace on the one screen where the message IS the remedy. Those
  refusals run to several paragraphs each and name the exact fix, and none of it
  was being read. It went unnoticed because all three existing refusals need a
  broken machine to fire and the button is pressed on a working one; a missing
  narrator is an ordinary state, so the gap would have started firing
  immediately. Found by building the checks, not by a test.

  **What the live run reported**, first time out, on the scene-246 retry:

  ```
  OK — 1 worker(s) on "assets" agree with this process (fingerprint c84c4529ca8b).
  OK — narrator "Brian - Deep, Resonant and Comforting" is on the elevenlabs account.
  OK — narration fits: 88 credits needed, 27,953 remaining (starter: 37,047 of
       65,000 used, NO overage (generation stops at the limit)).
  OK — pace expectation for Brian on en-CN: 199 wpm.
  OK — whisperx imports on C:\Python312\python.exe (Python 3.12.10).
  OK — every approved face was drawn in the configured art style.
  ```

  The allowance line is the one that could not be got any other way. For the
  full 257-scene run it reads **21,350 credits needed against 27,953 remaining**
  — it fits, by 6,603, on a plan where running out does not bill extra but
  simply stops. No cost estimate can produce that number: on a subscription the
  marginal answer is $0.00 on both sides of the limit.

- **THE PREFLIGHT CHECKED WHETHER THE ENVIRONMENT HAD MOVED AND NEVER WHETHER
  THE STORY COULD FINISH. A NULL COLUMN BECAME 257 FAILURE ROWS.** The second
  finding, and it is the axis one — see PRECONDITION in the axis table.

  Story 23, 2026-09-06. `stories.voice_id` was null. The operator dispatched;
  256 stills were bought; then `scene_narration` failed **257 times in five
  seconds**, every row carrying the identical message. Nothing was billed for
  the narration — `GenerateSceneNarration` refuses above the `synthesize()`
  call and that guard is correct — and being DOWNSTREAM is exactly what made it
  257 refusals instead of one.

  What it cost instead of money: 257 `render_jobs` rows, 257 `failed_jobs`
  entries, and a batch left at `total=514, failed=258, finished_at=NULL` whose
  completion callback could never fire.

  **The field was already in the preflight's hand, and the check asked the wrong
  question of it.** This is the part worth keeping, because the comfortable
  reading — "the preflight could not see `voice_id`" — is false.
  `reportPaceExpectation()` reads `$story->voice_id` and passes it to
  `NarrationPace::unmeasured()`, which cannot distinguish *voice set but
  unmeasured* from *no voice at all*. On that very dispatch it emitted:

  ```
   has no measured reading pace for en-CN, so the pace guard cannot judge this run.
  ```

  **The sentence begins with a space**, because the voice name interpolated to
  nothing. An absent narrator was classified as a MEASUREMENT GAP, which is a
  warning by design and correctly so — so the one check holding the field turned
  a provable refusal into something to scroll past. The blank was the visible
  tell and nobody read it.

  **The roster of what else is in that class**, all knowable before a single job
  is queued, all previously discovered per-job:

  | knowable from | now | if it had been missed |
  |---|---|---|
  | `voice_id` null — one column | REFUSE | 0 spend, batch stranded — happened |
  | `voice_id` not on the account — one free `GET /voices` | REFUSE | 0 spend, 422 per scene × 3 tries, reads like an outage |
  | allowance < outstanding characters — one free quota call | REFUSE | **half-narrated story, allowance gone either way** |
  | speech / stills / timings produced by a stand-in | WARN | the $8.12 phantom-spend shape |

  Deliberately NOT in the class, so the fix is not read as wider than it is: a
  cURL timeout on one still (transient by nature — scene 246 took one), and
  *"scene has no audio to transcribe"*, which is an ordering dependency INSIDE
  the batch, since narration and timings are dispatched together.

  **The refuse/warn split follows the rule this file already had**, which is
  stale-refuses / unknown-warns:

  - **Null voice, wrong voice, short allowance → REFUSE.** Each is a POSITIVE
    reading: the stage provably cannot complete and no reading of the situation
    makes it fine.
  - **Vendor unreachable, allowance unreadable → WARN.** A check that did not
    RUN is a failed check and never a passed one — but the failure is in the
    instrument, not in the story, and refusing would turn an ElevenLabs outage
    into a refusal to spend on IMAGES. `SpeechQuota::accommodates()` already
    returns null rather than true for exactly this.
  - **A simulated provider is never refused.** Running against fakes is how
    every fixture story here was made and how the render pipeline was proven
    without spending a cent. A guard that has to be switched off to do ordinary
    work is a guard that ends up switched off. It is reported, loudly, and not
    refused.

  **SCOPED TO THE STAGES IN THE DISPATCH**, the way the aligner check already
  was. An images-only run must not be refused for a missing narrator: that is a
  refusal about work that is not being done, and it is how an operator learns to
  reach for `--no-*-check` by reflex. This has its own test and its own drill.

  **Two vacuous fixtures were caught while building it, one by an assertion
  written for the purpose and one by a drill.**

  - The images-only fixture gave every scene audio and timings and STILL read as
    needing narration, because `needsNarration()` keys on
    `approved_narration_hash` and the fixture never set it. It was caught by an
    assertion inside the fixture asserting it had produced the state it claims —
    which is the practice this file arrived at after `queueDepthIs()`, applied
    ahead of the failure this time rather than after it.
  - `test_a_simulated_synthesizer_is_never_refused_for_its_voice` was written
    with `narrator-us-01`, which is ON the fake's voice list — so it passed
    whether or not the simulated skip existed. The drill proved it: removing the
    skip left that test green while a neighbouring case went red. **Suspect the
    drill, and then suspect the fixture.** It uses a real ElevenLabs id now, and
    asserts that id is absent from the fake's list before relying on it.

  Seven drills, each confirmed red against the shape it names.

- **A NULL DEFAULT IS NOT NEUTRAL. `providers.default_voice_id` IS BRIAN.**
  The narrower half of the story-23 finding, and it has now been wrong in both
  directions, which is why both are on the record.

  It was `narrator-us-01`, hard-coded — a string `FakeSpeechSynthesizer`
  invented so it had something to record, on no vendor, carried by every story
  in the database, with no picker anywhere to disagree with it. The fix for that
  was NULL, on the argument that a narrator should be a deliberate pick.

  **Correct about the placeholder and wrong about null.** This file's own
  sentence is that a channel keeps ONE narrator across every video — so "no
  narrator" is not a state a story is ever meant to rest in. It is a trap laid
  on every new story, defused by hand or not at all, and story 23 is what it
  looks like when it is not: a paid dispatch with 256 stills already bought and
  no voice to narrate them with.

  Brian, `nPczCjzI2devNBz1zQrb`, because it is the only voice on the account
  with a measured reading rate at all — 197.00 wpm on en-US and 199.49 on en-CN
  — so it is also the only default that does not put a new story on the 160 wpm
  fallback the sizing correction exists to escape.

  Three things it deliberately does NOT do:

  - **It does not backfill.** `CreateStory` reads the config at insert and
    nothing re-reads it. Every existing story keeps whatever it was given;
    `voices:list --set` moves one.
  - **It does not become a picker.** A dropdown on the new-story form would
    invite a per-story choice on the one axis meant to be constant, before there
    is a script to choose for.
  - **It is not written silently.** The new-story form prints the narrator the
    story will be created with, its measured rate on the chosen setting, and
    that it can be changed until the narration is bought. A default nobody chose
    and nobody can find later is how `narrator-us-01` survived a phase.

  **If the configured id is not on the account.** Nothing validates it in
  config — a config file cannot ask a vendor anything — and before this change
  that would have been WORSE than null: null refuses in `GenerateSceneNarration`
  with a sentence naming the fix, while a wrong id reaches ElevenLabs and comes
  back 422 per scene, three times each under `--tries=3`, reading like an outage.
  It now refuses at the dispatch instead, once, against the account's real list.
  **The two changes are coupled on purpose: this default is safe to set because
  that guard exists, and it would not have been before.** The form also says it
  cannot tell from where it stands whether the id is on the account, rather than
  printing the raw value as though it were fine.

  **Changing a story's voice stays cheap until it is not.** `voices:list --set`
  validates against the real list, and warns and asks for confirmation only when
  PAID narration already exists — checked on `narration_simulated` provenance
  rather than on "any audio", so a story narrated end to end by a stand-in moves
  freely. After a real narration run, switching makes every paid scene stale and
  re-bills it, which is correct: a story must not be narrated by two people.

- **THE PANEL'S RESTART ADVICE NAMED A BINARY THAT IS NOT ON PATH, IN THE ONE
  PLACE IT IS READ: WHILE THE PIPELINE IS STOPPED.** It printed `nssm start
  NarraText`, and `nssm` is not on PATH on this machine. It failed on the
  operator mid-incident.

  **The app already knew.** `scripts/install-worker-services.ps1` resolves nssm
  three ways — `Get-Command`, then a vendored copy under `tools`, then a
  download from nssm.cc — precisely because it is not assumed to be there. The
  panel did none of the three. That is the same shape as the truncation message
  naming `ANTHROPIC_MAX_TOKENS`, a variable this app does not read: **a remedy
  that is confident, specific and does nothing**, which is worse than no remedy
  because it spends the reader's attention and returns an error about the wrong
  thing.

  **`start` was also the wrong VERB for half the states offering it**, and that
  is the sharper half. A worker that is polling and taking nothing is a service
  that is RUNNING; `nssm start` reports it already running and changes nothing.
  So the command was about to be printed as the fix for a state it cannot fix.
  `Restart-Service` is correct in both directions, is a PowerShell builtin so
  there is no PATH question at all, and is what actually unblocked the one
  observed instance.

  **The mapping was written out twice and the copies were not equal.** Both
  `components/worker-health.blade.php` and `livewire/dashboard.blade.php` held
  the same three-row queue-to-service map, and the dashboard's additionally
  invented a name for anything it did not hold:

  ```php
  'Narra'.ucfirst($queue)
  ```

  **That fallback is the worst of the options available, and not because it is
  usually wrong — it is usually RIGHT.** The three services really are
  `NarraText`, `NarraAssets`, `NarraRender`. Rename a queue in config, or add a
  fourth, and the band prints a confident pasteable command naming a service
  that does not exist. A plausible name that happens to be right today is a
  guess wearing the clothes of a fact — the same substitution as `updated_at`
  standing in for a publication date, which was also the right TYPE and also
  right-looking on the day.

  `App\Support\WorkerServices` owns it now, keyed by ROLE and resolved through
  `render.queues.*` in one direction so a renamed queue keeps matching its
  service instead of falling off the map. **An unmapped queue gets NO command**
  — the state is still reported in full, only the fix is withheld, because there
  is not one to give. Rendered through one `x-worker-restart` component rather
  than a literal per surface, which is what let the two copies disagree.

  **The no-command branch is DEFENSIVE and cannot currently be reached, and
  that is written down rather than left as a green test reading like coverage.**
  `WorkerHealth::all()` builds its three rows from the three role keys, so no
  fourth queue can appear there, and renaming one keeps its service by
  construction — the map is keyed by role and resolved through config in one
  direction. The branch is therefore tested at COMPONENT level, not page level.
  The first attempt tested it by renaming `render.queues.assets` and expecting
  the page to lose its command, which is exactly backwards and would have been
  a passing test about a state the page cannot enter.

  The copy that is NOT consolidated is named rather than left to be discovered:
  the installer is PowerShell, creates the services, and cannot read a PHP
  class. If a service is renamed, both move.

- **AN ESCAPE CONSUMED IN TRANSIT: FOUR INSTANCES, ONE CAUSE. READ THE CAUSE,
  NOT THE SCANNER.**

  ---------------------------------------------------------------------------
  THE CAUSE, WHICH IS THE ONLY PART THAT GENERALISES
  ---------------------------------------------------------------------------

  **When source is generated through a substitution layer, a backslash escape
  can be consumed BEFORE the file is written. What lands is valid, compiles, and
  is wrong in a way no reader can see.**

  Measured directly rather than reasoned about. Writing a PHP fixture through a
  `python3 <<'PY'` heredoc, the correct way to emit a literal backslash is
  `"\\b"` — and what the interpreter received was `\b`, which it then parsed as
  the BACKSPACE control character:

  ```
  "x\\b y"  ->  repr 'x\x08 y'
  ```

  So the doubled backslash was collapsed by a layer between the author and the
  interpreter, and the interpreter then did the obvious thing with what was left.
  Every instance below is that one mechanism. **The byte is the symptom; the
  generated-source pipeline is the defect.**

  Two rules follow, and they are worth more than the scanner:

  1. **Prefer an exact-match editor over a generated patch for any source line
     containing a backslash.** Every failed patch in this area was a generated
     one; every successful fix was an exact string match. This is not a
     preference — the generated form is a different program from the one written.
  2. **Build the character from `chr(92)` when a fixture must CONTAIN a literal
     backslash.** The byte-scan fixtures do exactly this, which is why they are
     correct by construction rather than by inspection — the first draft of the
     "clean" fixture had a 0x08 in it, put there by the very mechanism it was
     written to demonstrate.

  ---------------------------------------------------------------------------
  THE FOUR INSTANCES
  ---------------------------------------------------------------------------

  | # | where | what it broke |
  |---|---|---|
  | 1 | `tools/blade-php-scan.php`, widening a rule to blade directives in foreign comments | matched nothing; the known-answer count went 2 -> 1 and named it |
  | 2 | `app/Actions/ValidateSceneDrafts.php`, the close-frame setting advisory | matched nothing, which for THAT check means EVERY close frame reported as naming no setting — an over-report in the loudest direction |
  | 3 | the "clean" half of the byte-scan fixture itself | the fixture written to prove the tool's negative case contained the defect |
  | 4 | `tests/Feature/Providers/NarrationProviderTest.php`, 4,800 literal NUL bytes as a fake audio payload | nothing — it WORKED, and was unreadable. Rewritten as `"\x00\x00"` |

  Instance 2 happened AFTER instance 1 was written down in this file, which is
  the sharpest thing here: **a hazard recorded as a note is not a mechanism.**
  Instance 3 happened while building the mechanism. Instance 4 was pre-existing
  and was found by the mechanism on its first run.

  **What would actually catch it, stated plainly because the honest answer is
  "not much".**

  - **A test would not.** The red/green pair was written after the fix and passes
    either way at the RED end; only the GREEN halves would have failed, and only
    if they existed at the time. They did not — the byte was in the file before
    any case was written for it.
  - **Reading the diff would not.** 0x08 renders as nothing in every editor, in
    `git diff`, and in this file. The two versions are visually identical.
  - **`php -l` would not.** The regex is well-formed.
  - **What DID catch it, both times, is piping the line through `cat -A`** —
    which renders it as `^H`. That is a habit, not a mechanism, and it only fires
    if somebody already suspects the line.

  **`tools/nonprintable-scan.php` is now that check**, and it is the one tool
  here whose case for existing is complete rather than argued: the byte is
  invisible to every other instrument in the toolchain, and the list above is
  the proof. `ToolsAnswerKnownCasesTest` runs it two ways — against a paired
  fixture, and against the whole tree with an assertion of zero.

  **It found a fourth instance on its first run against the tree**, and a
  pre-existing one: `NarrationProviderTest` carried 4,800 literal NUL bytes as a
  fake audio payload. That one WORKED — it is a binary blob, not a broken regex
  — which is the closest thing this tool has to a benign case, and the fix was
  still to write it as `"\x00\x00"`. A reader of that line could not previously
  tell what was in the string, and the escape form is identical at runtime. The
  tool keeps no benign category as a result: there is no legitimate reason for
  an invisible byte where a visible escape says the same thing.

  The scanner is the backstop, not the lesson. What prevents a fifth instance is
  the two rules at the top of this entry; what the scanner does is notice when
  they were not followed.

  The general form, which is the part that generalises past this byte: **an
  escape that is consumed by a layer between the author and the file produces
  code that is correct in the author's head, valid to the parser, and wrong in a
  way no reader can see.** Generating source through string substitution is where
  it happens. Prefer an exact-match editor over a generated patch for anything
  containing a backslash.

Still open, none blocking, all findable here rather than one gate at a time:

- **NOTHING STOPS A GATE APPROVAL AND AN ASSET DISPATCH WHILE A DRAFT OF THOSE
  SAME SCENES IS IN FLIGHT, AND THE DRAFT THEN DELETES THE ROWS THAT WERE PAID
  AGAINST. Reported, not built — the shape is the decision and it belongs to
  the operator.**

  The instance, story 23, 2026-09-06. All 275 scenes were being re-drafted so
  their prompts would carry the current expression shape. While that ran, Gate 2
  was approved and assets were dispatched. Four stills were bought against the
  OLD prompts at $0.14, the re-draft then deleted those scene rows, and the
  files were left on disk referencing scene ids that no longer exist.

  **The collision is visible in `render_jobs` and nothing looked.** Job 4706,
  stage `draft_scenes`, ran 10:43:42 to 11:01:11. Jobs 4709-4712, stage
  `images`, ran 10:53:25 to 10:58:00 — an asset stage opening and closing
  entirely INSIDE a text stage's window, on the same story. `DraftScenes` opens
  that row through `RenderJob::record()` at the start and heartbeats it, so a
  live draft is an ordinary `running` row that any dispatcher could read.

  **The two shapes, and they are not equivalent.**

  1. **A running draft refuses the dispatch.** The check sits in
     `PreflightAssetDispatch`, which already runs BEFORE any mutation and
     already throws `DispatchRefusedException` rather than warning — the
     placement this file argues for at length, and the reason `narration:
     preflight`'s advisory version was not enough. It would read the open
     `render_jobs` rows for `draft_scenes` and `extract_cast` and refuse while
     one is `running` with a live heartbeat.

  2. **A running draft refuses the gate APPROVAL too.** Wider, and it is a
     different claim: approving Gate 2 is a quality decision about a scene list,
     and approving a list that is being rewritten underneath you is meaningless
     whether or not anybody then spends. `ApproveScenesGate` would refuse the
     same way it already refuses an unreferenced cast.

  **They stack rather than compete, and the ORDER matters.** Non-negotiable #3
  says approving a gate and dispatching paid work are separate decisions with
  separate buttons, so a guard on only the approval leaves the money path open
  on a story already past Gate 2 — which is the more expensive half and is the
  half that actually bought something here. A guard on only the dispatch leaves
  a gate crossing standing on a scene list that no longer exists, and a gate
  crossing cannot be re-crossed.

  **My recommendation is to build the dispatch guard first and the approval
  guard second**, because the dispatch one is where the money is, it is a read
  of a table the preflight is already positioned in front of, and it needs no
  new state. But this is the operator's call and the shape is what was asked
  for, so neither is built.

  **What NOT to do: a lock.** The obvious version is a claim in the cache, like
  `SheetClaim`. It is the wrong instrument here — the fact is already in the
  database, durably, with a heartbeat on it, and a second source of truth about
  whether a draft is running is how the two come to disagree. Read the row.

  **And the heartbeat is what makes it safe.** A refusal keyed on `status =
  running` alone would jam permanently on a draft whose worker died. `RenderJob`
  already has `staleHeartbeat()` for exactly this, and the refusal should use
  it: a draft that has gone quiet is not a draft in flight, and refusing for
  ever on a corpse is how a guard gets disabled within a week.

  **THE THIRD DEFECT, AND THE ONE THAT LEFT A STORY LYING ABOUT ITSELF:
  `DraftScenes::persist()` TRANSITIONS ONLY FROM `scripted`.**

  ```php
  if ($story->status === StoryStatus::Scripted) {
      $story->transitionTo(StoryStatus::ScenesDrafted);
  }
  ```

  Correct for every case anybody had in mind, and silent for the one that
  happened. The story entered the draft at `scenes_drafted`, moved to
  `scenes_approved` and then `assets_generating` while it ran, and when
  `persist()` finished the condition was false — so it wrote 257 fresh scenes
  and left the status saying an asset run was in flight. Story 23 sat at
  `assets_generating` with **zero** scenes carrying an `image_path`, which reads
  on every operator surface as a run in progress and is a run that cannot exist:
  the rows it would have been generating for were deleted by the same
  transaction.

  It is not obvious what the right behaviour is, which is why it is here rather
  than patched. Forcing `scenes_drafted` unconditionally would be a text stage
  silently reversing a gate crossing — and `assets_generating` -> `scenes_drafted`
  is a legal edge precisely so a HUMAN can take it. Refusing to persist at all
  would throw away a completed draft that has already been billed. **The honest
  third option is that the collision should not have been reachable**, which is
  what the two guards above are for; the status is downstream of that.

  Repaired by hand through `Story::transitionTo()` — a legal, declared edge, not
  a raw write. The four orphaned files were deleted: nothing enumerates the
  stills directory, every reader goes through `scenes.image_path`, and their
  scene ids (1639-1642) can never exist again since story 23's rows now start at
  2286. **The `cost_entries` rows stay untouched.** The ledger is write-once and
  the $0.14 was really spent; the files were the unreachable copy, not the
  record.

- **`render:cancel` marks an in-flight draft row cancelled when it cannot cancel
  it, and the row then lies about a stage that finished normally.** Small, real,
  and the same "claims authority it does not have" shape as the truncation
  remedy that named a knob its stage did not own.

  `CancelRenderBatch::handle()` closes with:

  ```php
  RenderJob::query()
      ->where('story_id', $story->id)
      ->whereIn('status', [Queued, Running])
      ->update(['status' => Cancelled, 'finished_at' => now()]);
  ```

  Every open row for the story, whatever its stage. But what this Action can
  actually stop is named one screen above, in `BATCH_PREFIXES`: `scene-clips`,
  `scene-assets`, `scene-timings`. `draft_scenes`, `extract_cast`, `outline` and
  `act_scripts` are none of those — they run synchronously, outside any batch,
  and cancelling has no reach into them at all.

  So on story 23 the cancel marked the running `draft_scenes` row cancelled
  while the draft carried on, finished normally at 11:01:11 and overwrote the
  row with `succeeded`. **The row is correct now only by the accident of write
  ordering**, which is the part worth keeping: had the draft finished a second
  before the cancel instead of after it, the ledger of stages would permanently
  record a completed 275-scene draft as cancelled — and `RenderProgress` reads
  exactly that column.

  The fix is to scope the update to the stages this Action can stop, and to say
  so when a stage it cannot stop is running rather than silently relabelling it.
  Not built: it is one `whereIn` plus a sentence, and it wants to land beside
  whichever guard above gets built, because both are about a text stage and an
  asset path having no idea the other exists.

- **The fixture banner in `stories/gate.blade.php` is an `.alert` with no
  `wide`.** Found by the width probe while clearing Gate 1's re-hook advisory,
  on story 22, which is a fixture and therefore renders it on every visit. It is
  in the SHARED gate wrapper rather than in a gate body, so no
  `*GateLayoutTest` looks at it — `alertsWithoutTheirOwnWidth` is run over each
  gate component's own markup, and the wrapper is not part of any of them. Two
  lines to fix; the reason it is worth logging is the gap rather than the alert.
  **Nothing asserts anything about the chrome every gate page renders inside.**
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

- **Gate 2 shipped with `.dash.quiet`'s defect, and 681 green tests, a clean
  theme-audit and a brand-new ordering assertion all held while it did.** This
  is the most useful entry in this section, because nothing was broken — every
  instrument was working and every one of them was answering a question that was
  not the one being asked.

  The page was drawn for the busy case: three decision panels over a scene list.
  A story past Gate 2 has none of those decisions, so story 21 rendered three
  near-empty panels as three islands with voids between them, while the one
  actionable thing on the page — scene 141 failing asset generation — sat in a
  narrow box two rows below. The layout was a constant where it should have been
  a function of state. **The same defect, in the same words, as the dashboard's,
  fixed one session earlier, by the same author, with the reasoning written down
  in this file.**

  Why each instrument passed, one at a time, because the pattern is the point:

  - **681 tests.** Not one of them renders a page and looks at it. They assert
    that values are right and that markup is present, and every value WAS right
    and every element WAS present. A void between two panels is not a missing
    element.
  - **theme-audit.** It measures how far a loud surface separates from the panel
    beside it. Every figure was byte-identical to the baseline — correctly,
    because the tokens never changed. It has no opinion about a panel being
    nearly empty, or about three of them in a row.
  - **class-audit.** Every class resolved. It reads the stylesheet and the
    markup separately, so it also could not see that removing a wrapper orphans
    fifteen descendants — proved by renaming `.scenetable`, after which it
    reported exactly ONE new finding and went on calling the other fifteen
    CONTEXT.
  - **The new ordering assertion**, written the same session to prove decisions
    come before advisories. It built a story at `scenes_drafted` — where all
    three panels have content — and passed. **It tested the state the layout was
    designed for and was silent about the state the page spends most of its life
    in.** A test written from the same assumption as the layout inherits its
    blind spot, and passing then reads as coverage.

  The general rule, which is not new here but had to be learned again on a
  different surface: **a check written alongside a design tests the case the
  designer had in mind.** The dashboard's own lesson was recorded as being about
  the dashboard. It was about layouts. When a layout is drawn for a busy case,
  the test to write first is the empty one.

  Four assertions now cover it — `ScenesGateLayoutTest` — and each was confirmed
  to fire by reintroducing the defect it names: quiet-state ordering, loud
  markers out of any scroll region, no horizontal scroll region at all, and
  every scoped class reaching its rule in the rendered page.

  Two smaller things the same blindness hid, both real: the decision row used
  weighted `fr` tracks that left the panels at their own widths at 1750px, and
  the advisory count badge sat at the far edge of a container that paints
  nothing, so it read as belonging to neither panel. Neither is visible at the
  mock's width, which is the width everything had been checked at.

- **Gate 1 shipped BOTH of Gate 2's defects, one session later, past contract
  tests written before the page existed.** The tests were written first
  precisely so a check would not inherit the layout's assumptions, and they
  still did — so this entry is about why writing the test first was not enough,
  which is a stronger claim than the Gate 2 entry above it and supersedes the
  comfortable reading of it.

  The two defects, both live on `/stories/rent-will-split-model-rhln/outline`:

  1. The advisory row has three groups — spine problems, locale terms,
     structural warnings. That story has findings in two. The third still
     rendered a `<div>`, `.gatecols` still cut it a `1fr` track, and the row
     opened on an empty column that pushed the two real groups right.
  2. On the same screen the strip said reopening is not available, while the
     structural warnings said *none of these block approval* and *all of them
     are cheaper to fix here than at Gate 3*, and the locale panel said
     *judging these is yours*. Three sentences offering decisions that do not
     exist at `scenes_drafted`.

  Both had been fixed once already, at Gate 2, in the session before this page
  was built. Neither fix travelled.

  **Why each instrument passed.** This is the part worth keeping.

  - `test_gate_one_collapses_when_there_is_no_outline_decision` asks whether the
    page renders a strip. It does. **The three contract cases — EMPTY, WIDE,
    ABSENT — are all whole-page questions**, and defect 1 lives one level below
    them: a page that is correctly, entirely in its quiet state, holding a row
    that is not. The dashboard's lesson was written down as being about
    layouts; it was recorded at page resolution and the defect recurred at
    group resolution.
  - `test_gate_one_leads_with_the_advisories_not_the_premise` asserts
    `class="gatecols"` is PRESENT and that the problems come before the
    premise. Both true. **Asserting that a container exists says nothing about
    what is inside it**, and its fixture has a finding in the first group, so
    the two-of-three shape never occurred in any test.
  - The heading fix at Gate 2 was real and is still green. It was applied **at
    the include site** — `'heading' => 'Flagged on these scenes'` — so it could
    only ever cover the one string that passes through that include. Gate 1's
    three sentences are prose inside three different alerts. A fix at a call
    site cannot reach a sentence that does not go through that call site, and
    `ScenesGateLayoutTest` is a Gate 2 file, so nothing was even looking.

  **The general rule: writing the test before the build protects you from the
  BUILD's assumptions, not from the TEST's.** The three cases were written from
  the same busy-versus-empty dichotomy the layouts were, one level up. A
  specification inherits the resolution of whoever wrote it, and a defect below
  that resolution is invisible to a test written first exactly as it is to one
  written after.

  **What was built instead of two more fixes.**

  - `x-gate-row` and `x-gate-group` (`App\Support\SlotContent`). A group with an
    empty slot renders no element; `.gatecols` is `grid-auto-flow: column` and
    cuts one track per element it actually has. An empty track stops being
    something an author has to remember not to leave. The row's own `@if` is
    gone with it — it was a hand-written restatement of the four conditions
    inside it.
  - `App\Support\GateVoice`. Every clause that names an action has two
    phrasings and one capability choosing between them, and the claiming half
    is built from a fragment in `CLAIMS` so the fragment is in the emitted
    sentence by construction. `advisoryHeading`, `blocksApproval`,
    `countBlocking`, `fixHere`, `judgement`. `APPROVE` is uniform across all
    four gates (parked at `waitsAt()`); `EDIT` is the status that PRODUCES the
    gate's material plus the status the gate waits at, which is what three gate
    bodies had each written out by hand.
  - The assertions travel. `GateLayoutContractTest` runs both over **every gate
    page × every status**, from one table Gates 3 and 4 are already in. The
    claim check greps the rendered page for GateVoice's own fragments, so a
    reworded clause moves the check with it, and a claim written by hand
    WITHOUT the mechanism is caught too — which is how Gate 4's *"N thing(s)
    block approval"* was found rendering on a story at `draft`.

  **The first version of the row assertion passed when it was drilled**, and
  that is the same defect one level up again: its fixture gave every act a null
  `escalation_beat`, so the spine check reported a PROBLEM, so all three groups
  had content and there was no void to find. A fixture that cannot express the
  failing state makes the assertion vacuous however carefully it is written —
  the `queueDepthIs()` lesson, in a layout test. Both guards were then drilled
  against real instances and both fired.



- **Gate 4's mock draws ONE state, and the two it leaves out are the two this
  page has already been broken in.** Gate 2's mock declares three states, Gate
  3's declares two, Gate 4's declares no state enum at all — its only prop is
  the theme. What it draws is a sheet that is fully written and BLOCKED.

  - **NO SHEET.** The original Gate 4 defect was a form with no producer — the
    sixth instance in the audit. The producer exists now, and the page still
    could not tell "generated" from "never generated", because `mount()` calls
    `firstOrCreate()`: **opening this page manufactures the row it would have to
    test for.** Two live stories carry a `youtube_metadata` row for no other
    reason. So the row is not the discriminator and cannot be — the CONTENT is,
    and `sheetGenerated()` asks it. Below that gate the whole sheet, the
    advisory row and the copy-paste block are all withheld: "no title selected"
    and "description is empty" are findings about the absence of a thing the
    line above them already says is absent, and an empty copy-paste block beside
    a Save button is the form-with-nothing-behind-it defect verbatim.
  - **APPROVED.** `published` is terminal and is where both finished stories
    are. The sheet is a record there, not a form, so the decision collapses to a
    strip and the sheet stays readable in full.

  Two smaller ones, both the same shape as Gate 3's window bar:

  - **The title meter is clamped and names its overage.** The mock draws a
    target-70 / hard-100 meter for a title inside both; a title over 100 is the
    case the hard limit exists FOR, and `length / 100` walks off the element at
    101. The limit is untouched — the validator still refuses — this is only the
    picture staying honest.
  - **The tag budget names the tags it would drop.** "Enforce it, do not
    silently truncate" is this file's own wording, and a TOTAL cannot be acted
    on: the running count is per tag, from the same arithmetic
    `YoutubeMetadata::charCountFor()` uses, so the ones past the line are marked
    individually. Dropping a tag here is a decision; dropping it at upload is an
    accident.

- **`.dash` was the last copy of the fixed-track defect, and it had never
  actually fired.** Measured across every dashboard shape before touching it:
  all three columns always had content, because each happens to carry an
  unconditional wrapper. That is an accident rather than a guarantee — one
  conditional around one card would end it — and it is the page whose quiet
  state started this whole line of work.

  The columns are `x-gate-group`s now, so a column with nothing in it renders no
  element, and the template restates itself for two when the middle column is
  gone. Placement stays explicit: auto-flow would wrap the money rail underneath
  the decisions instead of beside them, which is the reason the original comment
  gives and it is still true.

  **The travelling half is the point.** `DashboardTest` runs the same
  empty-track assertion the three gate pages carry, pointed at `.dash`, over
  every shape the layout has — and it was drilled by emptying `.flow` and
  watching it go red. `PageProbe::emptyRowGroups()` takes the container name for
  exactly this: one detector, four surfaces, rather than a fourth hand-written
  instance of a check that has now been got wrong three times.

- **Gate 3's MOCK had the defect this time, not the page — and the state it
  omits is the one every story in the database is in.** Worth recording because
  the previous three entries are all about a check inheriting the layout's
  assumptions; this is the layout inheriting the DESIGN's, one step further
  upstream, and the fix was the same: build the missing state first.

  The Gate 3 mock declares two states, `ready` and `running`. Gate 2's declares
  three and the third is `locked`. So Gate 3 was drawn with no settled state at
  all, and `metadata_ready`/`published` is where two of the three rendered
  stories sit.

  **The target-window bar is the sharpest instance.** The mock hardcodes the
  axis — 30 min at 20%, 40 min at 67%, so about 25.7 to 47 minutes — and draws
  exactly one verdict, `IN WINDOW`. Measured against the database:

  | story | status | runtime | verdict | on the mock's axis |
  |---|---|---|---|---|
  | sample-story | rendered | 2:42 | 27 min 18 s under | about **−108%** |
  | story 9 | published | 29:38 | 21 s under | 23% |
  | story 21 | published | 40:36 | 36 s over | 78% |

  **Not one story is in window**, so the only verdict the mock specifies is the
  only one that never occurs — and the story that would fall clean off the
  element is `sample-story`, which is parked at `rendered` permanently and is
  therefore the one most often on screen. The axis is derived from the story's
  own window now, the mark is clamped in PHP, and the distance is printed beside
  it: a clamped mark on its own reports 21 seconds and 27 minutes as the same
  picture. The VERDICT is unchanged — `in_target_window` still decides and Gate
  3 still reports rather than refuses, because the floor is a preference.

  Four more the mock does not distinguish, each already a named defect here:

  - **`rendered` with no file is not "still encoding".** The mux row says the
    stage finished and the artifact is absent — the false-success table exactly
    — and folding it into the running state sends the operator to a progress
    page that will agree with it. It leads the page as a failure now.
  - **`EXIT 0` is drawn as a constant.** The badge comes from
    `render_jobs.status`, and a failed mux gets its error above the fold.
  - **"112/270 clips" cannot come from `render_jobs`.** `RenderJob::open()` runs
    INSIDE the job, so a queued scene has no row — row 7 of the false-success
    table, where story 21 read "118 done, nothing failed" with 152 scenes in
    Redis and nothing listening. The denominator is the scene count, the way
    `RenderProgress::stages()` already decided it, and scenes with no row at all
    are named as unseen rather than folded into either side.
  - **Act boundaries cannot go on the scrubber.** A native `<video controls>`
    scrubber belongs to the browser, and swapping in a custom player to gain
    seven tick marks would put this gate's one job behind JavaScript that can
    fail. They are their own rail under the player: same source, same
    information, nothing to go wrong.

  **And class-audit caught a live defect in the first build of this page, which
  is the argument for running it during the work rather than after.**
  `.measure` was `.alert.wide > .measure` — a DIRECT child of a wide alert — and
  Gate 3 wrote it on prose inside a `.panel` and on a `.grow` one level down
  inside an alert. Three of four usages matched nothing. The audit answered
  CONTEXT for all of them, which is its benign verdict, and CONTEXT is benign
  only while the ancestor is really there. `.warnfill` again, and the same
  lesson `.alert.wide` was extracted for: **a rule that imposes or lifts a
  global cap belongs on the element, never under a container.** `.measure` is
  unscoped now, and `PreviewGateLayoutTest::test_every_scoped_class_reaches_its_rule`
  is the Gate 2 guard copied onto this page — drilled by renaming `.actsrail`
  and watching `.tick` go unreachable.

- **A component tag inside a CSS comment is compiled. Inside a BLADE comment it
  is not — and this entry said the opposite for a day.** Worth keeping in that
  order, because the wrong version was written confidently, from the pass order
  as remembered rather than as measured, immediately after a real outage.

  What happened: `<x-gate-group>` written to NAME the component in prose, inside
  a `/* */` comment in `base-css.blade.php`. Every page in the console died on
  `syntax error, unexpected end of file, expecting "elseif"`, a thousand lines
  from the text that caused it, reading as an application bug. **Blade has no
  concept of a CSS comment**, so the tag was never in a comment at all as far as
  the compiler was concerned — it was ordinary template text inside `<style>`,
  where a compiled component render is a syntax error.

  The rule written for it looked for component tags inside BLADE comments. That
  is the safe case, so the rule flagged what cannot break and missed what did.
  Measured order, from `BladeCompiler::compileString()`:

  | # | pass | rewrites | before comments? | covered |
  |---|---|---|---|---|
  | 1 | `prepareStringsForCompilationUsing` | Livewire inline islands, `@island…@endisland` | **yes** | now, `PRE-COMMENT-TOKEN` |
  | 2 | `storeUncompiledBlocks` | `@verbatim…@endverbatim`, `@php…@endphp` | **yes** | `@php` by `SWALLOWED`/`UNPAIRED`; `@verbatim` now by `PRE-COMMENT-TOKEN` |
  | 3 | `compileComments` | `{{-- --}}` stripped here | — | — |
  | 4 | `compileComponentTags` | `<x-…>`, `<x-slot>` | no | `COMPILED-IN-COMMENT`, for foreign comments |
  | 5 | `precompilers` | morph-aware `@if`, `<livewire:…>`, ExtendBlade | no | as above |
  | 6 | `token_get_all` | `@directives`, `{{ echoes }}` | no | — |

  **Only passes 1 and 2 run before comments are stripped.** So the rule is not
  "comments are compiled": it is **a comment Blade does not know is a comment is
  not a comment**. Blade knows `{{-- --}}` and nothing else — not `/* */`, not
  `//`, not `<!-- -->`.

  `tools/blade-php-scan.php` refuses four shapes now, and the fixture carries the
  NEGATIVE case beside the positive one — the same component named in a blade
  comment two lines below the CSS one — so a rule written backwards again fails
  in `ToolsAnswerKnownCasesTest` rather than in an outage. Verified by drilling:
  adding blade comments back to the foreign-comment list turns that test red.

  Two before-comment tokens had never been covered and are now: `@verbatim`, and
  `@island`, which belongs to a pass EARLIER than the php blocks. No view here
  uses islands and that pass short-circuits without `@endisland` in the file —
  but "no view uses it yet" is why a hazard goes unnoticed, not evidence that it
  is absent.

  A second, quieter instance of the same "the tool cannot see it" family came
  out of the same change: `x-gate-row` first wrote its class as
  `$attributes->class(['gatecols'])`, and `class-audit` immediately listed
  `.gatecols` under NO LITERAL ASKS FOR THESE — its dead-rule list — about the
  rule the row depends on. **A component that hides its classes behind the
  attribute bag makes every class it carries unauditable.** Both components
  write literal classes and take no attribute bag.

- **A CLAUSE THAT CLAIMS A POSITION WALKED PAST A CONTRACT WRITTEN FOR CLAUSES
  THAT OFFER AN ACTION. Three defects across two gates, on eleven of the
  forty-four gate pages, and nothing that runs could have found any of them.**
  This is `GateVoice`'s own lesson turned on `GateVoice`, and it outranks the
  four-gate entries above it for the same reason those outrank the pages they
  are about.

  `GateVoice` was built because a sentence NAMING AN ACTION must be a function
  of whether the action exists. `claimsNotEntitledTo` greps a rendered page for
  the claiming fragments, `GateLayoutContractTest` runs it over four gates at
  eleven statuses each, and `GuardsGoRedTest` drills it. All of that worked.

  What shipped anyway:

  | gate | said | at | condition behind it |
  |---|---|---|---|
  | 4 | "Gate 4 is behind this story", and `draft` "is terminal: the file is on YouTube" | 8 statuses | `! editable()` |
  | 4 | a GREEN "Published on \<date\>. This sheet is now read-only", with `updated_at` as the date | 8 statuses | the `@else` of `editable()` |
  | 2 | "Gate 2 is behind this story" | 3 statuses | `! canApprove() && ! canGenerateAssets()` |

  **None of those names an action.** They make a claim about WHERE THE STORY IS,
  so every fragment the check knew about missed every one of them, and a
  contract running the whole grid was green about it for a phase. It was found
  by hand, during a hunt for an unrelated predicate, on a page that had just
  been rebuilt and reviewed against real data.

  **The common cause is one substitution, made three times: a CAPABILITY read as
  a POSITION.** `! editable()` is false on BOTH sides of a gate — this is the
  same thing `MetadataGate::pastThisGate()` was written for one item earlier,
  and the strip is where the substitution was doing the most damage. A
  capability answers "is there a decision here", which is the layout question
  and was correct; it cannot answer "which side of this gate is this story on".

  **Gate 3 was the only one of the four that was right, and it was right by
  hand.** Its `phase()` is rank-based, so both of its sentences were true
  wherever they rendered. That is the "one instance fixed by hand is not a
  mechanism" shape exactly — the same shape as Gate 2's advisory heading, which
  is what produced `GateVoice` in the first place. The wording the shared clause
  is written from is Gate 3's, verbatim.

  **What changed, and what did NOT.**

  - Position is three states, not two. `PASSED`, `AHEAD` and — the one a boolean
    cannot hold — parked AT the gate, which is entitled to neither claim.
    `PASSED` and `AHEAD` are deliberately not complements, and a test asserts no
    clause is ever entitled to two of its own phrasings at once.
  - **A position clause has a fragment per PHRASING, where an action clause has
    one.** An action clause claims in one state and asserts nothing in the
    other, so a careful settled sentence makes it safe. Every wording of "is
    behind this story" / "has not been reached" is a claim, so nothing but the
    capability can. `CLAUSES` is now one map, clause to capability to fragment,
    and `CLAIMS` is gone — they were two lists keyed the same way, which is a
    fifth clause added to one and not the other.
  - `claimsNotEntitledTo`'s LOOP did not change. The capabilities are data, so a
    new KIND of claim arrives the way a reworded one does; that is what the
    shared list was for.
  - **But it normalises whitespace first, and without that the whole extension
    would have been decorative.** Gate 4's blade wrapped its disclosure between
    "which is" and "terminal", so `str_contains` on the raw HTML could not see
    the fragment at all — measured, not assumed. **A detector a line break
    defeats is indistinguishable from a detector that passed**, and the one
    fragment it most needed to find was the one it structurally could not. Only
    runs of whitespace collapse; a fragment split across an ELEMENT still does
    not match, and there is a green case asserting that.
  - Gate 1's two sentences were already right, and both go through the voice
    anyway. Gate 1 is the one gate that CANNOT carry this defect — nothing
    precedes `draft`, so "not editable" and "past this gate" are the same set
    there. That is a property of the lifecycle, not of the template, and a
    sentence true for a reason outside itself is one condition change from being
    Gate 4's. Drilled: widen the condition, and the hand-written version is
    reported while the voice's version corrects itself.

  **Five defect shapes, each drilled red with a green counterpart** — and two of
  the five drills PASSED on the first attempt because the drill was wrong, not
  the guard. Reverting only the branch that renders when the claim is true, and
  reverting a condition while leaving the voice in place, both reproduce
  something that is not the defect. **A drill that passes is a claim about the
  drill until it is shown to reproduce the shipped shape**, which is the same
  rule as a fixture whose answer must be established independently of the thing
  it checks.

  The general finding, which is the part to keep: **a contract inherits the axis
  of whoever specified it.** This one was specified as "no page names an action
  it does not have" and was flawless on that axis while three sentences were
  wrong about something one word away from it. Every earlier entry here is about
  a check written from the same assumption as the thing it checks; this is about
  a check written from the same VOCABULARY. When adding a clause to a shared
  voice, the question is not only "is this sentence true here" but "what KIND of
  thing is it asserting, and is that kind on the list".

  ---------------------------------------------------------------------------
  THE AXIS QUESTION, WHICH IS NOW A STANDING ONE
  ---------------------------------------------------------------------------

  **THREE axes have been named here, and not one was named on purpose.** All
  three arrived the same way: a defect shipped, somebody looked at what was
  being asserted, and the KIND of assertion turned out not to be on any list.

  | axis | what it asserts | named when | found by |
  |---|---|---|---|
  | ACTION | that something can be done from this page | Gate 1's advisories offered three decisions that did not exist | reading the published story's page |
  | POSITION | where the story stands relative to this gate, and whether its status is the end | Gate 4 called `draft` terminal and put Gate 4 behind a story that had not been outlined | a hunt for an unrelated predicate |
  | **PRECONDITION** | that this story can complete the stages a dispatch is about to queue | story 23 dispatched with a null `voice_id` and turned it into 257 identical per-scene failures | a narration batch failing on every scene |

  **THE PREDICTION PAID, AND IT PAID SOMEWHERE THE LIST WAS NOT LOOKING.** The
  paragraph below used to say a third axis was likely and that nothing looked
  for one. It arrived — and not as a fourth kind of SENTENCE on a page, which
  is where this section was watching. It arrived one layer over, in the GUARDS.

  `PreflightAssetDispatch` asks three questions and every one of them is the
  same kind: *has the environment moved since this story was prepared* — worker
  code, aligner install, art style fingerprint. Not one asks *does this story
  carry what the stages need*. The class was flawless on the axis it was
  specified for while a null column walked through it, and the roster of what
  else is in that class was there to be read the whole time: `narration:preflight`
  has asked five such questions, for free, since it was written.

  **So the widening is the finding, not the third row.** The question is not
  "what kind of claim is this sentence making"; it is **"what kind of thing is
  this mechanism asserting, and does anything check that kind"** — and it
  applies to a guard exactly as it applies to a clause. A guard is a claim about
  a state, made in code instead of in prose, and it inherits its author's axis
  the same way.

  **A FOURTH is still likely and the candidate is still unwatched** — see the
  FIGURE case below, which has been in evidence since the position defect and
  has nothing checking it. The pattern holds in both directions now: the axis is
  invisible until something is wrong on it, and consistency is not coverage.

  **The one candidate still in evidence and still unchecked** — recorded because
  it turned up in the same sentence as the position defect, not because it was
  hunted for — is a claim about a FIGURE: that a number or a date on the page is a measurement of
  the thing it is labelled as. "Published on \<date\>" was two defects, and only
  the first was position. The second was that `updated_at` was never a
  publication date — on story 9 it resolved to the second at which a STYLE
  PREVIEW was billed, a day after the sheet was approved. No capability makes
  that sentence true or false, so no capability could fix it: the sentence lost
  its figure instead. See "THE APP HAS NO PUBLICATION EVENT" under Conventions.

  **What NOT to do about this.** Not a speculative fourth capability — a guard
  written before its instance is the documented-guard shape, and this file's
  standing rule is that a check must be confirmed to fire against a real
  failure. What is worth doing is asking the question when a clause is added,
  when a GUARD is added, or when either is reviewed: **what kind of assertion is
  this, and does anything check that kind?** A sentence or a check whose kind has
  no answer is not necessarily wrong. It is unchecked, and unchecked has read as
  covered three times now.

  ---------------------------------------------------------------------------
  THE SAME QUESTION ONE LAYER DOWN: **A SHARED FIXTURE HAS AN AXIS TOO, AND
  NOTHING WATCHES IT**
  ---------------------------------------------------------------------------

  `GateLayoutContractTest::pageFixtureFor()` has now silently voided a contract
  **twice, for the same reason**, and neither was found on purpose:

  | when | the field | what went unchecked |
  |---|---|---|
  | the empty-track pass | `escalation_beat` was null on every act, so the spine check reported a PROBLEM, so all three advisory groups had content | the empty-track assertion had no void to find and passed its own drill |
  | the sizing pass | `sized_against_wpm` was never set, so every non-writable status rendered Gate 1's UNKNOWN branch | the claim check could not see the sizing clause at all, on four gates × eleven statuses |

  Both fields were added to the fixture only after the assertion built on them
  had already been shown to be vacuous. **The fixture is a shared input with a
  dimension per story field, and every assertion that reads a page inherits
  whichever dimensions that fixture happens to exercise.** That is the ACTION /
  POSITION finding one layer down: there the mechanism covered the kinds of
  claim somebody had thought of, here the fixture covers the states somebody
  has thought of, and in both cases what is not on the list is not wrong — it
  is unwatched, and unwatched reads as covered.

  It is a worse position than the axis question in one respect. A clause at
  least announces itself: it is a sentence somebody wrote, on a page somebody
  can read. A fixture's unexercised dimension announces nothing at all — the
  contract runs, prints 44 green states, and the state it never built is not in
  the output to be missing from.

  **Not a tool, and deliberately not.** "Assert the fixture varies every column"
  is unbounded and mostly meaningless — `premise` and `title` have no bearing on
  any layout. What can be said precisely, and is worth saying when a surface is
  added: **name the story field the new surface branches on, and check whether
  `pageFixtureFor()` produces both sides of that branch.** Two entries in this
  table would have been caught by asking exactly that, in the change that
  introduced them, in about a minute.

  The one mechanical thing that already exists is worth keeping in view:
  `GuardsGoRedTest` asserts a PROPERTY of the fixture — that it leaves the
  spine-problems group empty while the other two have findings — so that a
  factory default cannot quietly make the row contract vacuous again. That is
  the pattern to repeat per branch, not a sweep over every column.

- **THE PAGE WITH THE THINNEST COVERAGE DRIFTED FURTHEST, AND IT DRIFTED BY
  EXACTLY THE AMOUNT NOTHING WAS WATCHING.** Gates 2, 3 and 4 each had a
  `*GateLayoutTest`. Gate 1 did not, and Gate 1 is the page that came back from a
  rebuild least like its design.

  The rebuild is what makes it measurable rather than a feeling.
  `outline-gate.blade.php` was destroyed by a `git checkout --` on uncommitted
  work and rebuilt from the test suite and this file. **Everything the suite
  pinned came back**: eleven statuses, the three-state sizing panel, every
  `GateVoice` clause, quiet-state ordering, the empty-track contract, the
  advisories-before-premise ordering, `.twoup`. All green, 681 tests, four clean
  audits.

  **Everything the suite did not pin came back as whatever the rebuilder
  happened to write.** The spine as one column instead of two. The acts as seven
  full-width panels instead of a grid — three screens of scrolling on the page
  whose one job is a single approve decision about what is on it. Premise and
  cast age sharing a panel, so neither could carry the note that belongs to it.
  No panel header bars, no count badge, no phase edge, no sticky decision. The
  re-hook advisory stranded below the spine at the 96ch cap. Two hand-written
  `style="margin-top:-6px"` workarounds standing in for an arrangement.

  So it is not really a fact about Gate 1. **A rebuild from the tests is a
  rebuild TO the tests** — it restores exactly what somebody wrote down, and the
  shape of what is missing afterwards is a map of what nobody did. That is the
  same finding as every fixture entry above, arrived at from the other end: a
  fixture that cannot express a state makes the suite blind to it, and a page
  with no layout test has no state expressed at all.

  `OutlineGateLayoutTest` exists now, written against the drifted page so that
  every assertion failed first and the failures were the specification — the
  order `GateLayoutContractTest` was built in for Gates 1, 3 and 4. Nine
  assertions, each drilled red against the shape it names.

  **What it deliberately does not assert**, because the design is not always the
  older witness: the advisory row's `1.05fr` track weighting, which `.gatecols`
  rejects in its own comment on measured grounds, and the locked banner's alarm
  gradient, which Gate 2 rejected for a reason recorded in `base-css.blade.php`
  and still true — the console has one saturated flood and an approved gate is a
  story going correctly.

- **OVER-REPORTING IS A SECOND DIRECTION, AND EVERY DEFECT IN THIS FILE BEFORE
  IT WENT THE OTHER WAY. THREE OF THE FOUR TOOLS HAD ONE.**

  Read the two lists above and they are one shape: a check that could not see
  the thing. `strpos` coercing false to 0, a fixture that could not express the
  failing state, a detector defeated by a line break, a regex that wanted the
  other quote character. Silent, vacuous, **absence read as agreement** — the
  sentence this file repeats more than any other.

  A tool can also be wrong by reporting what is not there, and the cost is not
  symmetric with a miss. **A false positive in a SEVERE category costs more than
  a miss in a benign one**, because the severe category is the one that gets
  acted on: `UNDEFINED` is what found `.panel.money`, and `GONE` is what this
  file leans on to catch one mistyped hex among two hundred token lines. Fill
  either with findings nobody can act on and an operator learns to discount the
  whole section — the same argument this file already makes about an alarm that
  fires for something the reader cannot act on, pointed at an audit instead of a
  worker. A miss leaves one defect unfound. A false positive in a severe
  category retires the detector.

  **It also masks.** Comma-merge two rules while genuinely deleting a third and
  `theme-audit` reports three GONE entries that look alike. That is the
  159-of-343 defect again — a real finding among false ones is
  indistinguishable from no finding.

  So all four tools were swept for it, and the result is worth stating plainly:

  | tool | severe categories | over-reported? |
  |---|---|---|
  | `class-audit` | UNDEFINED, TAG, COMBO | **yes** — every quoted literal inside `@class([...])`, operands included |
  | `theme-audit` | MOVED, GONE, DIVERGED, LOW CONTRAST | **yes** — a comma-merge reported as GONE |
  | `blade-php-scan` | all four kinds | **yes** — a foreign comment inside a pass-2 uncompiled block |
  | `scoped-override-audit` | **none** | not applicable, and that is the finding |

  Three of four had one. The fourth is the one that CANNOT: it has a single
  category, `CONDITIONAL OVERRIDES`, framed in its own output as *a judgement,
  not automatically a defect*, and it exits 0 whatever it finds. **A tool with no
  severe category has no severe category to be falsely loud in**, which is not an
  accident of that tool but the property that makes its cry-wolf risk
  survivable — and the reason its own docblock says to read the list rather than
  count it.

  What was NOT examined, so it is not read as covered: `theme-audit`'s MOVED is
  bounded by the identity case, which would show an over-report immediately, but
  DIVERGED and LOW CONTRAST were not probed for false positives. `blade-php-scan`
  keeps one narrow over-report on purpose — a blade comment naming `@verbatim`
  *inside* a verbatim block is inert, and is still reported, because the advice it
  gives is correct in every other arrangement and no view here uses verbatim at
  all.

  **The general practice, and it is cheap.** A known-answer fixture proves a tool
  SEES what it should. It says nothing about what the tool invents. So every
  known-answer case now carries a negative half as close to the positive as it
  can be made — an `@class` whose condition names four strings that are not
  classes; a comma-merge beside a real deletion; the same CSS comment inside a
  `style` element and inside a php block. Both halves, or the fixture only proves
  the direction somebody happened to think of.

- **`class-audit`'s `@class` parser: the instance the direction was found in.**
  Every tool defect before it was an under-report; this was the other kind.

  It took every quoted literal inside `@class([...])`, wherever it sat. That is
  right for `@class(['warnfill' => $cond, 'panel'])` and wrong the moment a
  condition contains a string, which is the ordinary way to write one:

  ```php
  @class(['actcard', 'leaving' => $act['phase'] === 'departure'])
  ```

  `departure` is an operand and `phase` is an array key. Neither is ever emitted
  as a class, and both were reported UNDEFINED — the tool's most severe category,
  the one that found `.panel.money`. Gate 1's rebuild produced **seven phantoms
  in one pass**, the first time this project wrote a comparison inside `@class`.

  **A loud section that fills with findings nobody can act on is a loud section
  that stops being read**, which is this file's own argument about an alarm that
  fires for something the reader cannot act on, pointed at an audit. And the
  workaround was available and tempting — compute the booleans above the tag and
  the phantoms go away — which would have left the tool wrong and the next person
  to write a condition inside `@class` with seven findings and no explanation.

  The array body is walked at top level now: commas inside nested brackets do not
  split, and a literal counts only in a CLASS POSITION — the key of `'name' =>
  expr`, or a bare `'name'`. The known-answer fixture carries an `@class` whose
  condition names four strings that must never be reported, and the drill was run
  with the real pre-fix parser rather than a paraphrase of it: 5 phantoms, red.

- **`theme-audit` called a COMMA-MERGE a deletion, and it was live in the run
  that found it.** Rules were keyed by the whole selector LIST — everything
  before the `{` — so `.alpha, .beta { … }` was one rule named ".alpha, .beta".
  Merging two identical rules into one comma-separated rule reported both
  originals GONE and the result NEW, with nothing the page paints changed.

  Measured on a two-rule sheet: **2 GONE, 0 identical** — the differ saying every
  rule in the baseline had vanished, for a pure reformat. `GONE` increments the
  failure count and, unlike `NEW`, carries no "not a regression" qualifier.

  It was found by reading the tool's own output on the Gate 1 rebuild, which
  merged `.advisories > .head h2` with `.alerthead h2` so that one declaration
  block serves both surfaces. The audit called the original gone; it was not
  gone, it was in the rule beside it. **That report was passed on as an expected
  shape before it was checked**, which is this file's standing mistake in
  miniature: a tidy output read as a correct one.

  Selectors are keyed individually now, split bracket-aware so `:not(.a, .b)`
  stays one selector. The same real comparison that produced the false GONE now
  reports **0 MOVED, 0 GONE, 34 NEW, no findings**. Paired known-answer cases: a
  merge that must be silent, and a genuine deletion that must still name `.gamma`
  — because a differ made quiet about GONE would satisfy the first perfectly.

- **`blade-php-scan` flagged a comment inside a block the compiler never
  compiles.** `@php…@endphp` and `@verbatim…@endverbatim` are extracted by
  `storeUncompiledBlocks` at PASS 2 — before comments are stripped and before
  anything is compiled — so a `<x-gate-group>` named in a CSS comment inside one
  is inert. It was reported as `COMPILED-IN-COMMENT`, which is the category for
  the defect that takes every page in the console down.

  **Verified against the compiler, not reasoned about**, which is this file's own
  rule for any claim about pass order and the rule this very check was once
  written in violation of. `compileString()` on the php-block case returns the
  tag untouched; on the `<style>` case it returns a component render.

  Those blocks are masked before the foreign-comment scan — with spaces, so every
  line number still points where it did — and deliberately NOT masked for the
  pairing walk, whose entire subject is which opener meets which closer.

  One narrow over-report is kept on purpose: a blade comment naming `@verbatim`
  *inside* a verbatim block is inert and is still reported. The advice it gives is
  correct in every other arrangement, both realistic shapes were confirmed
  hazardous against the compiler, and no view here uses verbatim at all.

- **NO TOOL HERE HAD EVER BEEN RUN AGAINST A KNOWN ANSWER, and that is the
  pattern behind three defects in three turns.** This entry outranks the three
  it generalises.

  | found | defect | found how |
  |---|---|---|
  | turn 1 | `strpos` returns false, false coerces to 0, so every ordering assertion passed for a DELETED element | drilling a different assertion |
  | turn 2 | the only rule lifting a global measure cap was scoped to a container the new layout removes | looking at a screenshot |
  | turn 3 | `theme-audit --against` resolved the baseline in LIGHT and the sheet in DARK: 159 of 343 rules MOVED comparing a file to ITSELF | establishing a baseline for something else |

  None was found on purpose. The common factor is not carelessness: every one of
  these tools was only ever pointed at the LIVE TREE, where any output looks
  plausible, and **a tool that is confidently wrong is indistinguishable from a
  tool that is right.** The audits were trusted because they produced tidy
  output, which is the same reasoning this file rejects everywhere else.

  So each tool now has a case whose correct output is known in advance, and
  `tests/Feature/ToolsAnswerKnownCasesTest.php` runs them in the suite rather
  than leaving them to memory:

  | tool | known-answer case |
  |---|---|
  | `class-audit` | a fixture with one class no rule names (UNDEFINED 1), one reachable only under an ancestor (CONTEXT 1), one unscoped rule that must NOT be called scoped, and an `@class` whose condition names four strings that must never be reported as classes |
  | `blade-php-scan` | a literal `@endphp` in a comment (UNPAIRED) and an inline `@php(...)` that swallows to a later closer (SWALLOWED); a clean file beside them reports nothing |
  | `scoped-override-audit` | a sheet with one conditional override and one scoped rule repeating the same value, which is not one |
  | `theme-audit --against` | a sheet against a COPY OF ITSELF must report every rule identical — and one mistyped token must still be reported, or the fix is a differ that reports nothing ever |

  Writing the fixtures found two more defects the same hour. `class-audit` and
  `scoped-override-audit` both read the stylesheet whole, so the first rule
  after the `<style>` tag parsed as `<style> .thing` — ancestor-scoped — and was
  answered CONTEXT, the BENIGN verdict, for a rule that is not scoped at all.
  Latent on the live sheet only because its first rule is `:root`, which carries
  no class. Both read the `<style>` body now.

  **The general rule: a tool that cannot be pointed at a known input cannot be
  tested.** All four take a path argument for that reason. And for any differ
  specifically, the first test to write is the identity case — compare the input
  against itself and require zero.

- **AND NO ASSERTION HAD EITHER. Same rule, and it had cost more.** The entry
  above generalises three defects in the tools; this is the same generalisation
  one level up, and it should be read as part of it rather than as a separate
  lesson. **Nine self-defeating checks so far, and not one was found on
  purpose. Four of the nine were found only because something ADJACENT was
  being changed** — which is the part that should be uncomfortable, because
  there is no reason to think the adjacent change was the last one.

  | # | the check | why it was green about nothing |
  |---|---|---|
  | 1 | every ordering assertion in `ScenesGateLayoutTest` | `strpos` returns false, false coerces to 0, so it passed for an element DELETED from the page |
  | 2 | the decisions-precede-advisories ordering test | built its story at `scenes_drafted`, the one state where all three panels have content |
  | 3 | `theme-audit --against` | resolved the baseline in light and the sheet in dark: 159 of 343 rules "MOVED" against itself |
  | 4 | the empty-track assertion | its own fixture gave every act a null `escalation_beat`, so the spine check reported a PROBLEM, so all three groups had content and there was no void to find |
  | 5 | `blade-php-scan`'s component-tag rule | rule AND fixture written from one wrong belief about the pass order, so they agreed with each other and both missed the CSS comment that took the console down |
  | 6 | `claimsNotEntitledTo` | grepped raw HTML, so a fragment the template wrapped across a line — "which is / terminal" — was invisible to it |
  | 7 | the raw-constant anti-drift grep | required a SINGLE QUOTE, so reintroducing the defect as `config("render…")` walked straight past a guard written to catch exactly it |
  | 8 | `GateLayoutContractTest`'s claim check, on Gate 1's sizing clause | its fixture never set `sized_against_wpm`, so every non-writable status rendered the panel's UNKNOWN branch, and the clause could only appear in a state the fixture never produced |
  | 9 | `alertsWithoutTheirOwnWidth`, on Gate 1's re-hook advisory | the Gate 1 case built ONE act, at sequence 1, and the check exempts act 1; `pageFixtureFor()` wrote a re-hook on both of its acts. **No test in the suite had ever rendered that element**, and it was live on story 22 at the 96ch cap |

  | 10 | `PartialSceneRedraftTest`, six cases, on a `--acts=` re-draft that had never worked | its three acts carry a ~30-word script each, which at `words_per_scene: 30` is ONE scene per act. The collision needs TWO. **The fixture was not missing a field — it was too SMALL** |

  **Number 10 is a different mechanism from the rest and that is why it is worth
  its own paragraph.** Every earlier fixture defect here is a fixture that OMITS
  something: a null `escalation_beat`, a null `sized_against_wpm`, a
  `queueDepthIs()` that answers one depth for every queue. The obvious guard
  against that family is "does the fixture set every field", and it is the guard
  this file already gestures at.

  It would not have caught number 10. That fixture sets every field it needs.
  What it cannot do is hold enough ROWS: `DraftScenes::persist()` parks new
  scenes at `PARK_BASE + 1 .. PARK_BASE + N` and `renumberByAct()` parked
  everything at `PARK_BASE + $index`, so the two bands only overlap once an act
  has more than one scene. At exactly one scene per act the bands collapse to a
  single number, the only row assigned `PARK_BASE + 1` is the row already
  sitting there, the update is a no-op, and six cases go green over a feature
  that fails on every real story. Story 12 died on `Duplicate entry '12-30001'`
  after billing two model calls for the act it then rolled back.

  So the fixture question has two halves, and only one of them is about fields:

  - **Does the fixture set every field the surface branches on?** (numbers 4, 8)
  - **Is the fixture BIG enough for the failure to exist in it?** (number 10)

  The second is harder to ask because there is no field to point at — the
  quantity that mattered was "scenes per act", which appears nowhere in the code
  under test and only emerges from `words_per_scene` dividing a script length.
  The new case takes a `scenesPerAct` argument and asserts it produced more than
  three scenes before it asserts anything else, so it cannot quietly collapse
  back to the vacuous size.

  Numbers 4 and 7 are the sharpest, and they are the same story twice: both were
  written to catch a defect that was live at the time, both were drilled
  deliberately, and **both passed the drill**. In 4 the fixture removed the
  condition the detector measures; in 7 the drill reintroduced the defect in a
  spelling the detector's regex did not cover. The detector was right in both
  cases. What it was handed could not contain the failure.

  So the detectors are pure functions in `Tests\Support\PageProbe` — not private
  methods on the test that uses them, for two reasons. Two of them had been
  copied verbatim into two files, which is the two-copies shape this file has
  paid for three times. And **a detector living inside its own test cannot be
  pointed at a known-bad input**, which is exactly what made all four of the
  above possible.

  `tests/Feature/GuardsGoRedTest.php` is `ToolsAnswerKnownCasesTest` for
  assertions. Every case is a PAIR:

  - **RED** — a known-bad input the guard must report.
  - **GREEN** — a known-good input, as close to the bad one as possible, that it
    must not.

  The pairing is not ceremony. A rule that reports everything satisfies RED, and
  blade-php-scan's first component-tag rule satisfied RED against the wrong
  comment entirely. The nastiest GREEN case here is that the SETTLED phrasing of
  every `GateVoice` clause must not trip the claim check — "None of these blocked
  approval." is one character from containing "block approval", and a guard that
  fires on its own fix can only be made green by weakening it.

  And the fixture gets its own cases: `pageFixtureFor()` is asserted, at every
  status, to leave the spine-problems group EMPTY while the other two have
  findings. That is defect 4 made checkable — change a factory default and it
  goes red beside an explanation, rather than quietly making the contract
  vacuous.

  **The standing rule, for both files: a guard that cannot be shown to go red is
  indistinguishable from a guard that passed.** Adding a guard means adding its
  red/green pair in the same change, not remembering to drill it by hand.

  ---------------------------------------------------------------------------
  **HOW DEFECTS ARE ACTUALLY FOUND HERE: BY WORKING NEXT TO THEM. THIS IS THE
  PATTERN, NOT THE EXCEPTION.**
  ---------------------------------------------------------------------------

  Every entry in the table above was found by accident, and the table records
  that one case at a time. Said once, plainly, because it changes what to expect
  from the instruments:

  | defect | found while |
  |---|---|
  | `strpos` coercing false to 0 | drilling a different assertion |
  | the scoped `.measure` cap | looking at a screenshot |
  | `theme-audit --against` comparing light to dark | establishing a baseline for something else |
  | the empty-track fixture's null `escalation_beat` | drilling the empty-track guard, which passed |
  | the raw-constant grep wanting a single quote | running the drill twice with different quote characters |
  | Gate 1's sizing clause, invisible to the claim check | drilling Gate 1's own sizing pair |
  | `escalation_beat` missing at the SCENE call site | wiring the same field somewhere else |
  | `--acts=` parking-band collapse | a re-draft failing during an unrelated measurement |
  | the 0x08 recurrence | hexdumping a line on suspicion, twice |
  | "eyes wide" classifying its own frame as a wide shot | measuring something else in the same story |

  **Not one of these was found by a test, an audit or a review.** Several were
  found while the suite was green and every audit was clean — and in three cases
  the instrument written FOR that defect was green about it at the time.

  Two things follow, and the second is the uncomfortable one.

  1. **Proximity is the detector.** The cheapest thing available is to look
     carefully at whatever sits next to the change while it is open: the other
     consumers of a field, the other parsers of a string, the fixture's size as
     well as its fields, the drill written the other way. Every entry above was
     within one step of work already being done.
  2. **The instruments are for REGRESSION, not for discovery.** 979 tests, four
     static audits and a byte scanner are what stop a defect coming back once
     somebody has seen it. Not one of them has ever found a new one. Treating a
     green suite as evidence that nothing is wrong is the mistake this whole
     file exists to correct, and it is worth restating at the top of the
     section that catalogues it.

  This is not an argument for more instruments. It is an argument for reading
  the thing beside the thing you are fixing, and for writing down what you find
  there even when it is not what you were looking for.

  **A FIFTH, and it is the worst of them: the fixture PASSED and proved the
  wrong question.** The four above are checks that failed to fire. This one
  fired, went green, and was wrong anyway.

  `blade-php-scan`'s `COMPILED-IN-COMMENT` rule was written the same hour a
  component tag in a comment took the console down. It looked for component tags
  inside BLADE comments. It shipped **with a known-answer fixture, and the
  fixture passed** — because the fixture was written from the same wrong belief
  as the rule: both assumed the component-tag compiler runs before comments are
  stripped. It does not. `compileComments` runs one step BEFORE
  `compileComponentTags`, so a tag in a blade comment is inert, and what
  actually broke the console was a CSS comment — which Blade cannot see at all.

  So the rule flagged what cannot break and missed what did, and the fixture
  agreed with it at every step. **A known answer is only known if the answer was
  established independently of the thing it is checking.** Mine was not: I wrote
  the rule and the fixture from one belief and never compiled a case to see.

  It surfaced only because the pass order was asked for as a table, which forced
  compiling both cases and looking. Nothing in the suite could have found it —
  every test was green, on both sides.

  Two things follow.

  1. **For any rule about a compiler, the fixture is not the evidence — the
     compiler is.** `BladeCompiler::compileString()` was run over both comment
     shapes and the output inspected; only then was the rule rewritten. The
     fixture now carries the NEGATIVE case beside the positive one, so a rule
     written backwards again fails rather than agreeing with itself.
  2. **The fixture then immediately earned its place.** Widening the rule to
     blade DIRECTIVES in foreign comments — after an `@if` written in prose,
     inside the CSS comment explaining the first incident, took the console down
     a second time — went in with a literal backspace byte (0x08) where `\b` was
     intended. The regex was valid, the tool ran clean, and it silently matched
     nothing. The known-answer count went from 2 to 1 and named it in one run.
     A live tree would have looked exactly the same.

  And the stylesheet had been carrying an unescaped `@class` in a CSS comment —
  inside the paragraph that documents this very hazard. The lesson was written
  down and had no tool behind it; now it has one.

  **SIX, SEVEN AND EIGHT ARE ONE SHAPE AT THREE SIZES: THE DETECTOR WAS RIGHT
  AND THE INPUT IT WAS GIVEN COULD NOT CONTAIN THE DEFECT.** Worth grouping,
  because it is the same sentence as number 4 and as `queueDepthIs()`, and it
  has now cost three separate guards.

  | # | what the detector could not see | how much of the defect it hid |
  |---|---|---|
  | 6 | a line break inside the fragment | the ONE fragment it most needed to find: "which is terminal", split by the template across two lines |
  | 7 | a double quote instead of a single one | any reintroduction written the other way, which is half of them |
  | 8 | a state the fixture never built | Gate 1's whole sizing panel, on four gates × eleven statuses |

  **Number 7 is the one to remember, because the drill was run and it PASSED.**
  The rule here is that a new guard is confirmed against a real instance of the
  failure — that was done, the defect was reintroduced, and the guard stayed
  green, because the reintroduction used `config("render…")` and the regex
  demanded `config('render…')`. **A drill is a claim about the drill until it
  reproduces the shipped shape**, and a drill written by the same hand as the
  guard inherits the guard's assumptions exactly as a test written by the
  designer inherits the layout's.

  It was found by running the drill twice with different quote characters, and
  only because the sizing work happened to touch both. Number 8 came out of the
  same session by the same accident: drilling a DIFFERENT test — Gate 1's own
  sizing pair — showed the travelling contract staying green about a clause it
  was supposed to cover.

  So the practice, which is cheap and has now paid three times:

  1. **Drill a text-matching guard with the input written the OTHER way.**
     Other quote style, wrapped across a line, different whitespace. If the
     guard is meant to catch a class of thing, the drill has to sample the
     class rather than the one member the author had in mind.
  2. **When a drill passes, suspect the drill first.** Two of this session's
     five drills passed on the first attempt and both times the drill was wrong
     — reverting only the branch that renders when the claim is true, and
     reverting a condition while leaving the voice that guards it in place.
  3. **Ask what states the shared fixture can express**, and add the one the new
     surface needs, in the same change. `pageFixtureFor()` now carries a
     `sized_against_wpm`, beside its `escalation_beat` — which is there for
     exactly the same reason, from exactly the same failure, two passes earlier.


- **`theme-audit --against` was comparing the baseline in LIGHT against the
  current sheet in DARK, and had been since it was written.** It read the
  baseline's tokens from `:root` — which is the light palette — and resolved the
  live sheet with `$dark`. Every rule mentioning a themed token therefore
  differed by construction.

  Found by the only check that can find it: diffing the stylesheet against a
  copy of ITSELF, which reported **159 of 343 rules as MOVED**. A comparison
  tool that finds 159 differences between a file and itself is not merely
  broken — this file leans on it to catch "one mistyped hex in two hundred token
  lines" during the palette split, and a real mistyped hex would have been one
  line among a hundred and fifty-nine false ones. Indistinguishable from not
  being reported.

  Fixed by resolving the baseline with the baseline's own dark tokens. The
  identity case is now 343/343 identical, and a deliberately mistyped
  `--d-panel` names exactly the eighteen rules that use it.

  **The palette-split period is covered retroactively, and it was clean.** The
  pre-split stylesheet survives in git at `a3d64c7` — one palette, `--bg:
  #0e0f13` — and the post-split one at `ffb0f36`. Run with the fix, the split
  itself reports **5 MOVED, 120 identical, 10 GONE, 75 NEW**, and every one of
  the five is a LAYOUT change: `header.top` padding, `main` width, `footer`
  padding, a `th` sticky offset, an added `box-shadow: none`. Every hex in them
  is unchanged — `#282d3a`, `#0e0f13`, `#7c8598`, `#171a22`, `#e0a33a`. Not one
  colour drifted.

  So the claim this file made about the split turns out to be TRUE. It was just
  never evidence: the check that was cited for it could not have shown it either
  way. A correct conclusion reached from an instrument that does not work is
  still a guess, and it is worth separating the two — the split was fine, and
  nobody knew that until now.

  **The general test for any differ: compare the input against itself and
  require zero.** It costs one command and it is the only assertion that cannot
  be satisfied by a tool that is confidently wrong.

- **Container-scoped rules, swept rather than fixed one at a time.**
  `tools/scoped-override-audit.php` asks the question class-audit cannot: not
  "can this rule reach this element" but **"does the element still get this
  declaration when the container is gone"**. It reports CONDITIONAL OVERRIDES —
  a property an element sets for itself AND is given a different value for
  inside some ancestor, so that deleting the ancestor silently reverts it.

  The sweep found 14. Thirteen are benign: the element cannot outlive its
  container (`.rail` inside `.dash`, `.small` inside `.band`), or both values
  are deliberate and the difference is cosmetic (`.actions` gap, `.why`
  margin). One was live and load-bearing — `.still` is 136x76 by default and
  92x52 only inside `.scenetable`, while the grid track holding it is 92px, so
  renaming that wrapper would have left every still overflowing its own column.
  It is `.still.dense` now, a modifier the element carries.

  **Worth teaching the tool rather than leaving as a documented sweep**, and it
  is: the question is mechanical, the answer is a short reviewable list, and it
  found a second live instance on its first run. Two precision passes were
  needed before it was trustworthy — a base rule only speaks for a scoped rule
  when its subject compound is a SUBSET (`.panel.money` says nothing about
  `.card .row-item.money`) and when the tags agree (`tr.warnfill` says nothing
  about a `div`). Both were producing findings between elements that can never
  be the same node, and a tool that cries wolf is one nobody runs.

  Its output is a JUDGEMENT list, not a defect list. Read it; do not count it.

- **A rule that lifts a global cap must live on the element, never under a
  container the layout may remove.** `.alert` caps its measure at 96ch on
  purpose. The only thing lifting it was `.gatecols .alert` — scoped to the
  decision row, which the quiet layout deletes. So on a story past Gate 2 the
  locked banner, the failure alert and every advisory silently snapped back to
  96ch and rendered at about a third of a 1770px viewport, beside a strip and a
  scene table that carry no cap and stayed full width.

  Nothing failed. The override did exactly what it said; it simply had no
  subject any more. `.alert.wide` is the same declaration written as an element
  modifier, so it survives any arrangement, and the advisory list fills the
  width as a grid rather than as one long ribbon — which keeps the reading
  measure the cap existed to protect in the first place.

  **The general form: when a layout can delete a container, every rule scoped to
  that container is conditional on it.** Worth grepping for the next time a
  wrapper becomes optional.

- **An ordering assertion passes when the earlier element is MISSING, and
  `assertLessThan` will not tell you.** `strpos` returns `false` for an absent
  needle, PHP coerces `false` to `0` in a numeric comparison, and
  `assertLessThan($later, $earlier)` is therefore vacuously true for an element
  that has been deleted from the page.

  Every ordering assertion in `ScenesGateLayoutTest` was written that way, and
  the drill is what found it: deleting the failure alert from the quiet layout
  left `test_the_failure_outranks_the_locked_banner` GREEN. Absence read as
  agreement, inside the test written to prevent it — which is why the standing
  rule is to confirm a new guard fires against a real instance rather than to
  confirm it passes.

  `positionOf()` asserts the string is present and then returns its offset, so
  the vacuous case cannot be written. Prefer it to `strpos` in any assertion
  about document order.

- **A story's stored PROMPTS can carry a superseded art style, and nothing
  refuses it.** Gate 2 now reports this — `ScenesGate::styleBlock()` reads the
  block that is byte-identical across every prompt in the story and compares it
  against config — but a report is all it is. There is no guard.

  **It is NOT the reference-sheet staleness item, which is closed.**
  `Character::referenceStyleState()` asks whether a character's reference SHEET
  was drawn in the current style; this asks whether the scene PROMPTS carry it.
  The remedies are disjoint — regenerating a sheet does nothing to the stored
  prompts, and re-drafting the scenes does not touch the sheets — so neither
  check can cover the other, and a green sheet check says nothing at all about
  the prompts.

  Measured on live data: rent-will 0 of 168 prompts carry the configured style,
  my-younger-brother 0 of 186, my-wife 270 of 270. `GenerateSceneImage` sends
  `image_prompt` verbatim and nothing re-applies the style at dispatch, so a
  story drafted before a retune buys stills in the old look for ever.

  The asymmetry is the part to fix or to accept deliberately: a stale SHEET is
  a refusal at asset dispatch, and a stale PROMPT is a sentence on a page that
  an operator can scroll past on the way to the approve button. Left as a report
  because the remedy is free — re-drafting the scenes costs text calls, not
  assets — and because refusing here would block two shipped stories from ever
  regenerating a failed still. But it is the documented-guard shape pointing the
  other way, and it should not be read as covered by the sheet check.

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

- **The word target now comes from the measured rate. It was 18% short on every
  script this pipeline had ever written, and correcting it took three passes in
  a fixed order because only the last one is irreversible.**

  `targetWordsPerAct()` read `render.narration.words_per_minute` — the FALLBACK
  constant, 160, the one whose own docblock says it is "no longer the answer".
  A 35-minute midpoint asked 5,600 words; that narrator reads 197 wpm, so the
  script ran 28.4 minutes before a word of it existed.

  | | target | per act | implied runtime |
  |---|---|---|---|
  | before, en-US single | 5,600 | 800 × 7 | **28.4 min — under the floor** |
  | after, en-US single | 6,895 | 985 × 7 | 35.0 min |
  | after, en-CN single | 6,965 | 995 × 7 | 35.0 min |

  **The order was the whole design.** Column, backfill, then target: steps one
  and two are recoverable and step three is not. Moving the target first would
  have left every earlier story judged against a rate it was never written to,
  with nothing in the record able to say so.

  Four things it deliberately did NOT do, three of them scoped in advance and
  one found by a test:

  1. **`NarrationPace` is not pointed at `sized_against_wpm`.** Its question is
     whether narration reads at the rate we believe; story 9 was sized at 160
     and its narrator reads 197, so a guard comparing audio against the frozen
     figure would find +23% on a 12% tolerance and cancel every batch on a
     healthy story. The column is provenance for judging a SCRIPT, never a
     target for judging AUDIO.
  2. **Nothing re-sizes or regenerates an existing story.** `wpmFor()` reads the
     frozen figure first, so story 9 keeps its 5,600-word budget for ever.
  3. **The other three readers moved with it**, into `ScriptSizing` or
     `NarrationPace::bestKnownWpm()`. A test names the three files still allowed
     to read the raw constant and fails on a fourth — and it had to be drilled
     twice, because the first version required a single quote and a
     `config("render…")` reintroduction walked straight past it. Same shape as
     the line break that hid a claim fragment from `claimsNotEntitledTo`.
  4. **THE PER-VOICE FIGURE ALONE WOULD NOT HAVE FIXED ANYTHING.**
     `providers.default_voice_id` is deliberately null until a channel's
     narrator is locked, so a story created through the console has no
     `voice_id` at act-script time, and `expectedWpm(null, 'en-US')` returns the
     fallback 160. Pointing the target at the per-voice figure would have left
     every new story sized exactly as short as before while the code read as
     corrected — absence reading as agreement, inside the change written to end
     it. `NarrationPace::bestKnownWpm()` asks the locale when it cannot ask the
     voice, and uses only real measurements to answer.

  **And the first version reintroduced the drift it was removing, in the same
  change.** Sizing asked `bestKnownWpm` while the runtime estimate still asked
  `expectedWpm`, so a story carrying an unmeasured voice id was sized at 197 and
  estimated at 160 — two beliefs about one narration. `narrator-us-01` is not
  hypothetical: it is the id the fake invented, and every story from 3 to 12
  carries it. **The grep guard was green throughout** — every caller really had
  stopped reading the constant — because "one reader of the constant" is a
  necessary condition and not a sufficient one. What caught it was an assertion
  that a script written to its own budget lands inside its own window, which is
  the sufficient version and is now a test of its own.

  **The band moved; the target did not move to match a result.** Both are edits
  to a number after a disagreement, and this file's rule separates them: never
  move a target to match a RESULT, always follow a corrected INPUT. 160 was
  never measured against anything; 197 is 186 real scenes. The word band in the
  format section follows the rate, which is what that section says to do.

  ---------------------------------------------------------------------------
  **CORRECTION, MEASURED AFTERWARDS: THIS DID NOT FIX THE RUNTIME PROBLEM. IT
  MOVED IT FROM THE FLOOR TO THE CEILING.**
  ---------------------------------------------------------------------------

  The entry above is right about the input and wrong about the outcome, and the
  wrongness is the familiar shape: the arithmetic was checked and the thing the
  arithmetic is a proxy FOR was not. 6,895 words at 197 wpm is 35.0 minutes —
  true, and it assumes the writer produces 6,895 words. It does not.

  Measured on one act, en-US, current code, at the corrected 985-word target:
  **1,123 words. +14.0%.** Extrapolated across seven acts that is 7,861 words
  and **39.9 minutes against a 30-40 window** — inside it by six seconds of
  arithmetic, at the ceiling rather than at the midpoint the target was designed
  for. One act overshooting in a real story puts it over.

  | | words | runtime |
  |---|---|---|
  | before (c), asked 5,600 | ~5,780 written | 29.4 min — **under the floor** |
  | after (c), asked 6,895 | ~7,861 written | 39.9 min — **at the ceiling** |
  | what (c) was designed to produce | 6,895 | 35.0 min |

  **Why the correction was still right.** The old target was wrong about the
  narrator's reading rate; that was a defect in an input and correcting it was
  not optional. What the correction assumed, silently, is that **the word target
  steers the writer** — and it barely does. Across five story-level
  observations spanning targets 800 to 1,120, the fitted slope is **+0.30**:
  asking for a hundred more words gets about thirty. Story 21 was asked for 800
  and wrote 1,152; the probe was asked for 985 and wrote 1,123. **The target is
  advisory.**

  So (c) closed a real defect and left the runtime problem in place, one side
  over. That is worth writing down rather than letting the entry above stand as
  a fix: **an input corrected on evidence is not the same thing as an outcome
  corrected**, and this file has now made that mistake in the direction where it
  is hardest to see — everything downstream of the change is more correct than
  it was, and the number the operator actually cares about is still outside its
  band.

  The one thing the correction should NOT prompt is moving the target again to
  compensate. Solving `written = f(target)` for the target somebody wants is
  calibration against a five-point fit with one unconfounded observation in it,
  and it would put a number in the prompt that the operator knows is false. Left
  open deliberately; see the generation-variance item.

- **Generation variance is a separate defect and correcting the constant did
  not touch it. Still open.** Story 21 overshot its word target by 44% — 8,065 words asked
  as 5,600 — and that is the only reason a story sized 18% short shipped 36
  seconds OVER the ceiling. Two errors in opposite directions, neither of them
  measured, landing inside the window by cancellation.

  Worth stating plainly because the temptation once the constant is fixed is to
  read story 21 as evidence the pipeline aims high. It does not: nothing bounds
  what the act writer returns against what it was asked for. A 44% overshoot on
  a corrected target would be ~50 minutes, and a 44% undershoot on the current
  one would be 16. The two items are independent — fix the target so the aim is
  right, then bound the spread so the shot lands near it — and fixing only the
  first would move a systematic error into a random one without narrowing it.
  **Nothing here is a case for adjusting the target to match what the writer
  actually produced**, which is the false-success pattern this file names in its
  own words at story 9: never move a target to match a result.

- **`stories.sized_against_wpm` EXISTS, IS BACKFILLED, AND THE TARGET HAS SINCE
  MOVED — in that order, which was the whole design.** Steps one and two are
  recoverable and step three is not, so the column and the backfill went in on
  their own, changing nothing about what any story was sized against, and the
  target moved only once every existing script had its own rate on the record.

  The old entry filed this as low urgency on the argument that en-US and en-CN
  are 1.26% apart, so "what we believe now" and "what this script was sized to"
  could not differ by more than the pace tolerance. **That argument measured the
  wrong pair.** It compared two MEASURED profiles against each other; the gap
  that matters is between the measured figure and the FALLBACK the word target
  is actually written against, which is 160 against 197 — **18.8%**, on both
  shipped stories, right now.

  What is in place:

  - The column is nullable and **NULL means unknown, never 160**. A default
    would make "nobody recorded this" and "this was sized at 160" the same
    value, which is absence reading as agreement — row 3 of this file's own
    false-success table. It is also the CORRECT answer for `sample-story`,
    whose acts were imported from a Phase 0 fixture and were sized against
    nothing at all.
  - `GenerateActScripts::sizedAgainstWpm()` freezes it on first use and never
    re-reads config afterwards, for the reason `locale_profile` is frozen. The
    freeze earns its place on a PARTIAL re-run: `--only=4` re-enters
    `writeActs()` on a story whose other acts were written before some config
    edit, and re-reading there would size act 4 to a different budget from acts
    1-3 and leave no trace of it. Drilled by moving the constant between two
    runs and asserting the story does not follow.
  - **The backfill is five stories, not the two that were noticed.** Stories 8,
    12 and 20 carry generated act scripts as well as 9 and 21, and a story left
    null when the target moves is precisely the unreconstructable case the
    column exists to prevent — so writing the backfill as a pair of ids would
    have fixed the two somebody looked at and left three behind the same defect.
    The predicate is the fact instead: a story has a generated script, therefore
    it was sized at 160.
  - **160 is checked, not remembered.** `env('NARRATION_WPM', 160)` reads 160 in
    every commit that has ever touched `config/render.php`, there is no override
    in `.env` or `.env.example`, and it resolves to 160 today. So every
    generated script in this database was written to a 5,600-word budget by
    construction.

  **The three traps that were named before the target moved, and how each
  turned out.** They are kept because two of them were real and one was not the
  whole story — which is worth as much as the fixes.

  1. **"Do not point `NarrationPace` at the new column."** Correct, and honoured.
     Story 9 was sized at 160 and its narrator reads 197, so a guard comparing
     audio against the frozen figure would find +23% on a 12% tolerance and
     cancel every batch on a healthy story. `expectedWpm()` is untouched.
  2. **"The other three readers must move with it."** Correct, and they did —
     into `ScriptSizing` or `NarrationPace::bestKnownWpm()`. A test names the
     three files still allowed to read the raw constant and fails on a fourth.
     It caught nothing at first because it required a single quote; a
     `config("render…")` reintroduction walked past it until the drill was run
     with both quote characters.
  3. **"The act count follows the word budget."** True, and it needed no change:
     `DEFAULT_ACTS_SINGLE` is already 7 for the reversal phase, so the corrected
     budget and the five-movement arc wanted the same shape.

  **A fourth was not on the list and was the one that mattered.** Pointing the
  target at the per-voice measured figure would have fixed nothing:
  `providers.default_voice_id` is deliberately null, so a story created through
  the console has no voice at act-script time and `expectedWpm(null, 'en-US')`
  answers the fallback 160. The change would have read as applied and left every
  new story exactly as short. `bestKnownWpm()` asks the locale when it cannot ask
  the voice.

  **And a fifth was introduced by the fix and caught by a test.** Sizing asked
  `bestKnownWpm` while the runtime estimate still asked `expectedWpm`, so a story
  carrying an unmeasured voice id — `narrator-us-01`, which every story from 3 to
  12 carries — was sized at 197 and estimated at 160. The grep guard stayed green
  the whole time, correctly: one reader of the constant is a NECESSARY condition
  and not a sufficient one. What found it was asserting that a script written to
  its own budget lands inside its own window.

  Nothing is surfaced on any page. That was deliberate while every story read
  160 and is now a real gap: story 9 is frozen at 160, a story written today
  gets 197, and an operator comparing two word targets has nothing on screen
  that explains the difference. Worth showing at Gate 1, beside the outline the
  budget produced.

- **CLOSED IN THE CONSOLE, STILL OPEN IN THE TERMINAL: the preflight's notes
  now reach Gate 2 and still not `--estimate`.** The original finding was that
  `assets:generate --estimate` prints the itemised bill and exits BEFORE the
  preflight runs, so the pace expectation, the aligner probe and the style
  fingerprint appeared only on a real dispatch — the one surface whose entire
  job is to inform a spending decision being the one without them.

  Gate 2's **Check without spending** button closes that for the console, and
  closes more than was originally asked: it runs the same `PreflightAssetDispatch`
  the money press runs, so it also carries the narrator and allowance checks that
  did not exist when this was written. The allowance figure in particular is not
  obtainable any other way — a cost estimate answers "what will this cost", and
  on a subscription the marginal answer is $0.00 whichever side of the limit the
  run lands.

  `--estimate` is unchanged and still exits early. Lower priority now that the
  button exists, and worth doing for parity: the terminal path is the one used
  for a scripted or limited run, which is exactly when a shortfall is easiest to
  miss.

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

Twelve times now the app has reported success while something was silently wrong.
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
| 9 | `/renders`: three workers up, none stale, footer saying "nothing running — this page is not refreshing itself" | The panel was frozen at whenever the page loaded. Two of the pids no longer existed; the page stops refreshing exactly when workers get restarted |
| 10 | Worker health: `assets` ABSENT, nothing listening | The worker was mid-job. `Looping` is silent during a job and `JobProcessing` fires once before it, so any job longer than the 300s TTL aged its own worker out — a 40-minute mux, or 270 image calls at ~53s each |
| 11 | The self-restart's stated bound: "never fires while the queue holds work, so a batch cannot be split across two code versions" | The bound was evaluated per worker; the stop is a machine-wide broadcast. An idle worker on an empty queue stood a busy one down and split a 10-job batch across two code markers. The busy worker's own guard was correct and never fired — a sibling's did |
| 12 | 943 tests, four clean audits and eight drills, green on a schema that could not write the value the code had just learned to produce | The test database's ENUM column is BUILT from the enum under test by `RefreshDatabase`, so it agreed by construction. `narra` and `narra_test` held different columns. An 87-second claude-opus-5 outline was billed, its ledger row truncated away, and its story left with no acts |

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

**A ninth, and it is the first one found in a REASSURANCE rather than in a
number.** `/renders` drops its meta refresh whenever nothing is running, and
its footer says so: *"Nothing running — this page is not refreshing itself."*
Every word is true. It reads as the all-clear and it is a warning — that
everything above it, including the worker-health panel, is frozen at whenever
the page last loaded.

The two halves compound. The page stops refreshing precisely when the queues
are idle, which is precisely when workers get restarted; so the state most
likely to go stale is the one the page is guaranteed not to notice. An
operator comparing that panel against `Get-CimInstance` finds pids that no
longer exist and concludes the registry is holding dead entries. It is not —
entries expire on `seen_at` correctly, and there is a test that fabricates a
4h33m-old entry and asserts the panel reads `absent`. The registry was right;
the PAGE was old, and nothing on it said so.

Same shape as an unreadable quota reading as fine: the absence of a fresh
reading presented as a fresh reading. The fix is the same too — say what you
do not know. Every reading now carries `read_at`, the browser ages it, and past
60 seconds the stamp goes amber and says the page has stopped refreshing.
Only the clock in the browser can answer this: a rendered page cannot know how
long it has been open, so the server must hand over the timestamp rather than a
verdict.

**And the panel now prints the PIDs.** Every other number on it comes from our
own bookkeeping; a pid is the one fact an operator can put beside the operating
system and see agree or disagree. Rule 3 — keep one number that we did not
compute — applied to a health panel rather than to a ledger.

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
