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

3c. **ASK WHAT THE NARRATOR MUST PRODUCE IN PERSON. WHEN A DOCUMENT CAN
   PRODUCE THE WITHHELD INFORMATION, THE WRITER LEAVES THE NARRATOR 800 KM
   AWAY AND THE PUBLIC PAYOFF ARRIVES AS HEARSAY.** Story 28 was watched
   end to end with three structural complaints, and all four rendered
   phase-era stories were measured against them before anything was
   proposed. The sharpest finding was not on the list. It is the difference
   between story 25, which works on every measure, and stories 23 and 28,
   which run the identical arc and do not:

   | story | exposure | narrator present | who produces the withheld information |
   |---|---|---|---|
   | 23 | 34:40–39:06, told secondhand | no, 800 km away | a developer's account manager and a roommate |
   | 25 | 32:56–37:32 | yes, raises his hand at the service door | the narrator: a trust vote "requires the settlor physically present" |
   | 28 | 38:20–40:30, told secondhand | no | an automated bank notice in a WeChat group |

   Story 23: *"I was eight hundred kilometers away that night, and I did not
   hear about any of it for nine days."* Story 28: *"my mother had flown down
   for the new year and told me the whole thing at my kitchen table."* Both
   spines wrote the narrator out of the room by letting something else carry
   the fact, and the search prompt then told the writer the narrator "is not
   watching", so it left him where the departure put him. Story 25's
   `withheld_information` needs the narrator's BODY, so the writer brought
   him back, uninvited, in a work jacket, and it is the strongest moment in
   the four videos.

   **The correction, written the way it was accepted.** Indifference over
   revenge held up: 25's narrator does not retaliate, he raises his hand.
   What did not hold up was reading "gone" as "absent from the payoff". The
   rule is not that she finds him. The search fails, and he chooses the
   moment he is seen — at the exposure, in the room, unexpected — and she
   reaches him afterwards because he came. Nothing about the search cost is
   weakened; the reference channel's own framing, "never expecting to see
   me", is a narrator turning up. So the arc is right and the staging was
   passive, and the passivity was decided one field upstream of the exposure.

   **What is in place.** `withheld_information` is asked for with "AND WHAT
   THE NARRATOR MUST PRODUCE IN PERSON". `stories.narrator_at_exposure` asks
   how they come to be in the room and what only they produce there;
   `ValidateOutlineSpine::checkNarratorAtExposure()` refuses a field that
   reads as the search succeeding (whole-word markers behind the same
   negation window the announcement check uses, because "she did not find
   me, I came" is the good case and contains the marker) and then checks it
   against `withheld_information` by sentence overlap, reporting WHICH
   sentence is produced. The refusal-phase prompt says the narrator is in the
   room and chose the moment; the search prompt says the search fails and
   the narrator "does not intervene" rather than "is not watching", because
   knowing the banquet date is how 25's narrator caught his train. The
   genre guidance's fourth movement reads that way now too.

   **THE SECOND FINDING: STORY 28'S PRESENT-DAY BETRAYAL LANDS AT 20:18
   BECAUSE TWO OF ITS THREE ESCALATION ACTS STAGE 2015 AND 2017 IN FULL.**
   Measured off the scene offsets the render produced:

   | story | runtime | present-day action begins | inciting betrayal staged | establishment after the hook |
   |---|---|---|---|---|
   | 21 | 40:36 | 0:00 | 1:50 | ~1.5 min |
   | 25 | 39:08 | 1:04 | 1:04 | ~0 |
   | 23 | 40:57 | 10:38 | 15:01 | 9.3–13.7 min |
   | 28 | 43:27 | 17:31 | 20:18 | 16.6 min, 38% |

   The premise that the betrayal was never dramatised was wrong: it is
   staged, and on 28 it is staged for 13:15. The hook opens on it and obeys
   every rule it has, then act 1 stages the 2015 betrayal (2:50–6:21) and the
   whole of act 2 is the 2017 one (7:47–17:31). The outline allocated it —
   act 2's summary reads *"Act 2 tells the second betrayal in full"* — and it
   was read at Gate 1 and approved, because nothing asked an act WHEN it was
   set. Two instructions rewarded staging: "name the amounts, the dates, the
   rooms, the exact words" and "anything vague here gets invented later".
   Two drivers, not one: a history-shaped premise ("the first time... the
   second time... the third time"), and a spine that dates the antagonist's
   line in the past, so the act that stages its first saying goes to history
   (28's 2017 housewarming toast, 23's Spring Festival table, and story 26's
   2019 housewarming despite a present-tense premise).

   `acts.timeframe` is the outline writer's declaration per act, `present`
   or `prior`, from the schema — the expression finding's lesson, a field's
   surface being 24x a prompt rule's — and `checkTimeframe()` refuses a prior
   act as a PROBLEM quoting the summary's own first sentence, because the
   script is written from the summary and nothing else. The genre guidance,
   the escalation slot guidance, the act system prompt's "name the dates"
   line and a per-act `timeframeInstruction()` all say the same thing: a
   prior incident is cited in one sentence with its date inside a present-day
   act, and the line the antagonist said years ago is quoted there and
   STAGED at its most recent saying. **Story 25 is the model** — "a wife who
   earns more" is quoted at 0:18 and staged at 9:17 in a present-day dinner,
   so the line still lands twice. Story 28's housewarming toast becoming one
   sentence is the right trade: the exposure replays it anyway.

   **Legacy, in two ages.** Stories 9, 12, 20 and 21 predate the reversal
   phase and are covered by that one warning; the new field is `later` there.
   Stories 22 through 28 were outlined with the phase and before either
   question, and `predatesTimeframeAndPresence()` reports that once — both
   questions, one sentence — with the field `absent` rather than `missing`.
   Typing a narrator-at-exposure in by hand ends the excuse and the timeframe
   check comes back asking for every act to be marked. Nothing is
   regenerated. `acts.timeframe` is editable at Gate 1 where `phase` is not,
   because the repair for a refused act is to rewrite its summary as
   present-day and then SAY so, and an operator with no way to clear the
   declaration could not approve without regenerating.

   **The consumer question was asked in the change, for both fields:**
   `actPrompt` (the outline block carries `[ESCALATION · PRESENT]`, the spine
   block carries "How the narrator is in the room for it", and the act being
   written gets the timeframe instruction), `sceneContext` (`SET IN:`, so the
   scene writer does not draw a flashback still for a cited sentence),
   `FakeScriptWriter` records both at both calls, `StoryFork` copies both,
   `SchemaEnumDrift::COLUMNS` names the column, and the Gate 1 fixture is
   asserted unable to hold either state while `storyWithBothPayoffs()` is
   asserted able to. Four drills, each red in a different test: the check
   call removed, the DTO field dropped at the act call, the instruction
   dropped from the prompt, and the negation window replaced by a bare match.
   The scene arrival test reads the field off the model and cannot go red on
   the prompt line alone, so the prompt line has its own reflection
   assertion.

   **Tested on one new story, 29, written to carry both traps.** An en-CN
   premise shaped like 28's ("The first time she moved my things... The
   second time...") with a withheld fact a document could carry (a deed in a
   bank box). The outline came back with every act `present`, a
   `withheld_information` that answers the question in its own words —
   *"The deed can be mailed; my signature and my refusal have to walk in"* —
   a `narrator_at_exposure` that opens *"The search has failed"* and walks
   in uninvited with the bag from the box, and a refusal that opens *"She
   reaches me in the corridor outside the private room, because I came, not
   because she found me."* Gate 1: zero problems, zero warnings, and the
   presence badge names the sentence produced. Act 1 opens on the hook,
   is in the present by its second paragraph, and cites 2016 in ONE sentence
   — *"The first time she moved my things into that room I was twenty-six,
   it was the autumn of 2016, and I carried every box back myself before
   midnight"* — where story 28 spent 3:31 staging the same beat.

   The six acts came back at 7,615 words, 38.3 minutes at 199 wpm, every
   one of them in the present: act 2 opens on a paper train ticket for
   April 5, act 3 on the May dinner, and the 2015 and 2016 mentions across
   all six are citations, never a scene. The refusal act is the shape 25
   has and 23 and 28 do not. The narrator walks into the banquet during the
   toast — *"Nobody saw me for maybe four seconds"* — turns the lazy Susan
   so the bag comes round to the head table, says *"Mother. Say it again.
   The notary is here,"* and she does; then the narrator lays out the
   certificate and the transfer slips herself and tells the notary, in front
   of forty-one people, that he will not be getting her signature. The
   exposure is a scene the narrator lives, not a report. The antagonist
   reaches her in the corridor afterwards, by the service trolley, and each
   of four refusals names the moment it answers: March, Qingming, New Year
   2019, the eight years. It ends within a few sentences.

   Spend for the whole test, outline and six acts: $1.34 across eight calls,
   one of them an act 3 the locale guard refused for "school district" — a
   US institution on the en-CN profile, unrelated to this change, re-run
   once. Story 29 is at `scripted` with no scenes, no assets and nothing
   paid beyond text, so it is free to keep as the first story written under
   both rules or to discard.

   **One number from that run to keep in view.** The outline billed 12,001
   output tokens at the configured MEDIUM effort for 17,231 characters of
   stored text, 1.4 chars per token — so roughly 7,000 tokens were reasoning,
   and the call sat at 75% of the 16,000 ceiling. The medium-effort entry
   below says a medium outline "measures under a third" of that ceiling on
   story 28; this one did not, on a prompt that grew by ~600 input tokens
   and asks two more questions. One observation, not a trend, and not acted
   on: the remedy's own clause says a truncation at the corrected setting
   is new information to be recorded before retrying, and this is the
   reading before the truncation.

   **The third complaint is measured and held.** The accomplice — the
   premise names him in all four stories — shares zero scenes with the
   narrator in 23, 25 and 28 and never speaks to him; the spine has no column
   for him and nothing requires the scene. Story 21, the pre-phase story, has
   the scene this genre is built on and it happened by accident: at 21:54
   Fang Zheng puts a hand on the narrator's shoulder and introduces him to a
   buyer as *"Yiran's family driver, very reliable"*, in front of nine
   people, and the wife does not correct him. That is the template. It is
   held until the two changes above are measured on a new story, because it
   needs the present-day escalation room that the timeframe rule creates —
   28 had one present-day escalation act and there was nowhere to put it —
   and because it has no model in the corpus beyond that one scene.
   **Built in 3g, and larger than this template**: Fang Zheng's line is one
   beat of the roughly twelve the second reference gives its accomplice.

3d. **THE CHAPTER IS A UNIT UNDER THE ACT, AND THE WRITER'S NATURAL 1,100 WORDS
   IS THE THING THAT FITS IT.** Measured on 2026-09-13 against a working video
   in this niche (34:46, 46.7K subscribers): it announces fourteen chapters,
   about 2:29 each, plus epilogues. Our four rendered stories:

   | story | acts | shortest | longest | mean | first re-hook after the hook |
   |---|---|---|---|---|---|
   | 23 | 6 | 5:54 | 7:40 | 6:49 | 7:21 |
   | 25 | 6 | 6:12 | 6:48 | 6:31 | 6:45 |
   | 28 | 6 | 5:58 | 9:44 | 7:15 | 7:47 |
   | 29 | 6 | 6:02 | 7:06 | 6:22 (est.) | about 7:06 |

   Chapters 2.6 to 2.9 times longer, a third as many re-hook points, and the
   first one at seven minutes on a format whose one measured failure is a
   vertical retention drop inside the first three minutes.

   **Fourteen acts was the obvious fix and it is the wrong one.** The act
   writer returns ~1,100 words whatever it is asked (slope +0.30, recorded
   under the sizing correction), because the act prompt's machinery — a
   beat, a re-hook, staging, exact words and dates, a summary — is what
   produces that length. Fourteen acts of it is a 75-minute video, fourteen
   sequential calls each carrying up to thirteen running summaries, and Gate
   1 with fourteen panels to read. Every number keyed on the act count would
   re-derive, and the phase plan has already moved twice.

   So the act stays the unit the script is WRITTEN in and the chapter is what
   an act is returned AS: two or three per act, each with a title and its own
   re-hook, the act's `script` being their texts joined. Six acts of the
   natural length is about fifteen chapters of ~440 words, 2:15 each at 197
   wpm. **This is the first time in this project a measurement was designed
   around rather than fought**: the length nobody could move stops being a
   defect and becomes the thing the chapter fits.

   What holds it up, and where:

   - `chapters` — `act_id`, `sequence` WITHIN the act, `title`, `rehook_line`,
     `first_sentence`, `start_ms`/`duration_ms`. The boundary is a 1-indexed
     sentence offset into the act script, in the same `SentenceSplitter`
     unit `DraftScenes` cuts scenes in, so nothing holds a second copy of
     the prose and a chapter and a scene can be compared in one resolution.
     Sequence within the act so `--acts-only=4` replaces that act's chapters
     with no story-wide renumber. `scenes.chapter_id` is nullable and set
     null when its act is rewritten.
   - The act schema returns `chapters: [{title, rehook_line, text}]`. The
     count (2-4), the per-chapter word floor (150), the title bound (100)
     and the join check are enforced in `GenerateActScripts` AFTER the cost
     row, exactly where the summary bound is, because structured outputs
     honour neither `minItems` nor `maxLength`. The join check is the one
     that is not obvious: chapter texts joined must split to the same
     sentence count as the chapters split one by one, or a chapter that did
     not end on a terminator has merged into the next and every boundary
     after it is one sentence late in every consumer downstream.
   - Every consumer, wired in the same change and each with an arrival
     assertion, per this file's own rule: the scene prompt states the
     boundaries and a scene must not straddle one (a straddler is filed
     under the chapter its FIRST sentence is in and NAMED on the job row —
     a few seconds of picture, not a re-bill); `ConcatRenderJob` times
     chapters from scene offsets exactly as it times acts; `YoutubeMetadata::
     chapters()` reads chapter rows when the story has them and acts when it
     does not; Gate 1 lists them under each act, read-only; `story:write`
     prints the count; `story:fork` leaves them behind with the script;
     `MergeAdjacentScenes` refuses across a boundary the way it refuses
     across an act. Three drills, each red in its own test.
   - `config/chapters.php`. `announce` is OFF: whether the reference speaks
     its chapter titles as narration is a question for the transcript, which
     had not reached this session when this was built. On, the act writer is
     told to open every chapter with the spoken title and the "no chapter
     markers in the prose" rule is lifted for that sentence.

   The `SchemaTest` assertion that no `chapters` table exists was rewritten,
   not deleted: the rule it protected — timestamps written once, by the
   render, never copied — still holds. The chapter is the unit now and the
   act is its container.

   **Two rules that were derived from a channel's framing and applied as
   structure for four stories, corrected the first time actual material was
   read.** "The search fails and there is no contact until the exposure" and
   "no epilogue, end within a few sentences of the last refusal" both came
   from one reference TITLE — *"never expecting to see me and our son 5
   years later"* — and were written into `genreGuidance()`, `endingFor()`
   and the spine checks as the shape of the genre. The first transcript in
   the niche that was actually read runs the other way on both: the
   antagonist and the narrator are in the same scene five times after the
   betrayal, at 9:53, 16:43, 18:21, 27:00 and 28:27, each one worse for
   her, and the video closes on two point-of-view epilogues, hers alone
   twenty years on. Measured against those five timestamps, our no-contact
   rule produced an 11-to-23-minute stretch with nobody on screen but the
   narrator in every one of the four stories, and the no-epilogue rule ended
   every one of them on a corridor plea.

   A title is a promise about a payoff; it says nothing about what happens
   between minute ten and minute thirty. **A rule derived from framing and
   enforced as structure inherits the framing's resolution**, which is the
   axis finding one layer out: the checks built on those rules were
   flawless about the shape they described and silent about the shape the
   niche actually runs.

   **THE TRANSCRIPT, READ. It is at `docs/refence/transcript-3446.txt`
   (the folder name is as it was saved).** The five figures the report was
   built on, aligned against the captions:

   | figure | reported | in the captions |
   |---|---|---|
   | the partner enters | 12:16 | named 12:18, on screen 13:04 |
   | encounter 1 | 9:53 | 9:53-11:35, the box of gifts; "Don't touch me", "Get lost" |
   | encounter 2 | 16:43 | 16:43-17:21, the campus square; no words, she joins the condom line |
   | encounter 3 | 18:21 | 18:21-19:50, the cafeteria; "I'm not into threesomes" |
   | encounter 4 | 27:00 | 27:00-28:24, outside his office in the other city; she kneels |
   | encounter 5 | 28:27 | 28:27-31:54, the café; "how can you prove that" |
   | chapters | 14 at ~2:30 | 14 at 1:02, 4:29, 6:34, 8:16, 11:35, 14:37, 16:17, 18:18, 19:54, 22:35, 25:30, 26:25, 28:27, 31:58 — mean 2:13, range 0:22 to 3:31 — plus two point-of-view extras at 32:20 and 34:05 |

   And a sixth encounter the count missed because it IS the betrayal: 1:31
   to 4:25, the reunion dinner, where the narrator answers back inside the
   scene — *"Marry you my ass"* at 2:56, *"breakup dinner or a date with
   your new boy toy"* at 3:30 — and still loses the round.

   **The chapter announcements are spoken.** The captions carry "chapter 1"
   inline in the stream at 1:02 (*"...You picked the perfect time to be
   awesome chapter 1 With graduation approaching..."*), lowercased the way
   the ASR lowercases everything it hears, and the same for every chapter
   through "chapter 14" and "Extra 1 — Sophia's POV". A bare number, no
   title. And the cold open comes BEFORE "chapter 1": sixty-two seconds of
   hook, then the announcement. `chapters.announce` is on, the prompt asks
   for the number in words as its own sentence, the title stays metadata,
   and act 1 is told the announcement follows the five beats.

   **The departure question, answered: leaving is not what the middle is
   built on. It is what makes her position unrepairable at the end.** The
   reference has TWO departures. The BREAK is announced, early and plainly:
   he calls her parents and cancels the wedding at 8:46 (25% of runtime),
   tells her to her face *"we broke up... I hope we never have anything to
   do with each other"* at 10:45. The RELOCATION is unannounced and late:
   25:30 (73%), *"asked everyone who knew where I was going not to tell
   her"*, blocked on every platform at 26:11. Between the two, fourteen
   minutes — 40% of the video — the narrator is still in the same city,
   visibly with someone new, and she keeps running into that: encounters 2
   and 3 exist because he did NOT leave. After the relocation the search is
   four lines long (28:36-28:43) and it SUCCEEDS, and the success is the
   cost — she finds him kissing someone on a street in another city and
   kneels in front of a crowd. Then the café: *"Everything she had done back
   then had become an irreversible losing move the moment I decided to walk
   away. Whether she had actually remained faithful could no longer be
   proven"* (30:56). So leaving did one thing, and it is the payoff's
   mechanism rather than the middle's: it converted a fight into a fact
   she can no longer argue with. The middle is carried by contact, and by
   the partner.

   **Item 2, built.** `genreGuidance()` movements 1-4, `endingFor()` for
   the escalation, search and refusal phases, the outline prompt's
   `narrator_at_exposure`, `departure`, `reversal_beats` and `refusal`
   lines, `ActPhase::guidance()`, the scene-context phase label, Gate 1's
   help text, and three comments that restated the rule. The escalation
   act still ends worse off on the LEDGER — `endsWorseForNarrator()` is
   unchanged and its docblock now says it is about the cost — and the
   narrator answers back in every scene the antagonist is in: one line,
   funny, exact, changing nothing about the cost. The search phase puts
   them in the same scene at least once per act, on her initiative or by
   chance, in front of people, and the narrator's own life is on screen
   rather than a paragraph. The search may succeed; the finding costs her
   and the scene stays the narrator's. `checkNarratorAtExposure()` no
   longer refuses a found narrator: it records the shape as a note and
   judges the field on what the narrator produces, which is the half of
   the rule the transcript confirmed. `ContactThroughTheMiddleTest` holds
   every one of those sentences by reflection.

   **What item 2 cannot do on its own, stated so the probe is read
   correctly.** The reference's middle is carried by the partner: two of
   the five encounters are about Willow on his arm, and the fourth is her
   seeing him kiss someone. Item 5 is held until the probe measures, so
   story 30's middle has contact and a narrator answering back and nobody
   beside him. That is a known gap in the probe, not a finding about the
   rule.

   **The register, measured against the transcript, and a deliberate
   deviation.** The reference is stronger than mild: *"Fucking
   disgusting"* (4:04), *"What the fuck was wrong with her"* (11:35), *"why
   the fuck did you come sit here"* (19:09), *"a bitch like this"* (3:18).
   Nothing in its first thirty seconds, which is the one place YouTube
   reads hardest. The prompt keeps MILD on the ad-suitability argument —
   the format exists for mid-rolls — and that is a choice made against the
   reference, recorded as one, and reversible in one paragraph if the
   measured cost of mild turns out to be the audience.

   **THE PROBE: STORY 30, A FORK OF 29'S PREMISE, RE-OUTLINED AND WRITTEN
   UNDER ITEMS 1, 2 AND 3 TOGETHER.** $1.23 across eight calls, one of them
   refused. Six acts, 6,832 words, 34.3 minutes at 199 wpm — in window.
   Measured with the same counts as the first report, at act level (no
   scenes exist, so runtime positions are cumulative words at 199 wpm):

   | axis | story 29 (same premise, old rules) | story 30 |
   |---|---|---|
   | encounters after the betrayal, in person | 5 (acts 2-4 and the banquet evening), narrator complies in all but the last | 8: acts 2, 3, 4 one each with the narrator answering back and still losing; act 5 two; act 6 two |
   | first encounter that goes AGAINST her | ~32:10 of 38:15 (84%) | the registry hall at ~25:30 of 34:20 (74%), then the tea shop at ~27:00, the banquet at ~29:30, the corridor at ~32:30 |
   | longest stretch with no contact | ~11 min | ~3.5 min, act 4's leaving to act 5's registry |
   | narrator lines, heuristic | 45 | 36, none of them "all right" or "Yes, Mother" |
   | "Yes, Mother" | 6 | 0 |
   | "I want to be honest/exact/fair" | 4 | 0 |
   | profanity, mild or strong | 0 | 0 |
   | chapters | 0 (6 acts, 6:22 mean) | 12, two per act every time, mean 569 words = 2:52 |
   | spoken chapter numbers | 0 | 8 of 12 |

   The lines the register asked for arrived, dry rather than crude, and the
   writer used none of the mild profanity it was allowed: *"The soup needs
   somebody with no seat. I'm the only one here who qualifies."* *"I've been
   home four minutes and my apartment already has a job."* *"I'd love to
   sign away something that was never mine. Truly. Point me at the line and
   I'll put down whatever name you've been using."* *"It's a lovely room.
   Enjoy the fish."* *"You're in seat forty-seven. I'm number twelve. You've
   got time to think about that."* *"That floor is nine degrees and you have
   a bad knee. Get up. It isn't the room."* And the refusal's last line is
   the "loss she can no longer repair" the prompt asked for: *"Tell me how
   you give him back those eight years. You can't. Neither can I. That's
   why there's nothing to come back to."* The search phase does what the
   contract now says: she finds the narrator through the building office
   receipt, sits down beside her in front of forty people, and the clerk
   tells her "the mother of the eldest son" is not a category; two days
   later she kneels on wet stone in Pingjiang and two customers film it.

   **Five things the probe found that the counts would not have, in the
   order they matter.**

   1. **Act 1 did not use the stored hook, and two of its five beats are
      missing.** Story 29's act 1 opens on `stories.hook` verbatim. Story
      30's opens mid-scene on the betrayal (beat 2, right), quotes the
      justification (beat 3, right), and has NO cold action and NO promise
      of the departure — beat 4's place is taken by an answer-back, which
      is the new register instruction colliding with the hook's own "not a
      confrontation" rule inside the one act that carries both. Two
      instructions now argue in act 1 and the newer one won. One
      observation; the fix, when it is made, is to say in `hookInstruction()`
      which of the two the first thirty seconds obey.
   2. **The spoken chapter number was dropped in exactly the two acts with
      a special opening instruction** — act 1 (the hook) and act 4 (the
      departure). Acts 2, 3, 5 and 6 spoke both of theirs, numbered
      correctly across acts. Nothing checks the announcement at Gate 1; a
      chapter whose first sentence is not "Chapter N." should be a warning
      there, and is not yet.
   3. **The writer chose two chapters per act every time, including at
      1,195 words.** The prompt states "at this act's length that is 2
      chapters" from the 985-word target, and the writer obeys the stated
      number rather than the length it actually wrote. Mean chapter 2:52
      against a 2:30 target and the reference's 2:13. The projection should
      be made from the natural length (`ScriptSizing::naturalActWords()`)
      rather than the target, which at 1,123 words rounds to 2.3 and would
      still say two — so the honest fix is to ask for three on an act
      whose target is the natural length, or to say "2 or 3, and 3 when the
      act runs past a thousand words". Not changed in the probe.
   4. **The act summary bound fired for the first time on a real act, and
      the distribution had already moved before today.** `Act::SUMMARY_MAX_
      CHARS = 3000` was derived on 61 acts with a maximum of 2,026. Story
      29's summaries, written this morning under the timeframe and presence
      prompt, ran 1,920 to 2,666; story 30's ran 2,448 to 2,954 and act 3's
      first attempt came back at 3,121 and was refused, billed ($0.15), and
      passed on the retry at 2,742. Each prompt addition asks the summary to
      carry more ("what was said" now includes the narrator's lines). Per
      the bound's own docblock this is information about the writer and not
      a reason to move the number; the lever is the summary instruction —
      "3-5 sentences" is being read as three paragraphs — and it was not
      touched in the probe.
   5. **The narrating-the-narration ban was obeyed on its listed phrases and
      the tic came back in a new coat.** Zero "I want to be honest"; five
      "I want you to understand / know / have / hold onto that number".
      Story 29 had none of that form. A ban written as a list of phrases is
      matched as a list of phrases, which is `CharacterTextGuard`'s lesson
      about `weathered` one field over; the form to ban is the narrator
      addressing the listener about how to read the narration.

   Two further readings, on the record: the search act's "the narrator's own
   life is ON SCREEN, not a paragraph" produced a paragraph — the rented
   room, the noodles, Grace on Sundays, sleeping through the night — which
   is what a middle with nobody beside the narrator has to offer, and is
   the gap item 5 is held for. And the outline billed 13,269 total tokens at
   medium effort, about 11,000 of them output, 68% of the 16,000 ceiling:
   the second medium outline in a row above two-thirds, which the ceiling
   watch says to treat as a trend on the third. **The third reading arrived
   on story 32 and it BREAKS the trend rather than confirming it: 4,832
   output tokens, 30% of the 16,000 ceiling, on a prompt that had grown
   again.** Three readings are 75%, 68%, 30%. Nothing is raised, and the
   watch is closed rather than left standing, because two readings that
   agreed and one that does not is variance and not a trend — which is the
   answer the watch existed to get.

   **CORRECTED 2026-09-19: THE 68% NEVER HAPPENED.** The ledger row for story
   30's outline is 8,004 input and **5,265 output** tokens: 13,269 is the
   TOTAL, and "about 11,000 of them output" was an estimate that read the
   total as output. 5,265 is 33% of the ceiling, and 5,263 of it was the
   outline text itself (counted exactly from the archive), so that call did
   not reason at all. The real readings were 75%, 33%, 30% — the watch closed
   on the right answer from a wrong middle number. It was reopened on story
   37's 91% with the text and the reasoning separated: see the ceiling entry
   dated 2026-09-19.

   Story 30 is at `outlined` with six scripts and twelve chapters, no cast,
   no scenes and no paid asset, so it is free to keep as the first story
   written under all three items or to discard.

   **FINDINGS 1, 2, 3 AND 4 ARE BUILT; 5 IS THE PROMPT HALF ONLY. Each has
   its own entry under "Where bugs actually live" and the four headlines
   are:** act 1 is the only act where three opening instructions meet and
   nothing had asked whether they can all hold at once, which is why a
   contract that was built and measured lost to a prompt edit made
   elsewhere; a stated FIGURE steers weakly and a stated COUNT steers
   absolutely, which is why the chapter count moved from a number in the
   prompt to a division the writer does against the text it wrote; a ban
   written as a list of phrases is always one rewrite behind, so the
   narrating ban names the move; and a chapter that never says its number
   is invisible in the database, so Gate 1 reads the prose. Finding 5 is
   corrected in the prompt and has NO mechanism behind it — that is
   deliberate and said out loud in its entry, because a request with no
   invariant reads as a guard while doing nothing.

   **Two things carried forward as watches rather than acted on.** The act
   summary bound fired for the first time on a real act and every prompt
   addition since has asked the summary to carry more — this change adds
   nothing to what a summary must hold, but the pressure is one-way and
   `Act::SUMMARY_MAX_CHARS` is not to be moved to fit a result. And the
   outline ran at 68% of its ceiling, the second medium run in a row above
   two-thirds; a third is the trend, and it should be RAISED then rather
   than absorbed. Neither is touched here.

   **THE SECOND PROBE: STORY 31, A FORK OF STORY 30'S OUTLINE, WRITTEN UNDER
   FINDINGS 1-4.** $1.55 across eight act calls, six kept and two refused by
   the summary bound. **The outline was deliberately NOT regenerated** and
   that is the experiment rather than a saving: all four changes are in the
   act prompt, and `story:fork` exists to hold an outline fixed while one
   thing varies — comparing acts written against two different outlines
   "measures nothing", in that command's own words. The premise chain is
   unbroken, 29 -> 30 -> 31, and story 30's `hook` travelled with the fork,
   which matters because the hook is the field act 1 dropped.

   | | story 30 | story 31 |
   |---|---|---|
   | act 1 opens on the stored hook | no — invented its own opening | **yes, verbatim** |
   | hook beats present | 3 of 5 — no cold action, no departure promise | **5 of 5** |
   | chapters | 12 — two per act, every act | **19 — 3,3,3,3,4,3** |
   | chapters that speak their number | 8 of 12 | **19 of 19** |
   | mean chapter | 569 words, 2:52 | **465 words, 2:20** (reference 2:13) |
   | `rehook_line` holding only "Chapter N." | 8 of 12 | **0 of 19** |
   | narrating-the-narration moves | 5 | **1** |
   | Gate 1 | 5 warnings | **1** |
   | the registry hall, the first encounter that costs her | 26.2 min of 34.3 (76%) | 33.4 min of 44.4 (**75%**) |
   | words / runtime at 199 wpm | 6,832 / 34.3 min | 8,835 / **44.4 min** |

   Act 1's opening is the whole of finding 1 answered in one paragraph: the
   stored hook verbatim, one sentence of setup, the betrayal at the kitchen
   table, the justification quoted — *"A woman who married up should be
   grateful for the company," she said* — then **a cold action and not an
   answer-back**, *"I said that sounded fair, got up, rinsed my bowl, and set
   it in the rack"*, then the departure promised, *"started counting the days
   until the Tuesday nobody would be able to find me."* Then, on its own line,
   **"Chapter one."** — after the hook, where the reference puts it. The
   answer-back arrives on the next page, in the same act, where it belongs:
   *"I said that in 2016 she had already moved my clothes and my books into
   that same small room while I was at work."* Both rules held, in the act
   that could previously only obey one.

   **THE SIDE EFFECT, AND IT IS THE BIGGEST NUMBER ON THE PAGE: ACTS GREW 29%
   AND THE VIDEO IS NOW OVER THE CEILING.** 1,139 words per act became 1,473,
   and 34.3 minutes became 44.4 against a 30-40 window. Nothing asked for
   more words; what changed is that an act is now cut into three chapters
   instead of two, and a chapter costs a spoken number, a re-hook and a
   boundary. **The writer's "natural act length" turns out not to be a
   property of the writer — it is a property of how many openings the act is
   asked to write**, which is a correction to `naturalActWords()`'s own
   docblock: 1,123 words was measured at two chapters per act and is not a
   constant across chapter counts.

   By this file's own rule that is acceptable and it is not invisible. The
   floor is a preference and the 8-minute mid-roll threshold is the only law;
   the reference channels run 44 and 54 minutes; over the ceiling costs
   nothing measurable and under it costs ad density. **The target does NOT
   move to match this**, and `story:write` correctly reports "in target: NO".
   What would want re-deriving, if 44 minutes is judged too long, is the ACT
   COUNT — five acts of three chapters is 37 minutes — and not the chapter
   budget, which is the one number here that now comes from a measurement.

   **The summary bound fired twice on this run, against once on story 30's,
   and that is the watch item moving.** Act 1 came back at 3,111 characters
   and act 4 at 3,233, both refused, both re-run, about $0.45 of the $1.55.
   The summary INSTRUCTION is byte-identical to story 30's — nothing in this
   change asks a summary to carry more — so the honest reading is that
   summaries scale with the act, and the act grew 29%. Per the bound's own
   docblock that is information about the writer and not a reason to move the
   number. The lever named there is still the untouched one: "3-5 sentences"
   is being read as three paragraphs.

   **No outline was generated, so the ceiling watch gets no third reading and
   stays at two.** That is worth saying rather than leaving as a gap: the
   trend is still one run from being called, and this probe could not have
   called it either way.

   Story 31 is at `outlined` with six scripts and nineteen chapters, no cast,
   no scenes and nothing paid beyond text.

   **THE THIRD PROBE: STORY 32, FIVE ACTS.** $1.09 across six calls — one
   outline and five acts — and **not one summary refused**, against two on
   story 31 at six acts. Forked from story 31 for the premise and the
   settings; the outline had to be regenerated because a five-act outline is
   a five-act outline, so the act STRUCTURE is the variable and the premise
   is the constant, as it has been since story 29.

   | | story 30 | story 31 | story 32 |
   |---|---|---|---|
   | acts | 6 | 6 | **5** |
   | runtime at 199 wpm | 34.3 min | 44.4 min — over | **39.1 min — in window** |
   | words | 6,832 | 8,835 | 7,774 (target band 5,500-8,000) |
   | chapters | 12 | 19 | **16 — 3,3,3,4,3** |
   | chapters that speak their number | 8 of 12 | 19 of 19 | **16 of 16** |
   | mean chapter | 2:52 | 2:20 | **2:26** (reference 2:13) |
   | `rehook_line` holding only "Chapter N." | 8 | 0 | **0** |
   | narrating-the-narration moves | 5 | 1 | **0** |
   | in-person encounters | 10 | 11 | **14** |
   | longest stretch with no encounter | 3.2 min | 4.0 min | **3.3 min** |
   | first encounter that costs her, in person | 76% | 75% | **72%** |
   | Gate 1 | 5 warnings | 1 warning | **0 problems, 0 warnings** |
   | summary-bound refusals | 1 of 6 | 2 of 6 | **0 of 5** |
   | spend | $1.23 | $1.55 | **$1.09** |

   The phase plan came back exactly as `ActPhase::planFor(5)` computes it —
   escalation, escalation, departure, search, refusal, every act `present` —
   and act 1 opens on the stored hook verbatim with all five beats and
   "Chapter one." after them. Nothing regressed on any axis the previous two
   probes moved.

   **The act length rose again and it was predicted rather than discovered.**
   1,555 words per act against story 31's 1,473. Five acts divide the same
   35-minute target into 1,393 words each instead of 1,161, and the target
   steers at +0.30, so +232 asked buys about +70 written. 1,473 + 70 = 1,543
   against 1,555 observed. **That is the first time in this project the
   act-length model has been used to predict rather than to explain**, and it
   is worth more than the six minutes it accounts for: the two terms are now
   separable, one advisory and one absolute, and both were needed to land
   39.1 minutes instead of 37.0.

   The consequence is that five acts sits nearer the ceiling than the 37.0 the
   change was argued on. It is in the window and it is the right side of it by
   this file's own rule, and there is no headroom left: an act count of five
   is the floor of what keeps the reversal, so a story that needs to be
   SHORTER has to give ground on the chapter budget, which is the measured
   number, or on the runtime window, which is a preference.

   **Zero summary refusals is one observation and not yet a result.** Three of
   the previous twelve acts broke the bound and none of these five did, on
   longer acts than any of them — which is the direction the five-sentence
   shape was meant to move it, and the sample is five. Watch the next run
   before calling it.

   Story 32 is at `scripted` with five scripts and sixteen chapters, no cast,
   no scenes and nothing paid beyond text.

3e. **THE BETRAYAL IS A SCENE, NOT A DISCOVERY. SEVEN STORIES FOUND THEIRS OR
   HEARD IT IN PRIVATE, AND THE WRITER WAS OBEYING AN INSTRUCTION WHEN IT DID.**
   Story 32 was read and cancelled at cast extraction: the structure was right
   and the thing that makes these videos work was missing. Measured on the
   seven phase-era stories before anything was built — rendered offsets for
   23, 25 and 28, word offsets at 199 wpm (≈) for the rest:

   | story | justification first staged | who hears it | first public saying | the betrayal |
   |---|---|---|---|---|
   | 23 | ≈6:10, a flashback | alone, in a courtyard | never before the exposure | found: a roommate's photos |
   | 25 | 5:33 | the narrator, in bed | 9:17, eleven at dinner | found: a cc'd booking |
   | 28 | 20:41 | two, kitchen table | never before the exposure | said, in private |
   | 29 | ≈4:30 | kitchen doorway | ≈11:24 | private eviction |
   | 30 | ≈0:50 | kitchen table | ≈8:40 | private |
   | 31 | ≈2:20 | kitchen table | ≈9:20 | private |
   | 32 | ≈5:40 | kitchen; the husband says it | ≈10:40, 22 people | private |

   No accomplice is in a room with the narrator in any of them. Two smaller
   readings: 23's hook claims "eleven people listening" and act 1 stages the
   line alone in a courtyard; the half of 25's justification that is ABOUT the
   betrayal ("four days in Sanya with the deputy") is never said aloud at all.

   **The cause was a sentence.** Hook beat 3 told the outline and act 1 that
   the justification lands "once here in a single sentence and again in act 2
   or 3 at length", and 29-32 did exactly that: one line in the hook, the
   first public saying at a banquet in act 2. The refusal bullet pointed back
   at "act 2 or 3" too. All three now point at the betrayal scene.

   **The reference, time-aligned, 1:02-4:29** (docs/refence/transcript-3446.txt):
   she arrives late to a reunion dinner of nine holding another man's hand
   (1:31); the room goes silent (1:49); a friend asks "Who's this? Your
   younger brother?" (2:05); "He's my boyfriend," one word at a time (2:09);
   the friend's boyfriend starts to stand and the narrator holds him down, "Let
   me handle this myself" (2:20); the narrator's first line is a question, "do
   you even know what you're saying?" (2:31); the justification, to his face,
   "I want to see a different view before I get married... it won't affect our
   wedding" (2:41-2:53); "Our wedding? Marry you my ass" (2:56) — NARRATION,
   followed by "In my head I slapped myself"; the spoken line back, "are you
   staying for a breakup dinner or going out on a date with your new boy toy?"
   (3:23-3:37); the man nods and leaves when she whispers (3:38); the
   justification AGAIN, to the friend (3:48-4:03); "you've even managed to turn
   my own best friend against me" (4:03) — the round lost. **The accomplice
   never speaks in the whole video.** He is silent beside her at 16:53 and
   18:21, holds her back at 19:41, and is revealed a fake at 29:29.

   **Two corrections came out of reading the material rather than a summary
   of it**, and both were in the prompt:

   - The accomplice is PRESENT, not speaking. The field asks for him in the
     room and explicitly not for lines. **REVERSED IN 3g**: that silence was
     one variant, explained by the transcript itself — he was a schoolmate
     asked to pretend, with no stake — and the permission became a motif.
   - "Marry you my ass" was cited in `genreGuidance()` as the model of a
     narrator answering back. It is what he THINKS. The prompt now carries the
     distinction, not a swapped example: the crude line is thought, the
     controlled line is said, and the pairing — 2:56 against 3:30 — is what
     makes a narrator fun to be inside without making them a ranter.

   **What is in place.** `stories.betrayal_scene`, required in the outline
   schema, asked for as "THE BETRAYAL AS A SCENE, NOT A DISCOVERY". The
   consumer question, asked for every reader in the change:

   | consumer | what it gets | arrival assertion |
   |---|---|---|
   | outline prompt + schema | the bullet; hook beat 2 compresses it; beats 3 and 4 point at it | `BetrayalSceneTest` |
   | act 1 prompt | "CHAPTER ONE IS THE BETRAYAL SCENE", inside `hookInstruction()` after the number and before the stored hook | `ActOneOpeningContractTest` |
   | every act prompt | the spine line "Where she first said it aloud", so act 3 does not re-stage its first saying and the refusal can hand it back | reflection |
   | act 1 scene call | "THE BETRAYAL SCENE (who is in the room)", so the accomplice and witnesses are drawn | fake record + reflection |
   | refusal check | `earlierMoments()` names "the betrayal scene" | `BetrayalSceneTest` |
   | Gate 1 | editable, labelled, a "says aloud" badge naming the justification sentence | Livewire |
   | `story:fork` | copies the field and the outline's age | `BetrayalSceneTest` |
   | `ExtractCharacters` | nothing — it reads scripts, not the spine | answered, not wired |

   **Beat 4 was the collision that act 1 would have had.** "The confrontation
   is the final act, and spending it here spends the video" is a general claim
   a writer can extend to chapter one, and the reference has a confrontation at
   1:31 that spends nothing — because the narrator loses it. It now reads THE
   RECKONING is the final act, and a round the narrator loses is not the
   reckoning. The cold action in the beats is untouched.

   **Four Gate 1 checks in `checkBetrayalScene()`, all warnings, each its own
   repair**: nobody watching (`AUDIENCE_MARKERS`, which deliberately omits
   `room`, `table`, `family` and `dinner` — a kitchen table is made of those
   words, and reusing `WITNESS_MARKERS` passes exactly the scene the check is
   for; drilled); the justification not said (overlap, naming WHICH sentence);
   the betrayal found (`DISCOVERY_MARKERS` behind the negation window — a
   warning on the operator's word, because not every premise can stage a
   public betrayal and a problem would refuse stories the genre allows); and
   act 1's summary not staging it (three distinctive words with proper nouns
   removed, because two stories' worth of names would pass any pair of
   summaries; drilled — with names counted, the red case passes). **Unchecked,
   said out loud:** that the person it is done with is in the room. A name
   cannot be told from a mention.

   **The legacy age is a COLUMN, and that is the first time.** The two earlier
   ages are inferred from the acts because every later outline carries a tell
   the Action or the schema writes. This field's only marker is itself, and
   empty is the value a legacy story and a broken new outline share. So the
   migration froze the fact at the one moment it was knowable —
   `outlined_before_betrayal_scene`, true on every story that had acts, by
   predicate rather than by id list — `GenerateOutline` clears it, a fork
   carries it, and typing the field in ends the excuse.

   **And the shared page fixture went red at all eleven statuses the moment the
   field existed**, because it was left describing a pre-phase outline that
   somehow had been asked for a betrayal scene — a state that cannot exist —
   and the missing-field problem filled the group the empty-track contract
   needs empty. The fixture question from further up this file, answered by a
   failing test rather than by remembering to ask it. The fixture now says its
   age, and `test_the_betrayal_fixture_can_express_every_state` asserts both
   fixtures' shapes.

   **The epilogue, fixed while in there.** `endingFor(Refusal)` still said "no
   epilogue" after 3d recorded that the transcript closes on one. It now
   allows a short time-jump epilogue in the narrator's own voice, and names the
   reference's single working sentence: a year later, at his wedding, "Sophia
   was the only one who didn't show up." **The two point-of-view extras after
   it (32:20 Sophia, 34:05 Willow) are NOT asked for**: they are a second and
   third narrator, and whether the format takes those is a decision, not a
   correction. The phaseless branch keeps "no epilogue" on purpose, asserted.

   Sixteen drills, all red for the reason they name, runner in a file with
   literal patches. 1,175 tests before, 1,208 after.

   **THE PROBE: STORY 33, A FRESH en-CN PREMISE, NOT A FORK.** $1.18 across
   seven calls, one refused. Five acts, 7,614 words, 38.3 minutes at 199 wpm,
   in window. Stopped at `outlined` for Gate 1; no cast.

   **READ THE CONFOUNDS BEFORE THE NUMBERS. Three things moved, not one.**

   1. **The premise was written to contain the scene.** The operator asked
      for one incident, a narrator who answers back and loses, and a public
      setting — and the premise dramatises the betrayal at the banquet in its
      own first sentence. A field and a premise that agree cannot be
      separated: a strong chapter one here is evidence the pipeline CAN stage
      the scene when both ask for it, not that the field alone would move a
      discovery-shaped premise.
   2. **Narrator gender × betrayal type is a combination no earlier story
      had.** Recorded precisely, because the first reading of it was wrong:
      the probe was flagged as the first female narrator, and it is not —
      stories 29-32 are all narrated by a daughter-in-law. What is new is a
      woman narrating a PARTNER betrayal: 23, 25 and 28 are men betrayed by a
      partner, 29-32 are women wronged by a mother-in-law. If 33 reads
      differently, that combination is a live explanation beside the scene.
   3. **The act 1 prompt changed between the first act-1 call and the kept
      one** (below). Acts 2-5 never carried the changed sentence.

   And one thing to settle before narration, not a text finding: the story's
   voice is the default, Brian, a male narrator voice, on a woman's first
   person. 29-32 never reached narration, so this has never been heard.

   | axis | reference | story 32 | story 33 |
   |---|---|---|---|
   | the betrayal | done, at a dinner of nine | private, kitchen table | **done, at a banquet of five tables + three clients** |
   | "Chapter one." | 1:02 | — | **1:00** |
   | antagonist enters with the accomplice | 1:31 | — | **2:04** |
   | a witness asks who she is | 2:05 | — | **2:36** ("Elder Brother, who is this?") |
   | justification first said aloud, in public | 2:44 | ≈10:40 | **2:52** |
   | the crude thought | 2:56 "Marry you my ass" | — | **3:01** "Partnership my ass." |
   | the spoken line back | 3:30 | — | **3:06** children's table / the cake |
   | round lost | 4:03 | — | **3:24** "the laugh turned around in the air and came down on me" |
   | accomplice speaks | never | — | **once, at the exposure: "I resign. Effective now."** |
   | justification said again in public | 3:48, to the friend | — | 33:08, at the exposure; handed back in the refusal at 36:24 |
   | in-person encounters after the betrayal | 6 | 14 | **11** (my reading) |
   | longest stretch with no encounter | — | 3.3 min | **~5.5 min**, the departure night to the tea house |
   | first encounter that costs HER | — | 72% | **70%**, the tea house |
   | hook beats | — | 5 of 5 | **5 of 5**, cold action not an answer-back |
   | chapters / spoken numbers / re-hook = "Chapter N." | 14 | 16 / 16 / 0 | **16 / 16 / 0** |
   | mean chapter | 2:13 | 2:26 | **2:23** |
   | act 1 words (mean of all acts) | — | 1,512 (1,555) | **1,610 (1,523)** |
   | profanity | strong | 0 | **1, "my ass", in thought, at 3:01** |
   | narrating-the-narration moves | — | 0 | **0** |
   | epilogue | a year later, one sentence doing the work | none asked | **a year later, the red envelope returned unopened** |
   | summary-bound refusals | — | 0 of 5 | **0 of 5** |
   | outline output | — | 30% of ceiling | **35%** |
   | Gate 1 | — | 0 / 0 | **0 problems, 1 warning — a false positive, below** |

   **The scene landed on the reference's clock to within a minute at every
   beat**, and the thinks/says split came back exactly as asked: the crude
   line as narration, then "What I said was," then the controlled one. Act 1
   grew about 100 words over 32's act 1 and the story came in 0.8 minutes
   SHORTER than 32, so the runtime risk named before the build did not
   arrive on this run.

   **FOUR FINDINGS FROM READING IT, in the order they matter.**

   1. **My own sentence collided with the chapter count, and the contract
      test written that morning to catch exactly this could not see it.** The
      betrayal block closed on "the scene is the chapter". The first act-1
      call returned ONE chapter of 479 words that stopped before the
      antagonist entered — 1,449 output tokens against 3,400-7,700 on every
      act of 31 and 32 — with a summary calling the act "the chapter", and was
      refused by the 2-4 bound, billed $0.1154, stored nothing. The archive
      made it a read rather than a guess. `ActOneOpeningContractTest` asserted
      the scene's POSITION against three opening rules and never asked whether
      it claimed the act's LENGTH, which is the fourth rule it touches. The
      sentence now says THE SCENE IS CHAPTER ONE, NOT THE WHOLE ACT, a test
      holds it, and the old sentence drills red. One observation; the wording
      plainly allowed the reading.
   2. **A permission became a motif.** "They do not need a line" was read as
      "they have no lines": every act summary from 1 to 4 carries "Vivian Xu
      has not spoken a single quoted word" as a fact the next act must not
      contradict, the narrator remarks on it in act 4, and she speaks once, at
      the exposure. It works on the page and it matches the reference, but it
      was not asked for, and it is the stated-count finding one step softer: a
      stated ALLOWANCE, carried forward in the running summary, steers like an
      instruction.
   3. **Gate 1's one warning is an over-report.** The departure check fired on
      "announces" — Qiao Meilan announcing, at the family meeting, that the
      narrator will step aside. The narrator's departure is unannounced. The
      announcement markers have no subject, so an antagonist's announcement in
      the same field reads as the narrator's. Not fixed: a warning that fires
      on the good case is how a detector stops being read, and this is its
      first live instance.
   4. **The refusal badge names the wrong moment.** The refusal answers the
      chair, "the sensible one" and "don't spoil my birthday" — the betrayal
      scene, in its words — and Gate 1 says "answers the grievance", because
      `checkRefusal()` reports the FIRST moment that clears two shared words,
      not the best one, and the grievance describes the same banquet. The new
      moment is shadowed by an older one in list order. Not fixed.

   **One inconsistency the outline carries and nothing can see:** the
   departure field and the premise say "three weeks after the banquet"; the
   acts date the banquet March and the leaving August 3. The act-1 writer
   silently rewrote the hook's "three weeks later" to "before the summer was
   out".

   **Gate 1 approved on the operator's read (2026-09-14); cast and scenes
   drafted, stopped at `scenes_drafted`.** $0.61 over eleven calls: one cast
   extraction and five acts at two calls each, because Haiku was discarded on
   all five — 0 of 43 now. 202 scenes, twelve characters, Vivian Xu in 20.
   The betrayal scene reached the pictures intact, which is the consumer the
   scene-call line exists for: the entrance hand in hand (scene 14), the chair
   pulled out with the narrator still in the next one (15), the cousin asking
   (16), Director Fang's cup (18), and "Partnership my ass" drawn as a cutaway
   with nobody in frame (20) — the thought kept out of the room in pictures as
   well as in prose.

   **The voice, measured before narration and not set.** Five female voices
   on the account — Sarah, Laura, Jessica, Matilda, Bella — and no voice but
   Brian measured on any locale. What an unmeasured female narrator changes,
   read off the code rather than assumed:

   - **The script: nothing.** Story 33 is frozen at `sized_against_wpm` 199.
   - **The estimates do NOT fall back to 160.** `EstimateSceneAssets`,
     `story:write` and the runtime projection read `bestKnownWpm()`, which for
     an unmeasured voice takes the highest rate measured on the locale —
     Brian's 199. So the page will show 38.3 minutes for a narrator nobody has
     timed: a borrowed figure, not a fallback one.
   - **The pace guard: detects, cannot enforce.** `expectedWpm()` is the one
     reader of 160, and on an unmeasured pair a drift is reported and never
     cancels the batch. The audio is unaffected; the runtime is a guess until
     `narration:measure` runs. At 175 wpm the video is 43.5 minutes, at 220 it
     is 34.6.
   - **Credits: nothing.** Billed per character, not per voice.
   - **The channel.** "A channel keeps one narrator" becomes two. Recorded as a
     standing decision to make once — one fixed voice per narrator gender —
     rather than a pick per story.

   **And the sentence the operator reads at dispatch is false for this story.**
   `NarrationPace::unmeasured()` said "scripts are sized against 160 wpm (the
   fallback) until one exists", printing `expectedWpm()`. Story 33 was sized
   against 199, and the estimate beside it reads 199 too. A figure claim about
   the wrong column, on the spend screen — and false on every en-CN story, not
   only this one, because sizing never reads the fallback while any voice is
   measured on the locale. **Fixed.** The sentence now names three figures as
   what they are: the rate THIS script was sized against (the preflight hands
   the story over; a null reads "not recorded"), the rate estimates borrow and
   whose it is, and the guard's fallback. Story 33 reads "sized against 199
   wpm; runtime estimates borrow 199 wpm, the best rate measured on en-CN by
   another voice; the pace guard has only the 160 wpm fallback — none of them
   is this narrator's figure". Two red/green cases and a call-site assertion
   in `NarrationPaceTest`, three drills red.

   **Voice set to Sarah (`EXAVITQu4vr4xnSDxMaL`) before any narration**, and
   fixed as the channel's female narrator — see Voice under Target audience.
   `narration:measure` runs on story 33 when its narration batch finishes.

   **The two Gate 2 advisories, read scene by scene before approval, and both
   over-report.** Of 13 "restates its narration": most are narration that
   DESCRIBES a picture (the hall, the price tag, the cake slice, the table of
   witnesses at the exposure), where the frame restating it is the right
   frame; the ones that are genuinely a picture of a sentence are #25 (the
   noodle strand, a literal of a line about a custom) and #72 (a second
   close-up of the same printed "Legal representative" line as #41). Of 21
   "hedged": eight rest the whole face on the hedge (#27, #45, #70, #75, #79,
   #107, #127, #195) and are the blank-face risk; eight carry a strong named
   expression with one hedged detail ("eyes wide, mouth slightly parted in
   shock"); and **four are not hedges at all** — `half` matches
   "half-standing", "half-smile" twice and "half-lidded", which are named
   expressions and a posture. A hedge list matched whole-word cannot see a
   compound.

   **BOTH GATE 2 ADVISORIES OVER-REPORT: 5 OF 13 AND 8 OF 21 WERE REAL. An
   advisory an operator learns to skim is on its way to being ignored**, and
   this is the over-report direction this file already warns about for
   audits, landing on the operator's own page. The restatement check cannot
   tell narration that DESCRIBES a picture from narration a picture merely
   illustrates, and that is a judgement, so it stays an advisory the operator
   reads. The hedge half was mechanical and is fixed: `mentionsHedge()` refuses
   a match joined to a hyphen, scoped to the hedge list only — `mentions()`
   also decides the face and setting cues, and widening its boundary there
   would have made two other advisories quieter as a side effect. Red/green
   pair in `GuardsGoRedTest`, drilled both ways (the old matcher, and `half`
   dropped from the list).

   **Edited on the operator's word, through `ImagePromptBuilder::rewrite()`
   with every stored tail verified byte for byte:** #25 reframed to Sophie at
   the back table while the head table raises its cups; eight expressions
   named at their real strength (#27, #45, #70, #75, #79, #107, #127, #195).
   Story 33 now reads 11 restating and 10 hedged.

   **#72 COULD NOT GET A FACE, AND THE REASON IS A GATE 2 LIMIT, NOT A
   CHOICE.** It was drafted with nobody in frame, so its stored prompt has no
   cast block, and the editor puts the new frame in front of the stored tail
   and never rebuilds it. A frame naming Sophie there would reach the
   generator with no description of her — a face invented once, on one still
   in 202. It was reframed as the studio floor after hours, lamp on at her
   desk, nobody in the room. **The Gate 2 editor cannot add a character to a
   scene**, and nothing on the page says so.

   **NOTHING COUNTS HOW OFTEN A STORY SHOWS PAPERWORK.** The only related
   check is the whole-story cutaway share, which warns above 60% and has never
   fired. Measured with a word-list heuristic written for the question
   (a paper noun in the frame's first eight words, and nobody in frame or a
   close-up) — rough, checked by hand against known frames, not a detector:

   | story | scenes | paperwork stills | densest 40 scenes | adjacent pairs |
   |---|---|---|---|---|
   | 12 | 148 | 24 (16.2%) | 10 | 1 |
   | 21 | 270 | 24 (8.9%) | 8 | 7 |
   | 23 | 257 | 25 (9.7%) | 7 | 6 |
   | 25 | 250 | 35 (14.0%) | **11, from scene 1** | 9 |
   | 28 | 203 | 17 (8.4%) | 9 | 3 |
   | 33 | 202 | 15 (7.4%) | 6, scenes 20-59 | 1 |

   Story 25 opened on a slideshow of documents: twelve of its first-act stills
   are paperwork. Story 33's cluster is #20, 26, 35, 41, 54, 59 across acts 1-2,
   which is the "three document stills in two acts" finding at its real size.
   This genre's payoff IS a document, so the count is not a defect on its own;
   a run of them is. Not built — the heuristic needs its own known-answer pair
   before it could be an advisory, and an advisory that over-reports is the
   finding above.

   **Seen on the way, not changed: `reversal_beats` is at 1,750 of its 2,000
   form bound** (max over ten stories; p50 1,507). That is the Gate 1 summary
   shape — a form rule sized by feel against a field a writer produces with no
   stated bound — at 88%. The refusal is no longer silent since `RefusedFields`,
   so it would be seen, but the next long search will be refused at save.

3f. **THE CAST IS DECIDED AT THE OUTLINE, AND AN ILLUSTRATION BECAME A
   SOURCE.** Built 2026-09-15, before the premise batch that names six people
   per story and places the future partner through one of the narrator's
   friends.

   **The census that decided it**, on the seven stories with a cast, matching
   each stored character against the premise, the spine fields and the act
   scripts:

   | story | named in premise | cast | named in the spine | added by the act writer | invented by the extractor |
   |---|---|---|---|---|---|
   | 21 | 0 | 10 | 9 | 1 | 0 |
   | 23 | 0 | 9 | 6 | 3 | 0 |
   | 25 | 0 | 13 | 9 | 4 | 0 |
   | 28 | 1 | 8 | 6 | 0 | 2 |
   | 32 | 0 | 11 | 8 | 3 | 0 |
   | 33 | 2 | 12 | 9 | 2 | 1 |
   | 34 | 0 | 9 | 8 | 1 | 0 |

   76% of the cast was named by the OUTLINE, which the prompt asked for in so
   many words — "name who is watching", "name the occasion and the witnesses"
   — and the act prompt twice more, "put the witnesses in the room and name
   them". Nothing counted them anywhere. `cast_age_profile`, the one per-story
   cast field, reached exactly one reader: the extractor, which runs after
   every act is written. A "six named only" line there could not have bound
   anything, and every character with a scene is a reference sheet.

   **What is in place.** `stories.outline_cast` — name, role, relationship —
   is a required outline schema field placed BEFORE the hook, because property
   order is generation order and every spine field names people. Roles are
   `CastRole`: narrator, antagonist, accomplice, antagonist_side,
   narrator_side, future_partner. No witness role, deliberately: a cousin who
   asks one question is "his cousin" in every field and every act. The
   consumer question, asked in the change:

   | consumer | what it gets | arrival assertion |
   |---|---|---|
   | outline schema + prompt | `cast` first; a budget of `cast.max_named` (8); the roles; the names taken by recent videos | `OutlineCastTest` |
   | `GenerateOutline` | refuses, after the cost row, no narrator, two antagonists, two future partners, a name twice, and a reused full name; stores the cast; clears `outlined_before_cast` | red/green, drilled |
   | every act prompt | "THE PEOPLE IN THIS STORY", and "do not name anyone else" | fake record + reflection |
   | the four witness sentences | "named only if they are in the cast"; a legacy story keeps "name them" | reflection, both ways |
   | extraction prompt | "describe these people and NOBODY ELSE" | reflection |
   | `ExtractCharacters` | keeps declared names, drops the rest, NAMES both on the job row; refuses if nothing matched | red/green |
   | Gate 1 | edit, add, remove rows; problems for structure; warnings for the budget (priced from the rate card), a reused name, and a non-narrator named in no act | Livewire + review |
   | `story:fork` | the cast and its age | drilled |

   **THE FIRST WRITE PRESS NOW STOPS AFTER THE OUTLINE, and that reverses a
   documented state.** The Act summary entry below says scripts present at
   `outlined` is "the ORDINARY state of this gate", and it was: one press
   queued the outline and every act, so the cast would have been reviewed at
   Gate 1 after the acts were bought against it. A cast decided where the
   decision is made has to be readable before the money moves, so the console
   press passes `outlineOnly` when the story has no acts and the acts are the
   second press. `story:write` is unchanged. And the split made a latent state
   ordinary — approving an outline with no scripts moved the story to
   `scripted`, where writing is refused — so `approve()` now refuses while any
   act is unwritten, naming the acts.

   **THE EXAMPLE-NAME REUSE, AND THE FIX PICKED.** 16 of the 72 cast entries
   were the en-CN guidance's eight example names verbatim: Grace Zhou in four
   of the last five casts, Wang Suhua in four, Kevin Lin and Leo Xu in two.
   The guidance meant them as the FORM of a name; **the writer read them as a
   list to draw from.** The fix is the check against recent stories, not
   removing or rotating the examples, because the viewer-visible defect is the
   same full name in two videos whatever supplied it, and the model's own
   favourites repeat without any list. `OutlineCast::recentNames()` reads the
   last ten non-fixture stories — ten, not five, because a premise batch fills
   a window of five with itself and forgets the published videos — from both
   `outline_cast` and `characters`, and skips role labels ("Second Uncle",
   "Sophie's Father"). The prompt lists them as unavailable; the Action refuses
   a reuse. On the day it went in the list held 55 names, including six of the
   guidance's eight examples. **Unchecked, said out loud:** a repeated given
   name with a different family name (Amy Sun, Amy Tang, Amy Shen). A token
   match would refuse every shared family name, and Zhou is common for a
   reason.

   **Also unchecked: a name in the prose that is not in the cast.** Telling a
   person from a place needs a proper-noun heuristic, and the census counted
   "Chaozhou" and "Songyuan Machinery" as names. The extractor's drop note is
   the exact version of that question one stage later.

   **The shared page fixture went red at all eleven statuses again**, for the
   third field running — it read "the cast is missing" as a PROBLEM on an
   outline written before the question existed. It now says its age
   (`outlined_before_cast`). Fourteen drills, run from a file with literal
   patches: thirteen went red on the first run and one did not — the narrator
   exemption, because the fixture script named the narrator, so the exemption
   was never exercised. The fixture now narrates in first person. 1,215 tests
   before, 1,236 after.

   **THE PREDICTION, ON THE RECORD BEFORE THE FIRST RUN.** A six-name premise
   with nothing bounding the spine lands at nine to thirteen characters, the
   band it has always been in. If the next story still extracts nine to
   thirteen, the spine witnesses are the cost and the array is not reaching
   them. The extractor's drop note says which names were refused, so the
   reading is a grep, not an inference.

   **Item 5, and why it is not built.** The future partner is a ROLE, so she
   has a name, a row the act writer is handed on every act, and a face the
   extractor describes. Nothing tells the writer when she appears, on purpose:
   whether a named row alone carries her into the acts is the measurement, and
   an instruction would make it unreadable. Gate 1 warns when she is named in
   no act script, which is the same question at act resolution. The scene
   count on the next story answers it at still resolution.

   **CORRECTION, 2026-09-17: THIS MEASUREMENT HAS NEVER RUN.** It was believed
   that story 35 had named the partner in its premise and she still never
   appeared. Neither premise from story 29 onward names a future partner (34's
   "girlfriend" is the antagonist; 35's Grace Pan is the narrator's DESIGN
   partner), and stories 35 and 36, the only two with an outline cast, have
   five rows each and no future_partner row. The role guidance only asks for
   the row "if the premise names one", so the outline was right not to invent
   her. The Gate 1 warning above has never had a row to fire on. The next
   premise has to name her and how she arrives; that is the whole test, and it
   costs no code. **No separate column**, decided with the operator: if the row
   alone does not carry her, the next step is a line in the search ending that
   reads the row the act writer already has.

   **FIRST READING, 2026-09-19, AND IT CAME FROM A DIRECTION NOBODY PREDICTED:
   THE ROW EXISTED AND THE PREMISE DROPPED HER.** Story 38's idea said "married
   her best friend". The premise generator declared Vivian Cao as
   `future_partner` in all three candidates, and none of the three premises
   names her; every one ends on the departure with no partner anywhere. So the
   measurement did not reach the act writer at all — the partner was lost one
   stage before the outline, by the writer holding her row. Cause and repair
   are in 3i. The act-level question this item was built for is still unread.

   **The role was gendered and is not any more.** It read "the woman the
   narrator ends up with", written for a man narrating; story 33 is a woman
   narrating a partner betrayal. It reads "the person the narrator ends up
   with, … as the premise names them" now, and `OutlineCastTest` holds the old
   sentence RED against the same detector the live one is GREEN on.

   **THE NARRATOR IS A REQUIRED PROPERTY, NOT A CAST ROW. Built 2026-09-18,
   after story 37's outline came back with no narrator and was refused.** The
   archived response (below, under the session-summary entry) holds eight cast
   rows, exactly `cast.max_named`, every role filled but the narrator, and
   every relationship written from the narrator's seat: "my girlfriend of four
   years", "my closest friend", "my mother". The hook, the grievance and the
   betrayal scene all say "I". The model wrote the cast AS the narrator and
   left them off it. Stories 35 and 36, same kind of premise, invented Ryan Mo
   and Aaron Cui; so one of three outlines under the cast missed.

   The prompt made it likely in four ways: the heading asked for "every person
   this story NAMES", which a first-person narrator is not; the only rule that
   reached them was the restraint clause ("give anyone else the premise
   implies a name only if..."); the narrator was counted against a budget the
   rest of the cast could fill; and the narrator row was one line in a role
   list under a heading that excluded them.

   **What is in place.** `narrator: {name, relationship}` is a required
   property of the outline schema, ahead of `cast`, and `narrator` is out of
   the cast's role enum on a single narrative, so the API cannot return a cast
   with no narrator or with two. `ClaudeScriptWriter::castFrom()` puts the
   property back as the first row of `outline_cast`, so Gate 1, the act writer,
   the extractor and `story:fork` read exactly what they read before. The
   prompt asks for the narrator first, says the premise will almost never name
   them and why they still need a name (their face is in more pictures than
   anyone's), and states the budget as people BESIDES the narrator, which
   `OutlineCast::budgetCount()` now counts at Gate 1 too. An anthology keeps
   `narrator` in the enum: each act's story has its own first person, and the
   property carries act 1's. `OutlineCast::castArrayRoles()` owns the answer for
   both the enum and the prompt's role list. `structuralProblems()` still
   refuses 0 or 2 narrators; it is the invariant behind the schema now rather
   than the only guard. Nine drills, all red for the reason they name. Not
   measured against the real model until story 37's re-run.

3g. **THE ACCOMPLICE HAS A STAKE, THE NARRATOR'S HEAD IS WHERE THE JOKES LIVE,
   AND THE ARC IS A DECISION, NOT A FINDING.** Built 2026-09-16 from two more
   reference transcripts, read separately because they are different kinds:
   `docs/refence/transcript-lydia.txt` (a betrayal story, ~33:38) and
   `docs/refence/transcript-declan.txt` (a COMEDY with a fantasy premise,
   ~32:30, used for one device only). Both have sections the operator removed —
   Lydia 23:37-25:05 and 33:00-33:38 — and nothing here is built on them.

   **The folder is `docs/refence`, misspelled, and stays that way.** Renaming it
   would break every path in this file for no gain. A request for
   `docs/reference/...` means this folder.

   ---------------------------------------------------------------------------
   **THE DECISION: THE FIVE MOVEMENTS STAY. Recorded as the operator's decision,
   not as a finding, so a later transcript does not reopen it by accident.**
   ---------------------------------------------------------------------------

   Lydia runs a different arc from ours. Its narrator holds hidden power (65% of
   the company, revealed at 12:51, 38% in) and wins every round from 5:35 (16%)
   on. The divorce is announced at 0:33. Nobody searches: he is found at work at
   11:11 and at home at 16:42. The exposures cascade (12:51, 23:06, 29:47)
   rather than landing at the end. It is a face-slap video, and it holds an
   audience for thirty-three minutes.

   **The operator chose not to trade the arc for that tone.** A narrator with
   hidden power winning from minute 12 is a different video. What this change
   builds is comedy INSIDE the five movements: the narrator's head, the
   narrator's controlled mouth, and the accomplice losing in public in the last
   three acts.

   **What reopening it would cost, so the next person weighs it rather than
   rediscovering it.** Every one of these is written against "escalation, then
   departure, search, refusal":

   - `ActPhase::endsWorseForNarrator()` and the escalation ending: the narrator
     loses every round before the departure. A face-slap narrator fails it from
     the first confrontation.
   - `ActPhase::planFor()`, the `count - 2` departure clamp, and "the reversal
     is the last three acts" in `genreGuidance()`.
   - The departure's no-announcement rule and `checkDeparture()`: an announced
     leaving cannot be searched for.
   - The search phase: `reversal_beats`, its cost check, and the in-person
     meetings measured against transcript 3446.
   - The refusal's no-gloat rule. Lydia's narrator gloats ("You're filthy now
     too", the stray-dog line), and that is most of its comedy.
   - Hook beat 5 (promise the departure) and its Gate 1 overlap check.
   - The probes behind all of it: stories 29-33 are measured against this arc.

   **So a fourth transcript that runs the face-slap shape is not evidence
   against the decision.** It is another instance of the variant already
   declined. Reopening needs the costs above weighed explicitly, as a spending
   decision on the whole contract, not as a correction.

   Two things were forbidden for reasons that have nothing to do with the arc,
   and they would stay forbidden either way: the narrator's punch (9:40, "blood
   burst"), under "no violence by the narrator"; and the orientation-coded
   material, below.

   ---------------------------------------------------------------------------
   **"THE ACCOMPLICE NEVER SPEAKS" WAS ONE VARIANT, AND THE FIRST REFERENCE
   SAYS WHY**
   ---------------------------------------------------------------------------

   3e wrote "the accomplice is PRESENT, not speaking" from transcript 3446, and
   the prompt carried it in three places as "they do not need a line". Story
   33's summaries then carried "has not spoken a single quoted word" from act
   to act as a fact, which is how a permission becomes a motif.

   3446 explains its own silence at 29:29: the "boyfriend" is *"a younger guy
   from school who had a crush on me"*, asked to pretend. He has no stake, so
   he has nothing to say. Lydia's Gerald wants the company shares:

   | | Gerald |
   |---|---|
   | first words to the narrator | 1:28, 4% in (heard on the phone at 0:45) |
   | his justification | changes four times: harmless (2:17), contrite (kneels, cries, kowtows, 4:44-5:16), a victim of family pressure (7:07, through Lydia), and then, the act dropped at 11:21, the man in charge ("you're fired", 11:28) |
   | his real motive | never said by him; guessed by the narrator at 23:13 and confirmed by his silence at 23:27, **the one time he is silent** |
   | first loss | 8:29 in private (his move for a marriage proposal fails); 9:11 and 9:40 in public |
   | how often | about twelve losses across the four scenes he shares with the narrator in the text that remains, every scene ending worse for him |
   | at the public exposure | he holds the microphone; Lydia is not mentioned in the plaza text |
   | the end | prison, "for even longer" than anyone else, in two sentences of epilogue |

   **Silence follows from having no stake.** That is a genre-level reading from
   two references pointing opposite ways, and it is the same failure 3d
   recorded for "no contact" and "no epilogue": one video's specifics written
   in as the genre's structure. The ledger rule, "no act ends with the
   narrator winning", fails the same test harder: Lydia's narrator never loses a
   round after 5:35. It is kept, by the decision above, as a choice rather than
   as a fact about the genre.

   **The losses fit the arc** because they are the antagonist's side losing,
   not the narrator winning. They belong to the last three acts.

   ---------------------------------------------------------------------------
   **WHAT IS IN PLACE**
   ---------------------------------------------------------------------------

   Three accomplice columns, not one, because the three go to DIFFERENT acts,
   and the routing is the reason:

   | column | reaches | why |
   |---|---|---|
   | `accomplice_motive` | every act's spine block, marked as not coming out before the last three acts | an escalation act plants it |
   | `accomplice_performance` | every act's spine block; the escalation and departure endings say he talks in it and wins | he speaks from chapter one |
   | `accomplice_fall` | the departure, search and refusal endings ONLY, and those acts' scene calls | an escalation act told how he ends spends it early, which is the narrator winning early |

   All three are EMPTY when the cast declares no accomplice (a mother-in-law
   does it with nobody), and Gate 1 reads that as "no accomplice in the cast",
   not as missing. `OutlineCast::structuralProblems()` now refuses two
   accomplices, since the spine describes one person.

   The consumer question, asked in the change:

   | consumer | what it gets | arrival assertion |
   |---|---|---|
   | outline schema + prompt | all four, in generation order: his act before the betrayal scene he speaks in, the thought before the refusal that pays it off | `AccompliceArcTest` |
   | `GenerateOutline` | stores all four WITHOUT `array_filter`, and refuses coded terms after the cost row | red/green, drilled |
   | genre contract | THE ACCOMPLICE section; movement 1 no longer says he never speaks; movement 3 says he is losing too | reflection |
   | act 1 | "AND HE TALKS" in chapter one; the running thought planted there; the opening block names both as starting after the beats | reflection, drilled |
   | every act prompt | motive, performance and thought in the spine block | reflection + fake record |
   | last three acts | the fall, per phase | reflection, drilled |
   | last three scene calls | "THE ACCOMPLICE'S FALL (he is in the room when he loses)" | fake record + reflection, drilled |
   | Gate 1 | editable, states, `exposes` and `said aloud in` badges, six checks | Livewire + GuardsGoRedTest |
   | `story:fork` | all four and the age | `AccompliceArcTest` |
   | `ExtractCharacters` | nothing: it reads scripts, not the spine | answered, not wired |

   **`GenerateOutline` does not `array_filter` these four.** The spine is stored
   through a filter that drops empty values, which is harmless for fields every
   outline fills. An empty accomplice field is a real answer, so a filter would
   leave the previous outline's accomplice standing on a re-outline that has
   none. They are written explicitly, empty as null.

   **THE EXCLUSION.** Gerald's act is orientation-coded mockery: a
   "sissy-sounding" voice, "I'm not into women", a staged kiss as the lie. The
   device underneath is a harmless ROLE the narrator sees through, and it needs
   none of that. The operator excluded it entirely. The prompt asks for a role
   (the old friend, the loyal colleague, the considerate relative) and says what
   not to use; that is the request. `AccompliceArc::codedTerms()` is the
   invariant: `GenerateOutline` refuses an outline carrying one in the three
   accomplice fields, after the cost row, and Gate 1 reports an operator's edit
   as a PROBLEM from the same list. It deliberately has **no negation window**:
   "he is not into women" is not the good case of this check, it is the tell.
   **Unchecked, said so it is not read as covered:** the act scripts. A story
   can have a gay character who is not a joke, and a word list cannot read
   prose.

   Six Gate 1 checks in `checkAccomplice()` and `checkRunningThought()`, each
   with a red/green pair in `GuardsGoRedTest`:

   - coded terms (a problem)
   - the act quotes no line: the silent accomplice in a new coat
   - the fall reads as one humiliation (fewer than three sentences)
   - the fall has nobody watching
   - the fall never exposes the motive (overlap, proper nouns removed; the
     badge names the motive sentence)
   - the refusal never pays off the thought

   The last one counts only **the thought's own words**, with every word it
   shares with another spine field removed. A joke about "family helps family"
   shares those words with every refusal, so plain overlap would pass a refusal
   that never mentions the joke. **Unchecked:** that the fall starts no earlier
   than the departure act (the routing enforces that, the field cannot say it),
   that the narrator is in the scenes he loses, and that thoughts are tagged in
   the prose.

   ---------------------------------------------------------------------------
   **THE HEAD AND THE MOUTH: THE THINKS/SAYS RULE, RENAMED AND WIDENED**
   ---------------------------------------------------------------------------

   The rule was named after its example, "the crude line is what they think".
   Declan runs the same device about six times as often and funnier, with
   **no profanity at all**. Measured against the first reference:

   | | 3446 | Declan |
   |---|---|---|
   | comic thought lines | ~5 in 34:46, three in the first four minutes | ~30 in 32:30, in all 15 chapters |
   | gap between thought and spoken line | 34 s (2:56 to 3:30) | usually none: the same sentence |
   | tagged as a thought | "Marry you my ass" untagged; "In my head" a line later | 28 of 30 tagged ("I thought", "I told myself", "inwardly") |
   | thoughts paid off later | none | "billing for emotional damages" (0:08) becomes a $50 privacy fee approved at 27:30 and added to salary at 29:44 |
   | profanity | strong | none |

   Now in `genreGuidance()`: **THE HEAD IS FUNNY; THE MOUTH IS CONTROLLED**, with
   the 3446 pairing kept as one example and Declan's 10:27 line as another. A
   thought costs nothing on the ledger, so it can win every exchange without the
   narrator winning a round. **EVERY THOUGHT IS TAGGED**, because one TTS voice
   reads both channels and an untagged thought is heard as said aloud.
   **THE RUNNING THOUGHT**: one private joke, planted in chapter one after the
   beats, recurring, and said aloud once in the refusal.

   **`running_thought` is a column, not a prompt line, and a prompt line could
   not have done it.** The refusal act is written from five-sentence summaries
   of the acts before it, so a thought planted in act 1's prose never reaches
   act 5. This is the `escalation_beat` finding caught before it shipped: a
   request that cannot arrive.

   **Held, both measured and both not transferable:**

   - **The reaction device.** Around seven of Declan's laughs are Aurelia
     reacting to a thought she can hear (1:44, 4:26, 9:17, 13:42, 15:39, 17:33,
     19:44). That needs the fantasy premise. In our stories only the viewer
     hears a thought.
   - **The mistaken narrator.** Declan's thoughts are funny because they are
     WRONG (fall guy, corpse suit, a hit). A betrayal narrator holds the truth,
     and "the audience is never confused about who is wrong" rules out a
     narrator who is.

   Tagging and the running thought's recurrence are **prompt requests with no
   mechanism** behind them in the prose. The next story's scripts are the
   measurement.

   ---------------------------------------------------------------------------
   **FOUR THINGS THE BUILD FOUND**
   ---------------------------------------------------------------------------

   1. **The locale guard caught British spelling I had written into the PROMPT
      itself.** "apologise" and "licence" went into the genre contract and the
      fake. The fake was refused, as it should be, and that is the only reason
      the prompt copy was noticed. **Nothing checks prompt text against the
      locale**: the guard reads OUTPUT. A prompt that spells a word the British
      way teaches the writer the spelling the guard then refuses at a billed
      call. The pre-existing "colour" in the character-extraction prompt is the
      same shape, left alone because that prompt does not produce narration.
      **Closed 2026-09-17**, and "does not produce narration" was the wrong
      reason to leave it: cast descriptions ARE locale-checked output. See
      `PromptLocaleTest` under "Where bugs actually live".
   2. **The shared page fixture went red at all eleven statuses for the third
      field in a row** ("Running thought is missing"). It was on the plan for
      this change and was still fixed after the run rather than before it,
      which is the honest record of how much a plan item is worth against a
      failing test.
   3. **Two of eleven drills passed first.** One found a real gap: act 1's
      no-accomplice branch could restore "they do not need a line" and no test
      rendered that branch looking for it. One was a broken drill: `forceFill()`
      takes one argument, and the filter passed as a second one was silently
      ignored, so the "defect" was a no-op. Both are red now, for the reason
      they name. Suspect the drill first, again.
   4. **A GREEN half went red because the fixture's joke had no tally.** The
      thought "a dollar... the fund" shared only "fund" with a refusal saying
      "dollars", plural. The check was right. A running tally that states no
      figure gives the refusal nothing to say back.

   **ON THE RECORD BEFORE THE FIRST PAID RUN.** Nothing here was run against
   the real model. The prediction for the next story with an accomplice: he
   speaks in chapter one; the escalation acts show him winning with her; he
   loses at least three times in the last three acts, in scenes with the
   narrator; the running thought is tagged in act 1 and said aloud in the
   refusal. **The cost to watch is the outline ceiling.** Four more required
   fields at ~300 output tokens each (story 22's measured per-field average) is
   ~1,200 tokens. On story 35's 13,167 (82%) that is ~14,400, or 90%. That is an
   estimate from the field average, not a reading. If an outline truncates, it
   is information to record before retrying, as the ceiling entry says, and
   the ceiling is not to be raised to absorb it.

3h. **THE LAST CHAPTER IS THE ANTAGONIST'S, ABOUT A YEAR ON, IN THE SAME
   VOICE.** Built 2026-09-17. Our stories ended on a corridor plea and a
   narrator epilogue; the reference ends on "Extra 1 — Sophia's POV" (32:20,
   1:45), which turns on a chance she was offered and threw away that the
   narrator never saw, and on what the years look like from inside her life
   ("The wedding dress I had once ordered still sat on the top shelf of my
   closet"). The arc decision in 3g stands: this is a CHAPTER inside the
   refusal act, not a sixth movement and not a phase.

   **Three decisions, the operator's, recorded as decisions:**

   - **One chapter, not the reference's two.** The second extra is the new
     partner's, about 40 seconds, and no story has a partner (see the item 5
     correction in 3f).
   - **One voice.** A second voice touches `voice_id`, the pace guard and
     the fingerprint, for two minutes of audio. The spoken announcement,
     "Extra — Dana Vasquez's point of view.", is the only marker that the "I"
     changed hands, which is why it names her and why Gate 1 checks it.
   - **About a year, not twenty.** Her face is one reference sheet at her age
     in the story, and ageing texture is refused, so decades could only be
     drawn as objects. A year is drawable. Gate 1 warns on five or more years
     or a decade, behind a negation window.

   **What is in place.** `stories.antagonist_regret` (the chance, who offered
   it, on which day, and the year in things that can be seen), required in the
   outline schema after `refusal`; `chapters.point_of_view`, the cast name on
   her chapter and null on every other. `App\Support\AntagonistPointOfView`
   owns "does this story get her chapter, and whose name": yes only when the
   regret is written AND the cast names an antagonist, and only in the refusal
   act.

   | consumer | what it gets | arrival assertion |
   |---|---|---|
   | outline schema + prompt | the field after the refusal, "ABOUT A YEAR, NOT TEN OR TWENTY" | `AntagonistRegretTest` |
   | real writer decode | the field, and `point_of_view` on every chapter | `AntagonistRegretTest`, drilled |
   | `GenerateOutline` | stores it (empty clears), clears the age flag | red/green |
   | genre contract | `"I", never "she"` keeps ONE EXCEPTION, named | reflection |
   | refusal act prompt | her chapter last, announced, no number, the regret | reflection, drilled |
   | every other act | nothing: an escalation act would stage the offer | reflection, drilled |
   | chapter instruction | "THE LAST CHAPTER OF THIS ACT IS THE EXCEPTION" | reflection |
   | `GenerateActScripts` | stores it; refuses two, misplaced, misnamed, wrong act; not counted toward `max_per_act` | red/green, drilled |
   | refusal scene call | "I" is her; the year drawn in rooms and faces, never aged | fake record + reflection |
   | Gate 1 | edit; "offered on" badge; chapter badged; warnings for a missing chapter and a missing announcement | Livewire + GuardsGoRedTest |
   | `story:fork` | the field and its age | drilled |
   | `ExtractCharacters` | nothing: it reads names, and "I" is not one | answered, not wired |

   **Her chapter is not refused when it is missing.** A malformed one is a
   shape and refused after the cost row, like every chapter shape. A missing
   one is content the writer did not deliver, and Gate 1 reports it the way it
   reports a chapter that never says its number.

   **Five things the build found:**

   1. **The real writer's decode had no test, for any spine field.** Every
      test runs on the fake, which builds its own draft, so the drill that
      deleted `antagonist_regret` from the real decode stayed GREEN.
      `outlineDraftFrom()` and `actDraftFrom()` are separate from the call
      now, and one test checks the whole spine through them.
   2. **The anchor check copied `checkRefusal()`'s first-match loop**, the
      defect 3e finding 4 records, and named "the departure" for a chance
      offered at the reception. It takes the best match now, scored on the
      CHANCE sentences only, because the year-on sentence ("changed her
      number") out-voted the offer's own words. **`checkRefusal()` itself is
      still first-match**, unchanged here.
   3. **The negation window was case-sensitive**, so "Not twenty years later"
      read as a twenty-year jump. The GREEN half of the pair caught it.
   4. **The fake's last narrated chapter ended mid-sentence**, harmless while
      nothing followed it. With her chapter after it, the join check refused
      the merged sentence, correctly, and the fake closes its sentence now.
   5. **The shared page fixture went red at all eleven statuses again**, the
      fourth field running (betrayal scene, cast, accomplice arc, this).

   **The pronouns in the new text are neutral** ("the antagonist", the name,
   "their"). The existing contract says "she" for the antagonist throughout,
   and story 33's antagonist is a husband; that older text is unchanged.

   Nothing was run against the real model: no outline or act has been
   generated under this. The next story is the measurement.

3i. **PREMISES FROM AN IDEA, AND THE SPINE QUESTIONS HAVE ONE COPY.** Built
   2026-09-19. The operator types an idea ("my CEO wife cheated so I made
   them pay"); three premises come back, each checked against Gate 1 before it
   is picked. Findings 3c and 3e both said the premise shapes the outline more
   than any field: a history-shaped premise got its history staged as acts
   (29-32), and the one premise written to contain the betrayal scene (33) got
   the scene on the reference's clock. This writes premises in that shape.

   **The refactor came first, because without it this was the fourth copy.**
   The spine questions were clauses inside one `sprintf` in `outlinePrompt()`.
   They are `App\Support\SpineQuestions`, one method each, called by the
   outline prompt and the premise prompt. The outline prompt was rendered for
   seven story shapes before and after the move: system prompts byte-identical,
   user prompts identical except the one space `withheld_information:the` had
   been missing. `PremiseGeneratorTest` asserts every spine field the outline
   SCHEMA requires is asked, from the one copy, and that no question's text is
   back in `ClaudeScriptWriter`. **The first version of that test was
   vacuous**: it walked `OUTLINE_ORDER`, so a drill that dropped `departure`
   from the list shrank the assertion with it and stayed green. It reads the
   schema now, an independent list, and the drill is red. **Seen, not folded
   in:** the hook's five beats have a second, pre-existing copy in act 1's
   `hookInstruction()`, worded for the act writer. Folding it would change the
   act 1 prompt that `ActOneOpeningContractTest` holds, so it is recorded
   rather than moved.

   **What the premise prompt adds, and nothing else:** the premise's own shape
   (six or seven sentences, first person, the narrator unnamed; opens in the
   room where the betrayal is done; FRIENDS as the witnesses, not family; one
   incident, no enumerated history; names `cast.premise_named` = 6 people
   besides the narrator; ends on the departure), what the three differ in (the
   occasion and what only the narrator can produce in person, nothing else),
   and the revenge translation. The genre contract, the format, the locale
   guidance, the cast question and six spine questions are CALLED. "Friends as
   witnesses" is new and lives in the premise prompt only: the outline's
   `betrayal_scene` question does not carry it, deliberately, because adding
   it there changes every outline and that is a separate decision.

   **The shape, from the title finding.** One `candidates` array of identical
   items and no labelled slots: the title schema's `short_titles` field
   returns a short title every time, because a required field gets filled.
   Each candidate is the narrator, the cast, seven spine answers, then the
   premise LAST, so the prose is written to its answers. Top-level,
   `idea_was_revenge_shaped` and a one-sentence `translation`, decided before
   any candidate is written.

   **Sonnet, medium, 16,000 ceiling**, because the use is re-rolling: three
   rolls must cost less than the outline they precede. Estimated before the
   first call from the outline ledger at ~$0.07-0.11 a roll against ~$0.18-0.28
   on Opus. **Not measured: nothing here has been run against the real model.**
   The first rows replace the estimate.

   **Checked on an unsaved story, shown rather than dropped.**
   `ValidateOutlineSpine::premiseChecks()` puts a candidate's cast and answers
   on an unsaved copy of the story and calls the SAME private checks `handle()`
   runs: cast structure, reused names, coded terms, justification, betrayal
   scene (audience, said aloud, done not found), narrator at exposure,
   departure. Severity follows the OUTLINE: a reused name is a Gate 1 warning
   but a premise problem, because the cast prompt tells the outline to use the
   premise's names exactly and refuses a reused one. Then the PROSE, because
   the outline reads the premise and nothing else: every named person must
   appear in it, four answers must be carried by it (the overlap every spine
   check uses), and it must not enumerate history ("first time / second time",
   or two years). Checks are computed when Gate 1 is read, from the stored
   fields, so they never go stale; `ran` lists every check, so a clean
   candidate reads as checked rather than unexamined. Problems red, warnings
   amber, every candidate shown. **Unchecked, said so it is not read as
   covered:** that the prose opens in the room, that the witnesses are friends,
   the sentence count, and that it ends on the departure.

   **The revenge line.** When the generator reports the idea as revenge-shaped,
   Gate 1 says so above the candidates with its one-sentence translation. When
   it does not but the idea's own words carry a revenge phrase
   (`PremiseIdea::revengeMarkers()`), Gate 1 says the idea reads as revenge-
   shaped and the generator did not say it translated it. Two readings, so a
   silent translation still leaves a line.

   **Where it lives.** `cost_entries.story_id` is NOT NULL, so a spend needs a
   story. The new-story form offers "an idea" (single narratives only), which
   creates the draft and queues nothing; the premises are a billed, two-press
   button on Gate 1 at `draft` (`OperatorAction::WritePremises`), queued on the
   text queue with a `premises` stage row, and the panel polls only while one
   is in flight. "Use this premise" writes the premise column and nothing else:
   `save()` moves a draft to `outlined`, which would close the panel before any
   outline exists. The latest roll is `stories.premise_candidates`; every roll
   keeps its cost row. The form also asks who narrates (voice item A, now
   built). Hidden on an anthology, with the reason said.

   **Found on the way:** the gate page never rendered `session('notice')`, so
   the new-story form's "created and queued" message had never reached the
   operator on the page it redirects to. It renders now.

   Thirteen drills, all red for the reason they name after the vacuous test
   was fixed. The backslash hazard hit three patch scripts written through a
   Bash heredoc across 09-18 and 09-19: one landed correct only because `\E`
   is not a Python escape, one was stopped by its own match assertion, one was
   caught before it ran. Measured: `a\\b` in a QUOTED heredoc reaches the
   program as `a\b`, so quoting the delimiter is no protection. Patch scripts
   are written as files with the Write tool.

   **THE FIRST ROLL, story 38, 2026-09-19.** Idea: "My CEO wife cheated with
   her intern. I divorced her and married her best friend." en-CN, male
   narrator. **$0.1269**: 3,230 input + 7,719 cache write + 10,115 output (63%
   of the 16,000 ceiling). The returned JSON counts **4,181 tokens** on the
   model's own tokenizer, so **~5,934 (59%) was reasoning**, about $0.059.
   Above the $0.07-0.11 estimate, and three rolls ($0.38) now cost more than
   story 37's outline ($0.2273), which is the use pattern this stage was put
   on Sonnet for. One reading; not acted on.

   What worked, recorded so it is not changed by accident: the three varied
   the occasion (stock listing / anniversary dinner / industry gala) and the
   in-person item (share certificate / business licence / notarised deed) and
   nothing else; none is revenge although the idea is revenge-shaped (the
   generator said false, and the idea's own words carry no phrase
   `revengeMarkers()` knows, so no line was shown either way); and the
   narrator's lines are funny ("a hand-holding fee", "the meeting where growth
   got redefined as an intern").

   **Three findings, all three candidates, so the prompt and not chance. Two
   of the three were partly the INSTRUMENT, and reading the stored rows is what
   separated them:**

   1. **"The accomplice never speaks" was a detector that could not see single
      quotes — and under it, a real defect about WHO he speaks to.** All three
      fields and all three premises quote Ethan Bao: *'I only ever tried to
      carry what she couldn't carry alone...'*. Single quotes, which is how a
      writer avoids escaping a double quote inside a JSON string.
      `AccompliceArc::quotesALine()` knew only double quotes, so Gate 1 said
      "described and never spoken" three times and the operator reported a
      silent accomplice. It now accepts single quotes, straight and curly, with
      an apostrophe rule (opening mark not after a letter, closing mark not
      before one), paired RED/GREEN. What IS real: every line is said to the
      ROOM ("told the table", "told the guests gently"). `accomplice_
      performance` asked for a line "TO THE NARRATOR"; `betrayal_scene` asked
      for "the line that makes her defend him in front of everyone" and named
      no addressee, and the model followed the scene question. Both questions
      now say to the narrator's face; unchecked, because telling an addressee
      from prose is a reading a word list cannot make. The premise prompt never
      carried "they do not need a line" — the grep is clean — so that was not
      the cause.
   2. **"The justification is not said in the betrayal scene" is true of the
      FIELD and false of the PROSE.** All three premises have her say it
      verbatim, aloud, to his face, in the room. All three `betrayal_scene`
      fields write *"delivers the justification to his face"* — a pointer to
      another field, which shares no words with it, so the overlap check fired.
      The question now says QUOTE HER HERE and names the pointer as the thing
      not to write. The ORDER was left alone on purpose: a witness's question
      answered with a different line, the accomplice, then the justification to
      the narrator's face is transcript 3446's order exactly (2:05 the
      question, 2:09 "He's my boyfriend", 2:41 the justification to his face),
      and 3e is built on that reading.
   3. **The premise never names people in its own cast, and the prompt TOLD IT
      TO NAME ONLY SOME.** Zhou Bin and Vivian Cao are in all three casts and
      no prose. The premise prompt asked for a COUNT ("names 6 people besides
      the narrator"); the cast instruction it calls then said "The premise may
      name the people it needs" — a sentence about the premise as the
      outline's INPUT, which read by the premise writer is permission — and
      allowed `max_named` (8) rows against `premise_named` (6). And "ENDS ON
      THE DEPARTURE" left a partner who arrives afterwards nowhere to be. Here
      the count equalled the cast and was still not obeyed: the stated-count
      finding has a limit, and it is a competing permission in the same
      prompt. Now: the premise names EVERY person in its cast and nobody else,
      the cast instruction takes `forPremise` (the premise limit and "everyone
      in this cast is named in the premise you write"; the outline's text is
      byte-identical), and the future partner is placed BEFORE the departure,
      in the room for the betrayal or already in the narrator's life. Gate 1
      reports a missing partner on her own line, naming her, not as one name
      in a list. That is the item 5 reading, recorded in 3f.

   **Two decisions, the operator's, 2026-09-19, so a later roll does not
   reopen them by accident:**

   - **The betrayal scene's order stays.** Witness's question, an answer that
     is not the justification, the accomplice's line, then the justification
     to the narrator's face — transcript 3446 at 2:05, 2:09 and 2:41. Not
     changed on one roll.
   - **"A soft, apologetic voice" is a performance, not a coded tell.** It is
     what Gerald does in the Lydia transcript; the ban is for orientation-coded
     mockery, not for a quiet voice. `AccompliceArc::CODED_TERMS` stays as it
     is and does not gain "soft".

   **THE COST WATCH, opened on this reading.** $0.1269 with 59% reasoning puts
   three rolls above one outline, which was the argument for Sonnet. **If the
   next two rolls land in the same place (about $0.12 or more, reasoning over
   half the output), that is the trend, and the stage is revisited: effort
   `low` here, or a shorter prompt.** Split each roll the way this one was:
   count the archived response with `countTokens` and subtract from billed
   output. Archives survive until the story renders.

   **ROLL 2, the same idea, under the round-one prompt fixes.** $0.1190:
   3,679 input (+449, the new premise bullets arriving) + 7,719 cache write
   (byte-identical system prompt) + 9,230 output; the returned JSON counts
   4,352, so **~4,878 (53%) was reasoning**. The cost watch's second reading
   agrees with the first: one more like it is the trend. What the round-one
   fixes did, all three candidates: every cast member is named in the prose,
   and the cast shrank to the five the prose uses; **Chloe Rong, the future
   partner, is placed in the room in all three** ("the only person at that
   table who reached for my hand instead of Nicole's"); every betrayal-scene
   field quotes her justification and the prose carries it verbatim; the
   accomplice's line is to the narrator's face in all three fields and in two
   of three premises — premise 3 has him answer the bride's mother. Gate
   warnings fell from nine to two.

   **Of the two, one is real and one is the check.** Premise 1's prose never
   mentions the loan guarantee it withholds — a true positive. Premise 3's
   "narrator at the exposure names nothing only they can produce" is a false
   fire: the fields share mortgaged/mortgage, signature/sign, refinanced/
   refinance and bank/bank's, and `distinctiveWords()` matches exact tokens, so
   it saw only "nicole's". The same matcher PASSED candidate 2 on "narrator" +
   "board" — "narrator" is in nearly every spine field. So the overlap check
   is form-blind in one direction and too generous in the other. Reported,
   not built: `distinctiveWords()` is shared by every overlap check at Gate 1
   (refusal, hook, justification, regret, premise prose), so stemming it or
   adding "narrator" to its common words changes them all at once, and that
   wants its own measurement against the stored spines first.

   **"booked" is a sense-blind marker, and it is not alone. Fixed the same
   day, below.** Measured over every real `betrayal_scene` in the database (stories
   33-37) and all three candidates: the discovery check fires on TWO of eight,
   and both are false — this roll's "the restaurant booked for their
   anniversary", and story 36's "she holds her phone out... and asks me to
   take the photo", which was live on story 36's Gate 1 and was never noticed.
   Zero true positives on real output; every true positive the check has ever
   matched is a hand-written sample. The class is a word list matching a word
   in a different sense: `pencil` in `CharacterTextGuard` (a skirt), "announces"
   in the departure check (3e finding 3, the antagonist announcing), and these
   two. `pencil` was fixed with a PHRASE-EXCEPTION list (`NOT_AN_OBJECT`), which
   is the longer-list repair and is always one phrase behind. The discovery
   check was given a structure instead — verbs alone, evidence only with
   somebody finding it in the same sentence — built the same day; see the entry
   on checks that fire on good output, under "Where bugs actually live", which
   also records that this check has never caught a true case.

3j. **THE ENDING IS CHOSEN BY THE OPERATOR, BEFORE THE OUTLINE, AND THERE ARE
   TWO.** Built 2026-09-19. `stories.ending`, `App\Enums\StoryEnding`. The five
   movements are untouched; this is the last two or three minutes.

   **Why.** Measured on every story with a written refusal act (29-37): the
   refusal act was asked for an optional narrator epilogue — "what the
   narrator's life is now, and one concrete fact that shows her loss from the
   outside" — and, whenever `antagonist_regret` was written, her chapter
   stacked after it. The regret was REQUIRED in the outline schema, so from
   story 37 on every outline asked for both. Every epilogue came back as the
   same list: a headcount and square meters, her decline in three facts, one
   object — stories 33 and 35 send back the same red envelope unopened. And
   story 37's two endings restated one year: the regret field asked for "one
   fact about where the narrator is now", and her chapter repeated the
   showroom, the daughter and the full banquet the epilogue had just listed.
   Story 36's four-chapter refusal act (37.3 min) was the narrator's four
   chapters, not hers; 37 is the only story that ever had her chapter.

   **Two endings, exclusive, the operator's decision:**

   | ending | what it is | does NOT carry |
   |---|---|---|
   | `new_life` | a year on, ONE SCENE of the narrator's life, its own numbered chapter. The cast decides the partner: a `future_partner` row puts them on screen; none is "alone and fine", with nobody treating single as a gap | the antagonist's year; no gesture back at her; at most one clause touching her |
   | `antagonist_voice` | a year on, the antagonist's own chapter: the chance thrown away and the year from inside | the narrator's year; no narrator epilogue; the narrator seen only from outside, in ONE fact |

   A third, "somebody else tells him what happened to her", was dropped: its
   content was already the second half of every epilogue, and what made it
   different was only that it was a scene. "Alone" and "with the partner" are
   one ending because the partner is a fact about the CAST. The year rule
   stands for both.

   **The epilogue's content, fixed for whichever ending:** the new life is ONE
   SCENE, told as it happens, with an exchange in their own words — "NO
   NUMBERS: no headcount, no floor area, no salary, no contract value". Stated
   as the move, not a phrase list.

   **Why an operator column set before the outline**, not the others: a model
   left to choose converges (16 of 72 cast names were the setting's own
   examples; every epilogue took one shape) and sees one story, so it cannot
   vary the channel. Gate 1 after the outline is too late: each ending needs
   fields written upstream. The premise is optional. The new-story form
   requires it on a single narrative, `story:write --premise` requires
   `--ending`, Gate 1 shows the picker at draft (saved as picked, nothing
   billed) and a readout at every status, and `GenerateOutline::NO_ENDING`
   refuses an unchosen single narrative at the Action, the dispatch and the
   Gate 1 button, all before the call. An anthology has none.

   **The history is beside the picker** (`RecentEndings`, one component
   `x-ending-picker` for both surfaces): the last five non-fixture stories,
   newest first; three in a row with one ending is said in a warning box. A
   story outlined before the column is READ from its chapters (a closing
   chapter in the antagonist's voice, or not) and labelled as read, never as
   chosen.

   | consumer | what it gets | assertion |
   |---|---|---|
   | outline schema + prompt | `antagonist_regret` only on her ending (`SpineQuestions::outlineOrderFor`), ~439 output tokens back at 91% of the ceiling | `PremiseGeneratorTest`, both endings |
   | `GenerateOutline` | refuses no ending before the call; drops a regret the new life did not ask for | `EndingChoiceTest`, drilled |
   | `AntagonistPointOfView::nameFor` | the ending decides first; NULL keeps the regret-presence rule | drilled |
   | refusal act (`closingFor`) | exactly one ending, with `doesNotCarry()`; the partner from the cast | drilled both ways |
   | genre contract | movement 5: one ending, never both | `BetrayalSceneTest` |
   | Gate 1 | regret on the new life named as the other ending's material, not as a missing antagonist; a missing regret is `none` on the new life; a partner on her ending; a partner missing from the new life's last chapter | RED/GREEN, drilled |
   | metadata brief + rule 1 | "How the video ends", and "PROMISE ONLY THE ENDING THIS VIDEO HAS" | drilled |
   | `story:fork` | copies it | `EndingChoiceTest` |
   | fake writer | the regret only when asked | via the above |

   **NULL is legacy and honest**, not backfilled: those stories were asked for
   neither ending cleanly. They keep the stacked shape (only 37, published,
   has a regret). **Unchecked, said so it is not read as covered:** that the
   new life is a scene rather than a list, and that it carries no report on
   her. Both are requests; nothing reads a scene from a list.

   **Two things the build found.** The last-chapter check first read the act's
   FIRST chapter: `Act::chapters()` already orders ascending, so an appended
   `orderByDesc()` was a secondary sort that did nothing; the RED case named
   the wrong chapter, and it is `reorder()` now. And a negated-jump GREEN in
   `GuardsGoRedTest` stayed green for the wrong reason the moment endings
   landed — the regret checks returned early on a new-life story — so the
   regret cases state her ending and the fixture assertion checks both. Also
   fixed on the way: `story:fork` built a 65-character slug for a 64-character
   column on long titles (two runs in six on random factory titles); it uses
   `Story::slugFor` now, with a deterministic case.

   Twelve drills plus one, all red for the reason they name. 1,382 tests
   before, 1,401 after. Not run against the real model: the next outline and
   refusal act are the measurement, including whether "one scene, no numbers"
   is obeyed.

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

  **Names split by generation**, which is a deliberate part of the register
  rather than a concession to English-speaking viewers: an English given name
  with a Chinese family name for the twenties cast — Kevin Lin, Amy Sun — and a
  full Chinese name, family name first, for parents, grandparents and in-laws.
  Young urban Chinese adopting an English name at university or at work is
  real, and the split marks the generation with one foot outside the family,
  which is the fault line the genre runs on. The authority machinery is
  unaffected: it runs through address by relationship — Mother, Second Uncle,
  Eldest Brother — and not through given names at all. What it costs, and the
  provenance column that went in before it moved, is under "Where bugs actually
  live".

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
  generated outline and act. **Since 2026-09-17 a hit there KEEPS the text and
  shows the phrase at Gate 1, in red, for the operator to judge**, instead of
  failing a billed call; scene frames and the cast still fail the stage. See
  "Denied locale terms are judged at Gate 1" under "Where bugs actually live".
- The prompt template stores a `locale_profile` field so this is data, not
  hardcoded prose.

**Voice**
- American English TTS voices only. Neutral-to-warm narration.
- Store `voice_id` per story so a channel keeps one consistent narrator.
- **ONE VOICE PER NARRATOR GENDER, FIXED FOR THE CHANNEL. Never picked per
  story.** Decided 2026-09-14 on story 33, the first woman narrating a partner
  betrayal, where the alternative was 38 minutes of a man reading "my husband
  sat his mistress in my chair".

  | narrator | voice | voice_id | measured |
  |---|---|---|---|
  | male | Brian — deep, resonant, comforting | `nPczCjzI2devNBz1zQrb` | 197.00 en-US, 199.49 en-CN |
  | female | Sarah — mature, reassuring, confident | `EXAVITQu4vr4xnSDxMaL` | not yet — `narration:measure` after story 33's batch |

  "A channel keeps one narrator" became two, deliberately, and this table is
  what stops it becoming five. **Mechanised 2026-09-19** (option A below):
  the table is `providers.narrator_voices`, the new-story form asks who
  narrates (required, no default) and `CreateStory` resolves the voice from
  the table via `NarratorVoice`, and `story:write --premise` requires
  `--narrator`. `voice_id` is still the record of the choice; the premise
  generator reads the gender back from it. **What it does not catch:** the
  wrong radio pressed. `default_voice_id` (Brian) remains for callers that
  name no narrator — a fork, a fixture. `voices:list --set` is still the
  override, and still must run BEFORE narration: after it, every paid scene
  is stale and re-bills.
- Until Sarah is measured, her stories' runtime estimates borrow Brian's
  locale rate and the pace guard cannot enforce. See 3e.

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
- Derived from `chapters` — two or three per act, using the chapter title —
  and from `acts` on a story written before chapters existed (see 3d).
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

Chapter timestamps are the render's numbers, written once. They live on
`chapters` (two or three per act, see 3d) and, on a story written before that
table existed, on `acts`; the sheet reads whichever the story has and never
both.

**chapters**
```
id, story_id, act_id, sequence (int, within the act),
title, rehook_line (nullable),
point_of_view (nullable — null for the narrator; the antagonist's cast name on the
               one chapter closing the refusal act, see 3h),
first_sentence (int — 1-indexed offset into acts.script, SentenceSplitter unit),
start_ms (nullable — filled after render), duration_ms (nullable)
```
`scenes.chapter_id` is nullable and points here.

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
id, title, premise, premise_candidates (json, nullable — the latest premise roll, see 3i),
cast_age_profile (nullable),
outline_cast (json, nullable — [{name, role, relationship}], see 3f),
outlined_before_cast (bool, default false),
hook,
narrator_grievance, antagonist_justification,
accomplice_motive, accomplice_performance (nullable — empty when the cast has no accomplice, see 3g),
betrayal_scene, outlined_before_betrayal_scene (bool, default false),
withheld_information, exposure_moment, narrator_at_exposure,
departure, reversal_beats, accomplice_fall (nullable, 3g),
running_thought, outlined_before_accomplice_and_thought (bool, default false),
refusal,
antagonist_regret, outlined_before_antagonist_regret (bool, default false, 3h),
ending (varchar, nullable — new_life | antagonist_voice, the operator's choice before the
        outline; null on stories outlined before it existed, 3j),
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
output_path, log (longtext), error (text, nullable),
failure_kind (varchar, nullable — App\Enums\FailureKind), failure_facts (json, nullable)
```
`error` holds what happened and nothing about what to do. The repair is built
from `failure_kind` when the page is read — see "A failure row names its kind".

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
GeneratePremises                     [optional, at draft: three premises
                                      from an idea, one Sonnet call per
                                      roll; the operator picks one, 3i]
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

  **THE SAME QUESTION WITH THE ARROW REVERSED: WHEN A FIELD IS RESTORED IN ONE
  PLACE, ASK WHO ELSE HOLDS A COPY.**

  `duration_ms` lives in two tables on purpose. `scene_audio.duration_ms` is the
  audio file's length; `scenes.duration_ms` is what the clip's frame count falls
  back to. A repair of story 25's four re-narrated scenes restored the first and
  not the second, and `RenderSceneClipJob` — which reads the second — failed all
  four.

  **The answer was in a docblock I had read in the same session.**
  `GenerateSceneNarration::writeAudioRow()` says it in as many words: *"`duration_ms`
  lands in two places on purpose and they are not duplicates: `scene_audio.
  duration_ms` is this file's length, and `scenes.duration_ms` is what the clip's
  frame count is computed from."* It was on screen while the write payload was
  being read to decide what the full clear should mirror.

  That is the migration lesson's exact shape — the finding written down, in the
  right place, correct and complete, applied to `category` and not to `unit` —
  with the reader being the author this time. **Being the person who read it is
  no protection.** A fact in prose fires once, at the moment somebody happens to
  be looking at it for a different reason; the question has to be asked out loud
  during the change, or a mechanism has to ask it.

- **THE ARROW REVERSED A SECOND TIME: A FORM VALIDATING A FIELD THAT TWO
  STAGES WRITE, WITH ONLY ONE OF THEM IN MIND WHEN THE LIMIT WAS SET.** Story
  28, 2026-09-12. Gate 1's Approve did nothing — no transition, no error, no
  message anywhere on the page — and the outline was good. The status was
  `outlined`, six acts, six scripts, both `render_jobs` rows succeeded, and
  `approveGate()` would have passed. What refused it was one line in `save()`,
  which `approve()` calls first:

  ```php
  'acts.*.summary' => ['nullable', 'string', 'max:2000'],
  ```

  Act 4's summary was **2,026 characters**. Not the operator's text and not
  the outline's: `GenerateActScripts` REPLACES each act's outline summary with
  the summary the act writer returns, because the next act needs the truth
  rather than the plan. That prompt asks for "3-5 sentences" against a bare
  string schema, and act 4 came back in exactly five sentences. The writer did
  what it was asked; the limit was sized — by feel — for the OUTLINE writer's
  "3-5 sentences", and nobody had measured the act writer's. Measured, on 61
  acts:

  | | p50 | p90 | p99 | max |
  |---|---|---|---|---|
  | act-writer summaries (script present) | 1,459 | 1,815 | 1,968 | **2,026** |
  | outline summaries (no script yet) | 970 | 1,057 | — | 1,179 |

  So 2,000 was comfortably above the outline stage and inside the act stage's
  tail. **The next largest across twelve stories are 1,968 (story 21) and
  1,933 (story 23), both published — within 2% of the same wall on shipped
  work.** And the reopen was incidental: the code writes the acts BEFORE Gate
  1 (`WriteScript` is permitted at `draft` and `outlined` and refused from
  `scripted`; `NextAction` at `outlined` says "read the outline and the act
  scripts, then approve"), so scripts present at `outlined` is the ORDINARY
  state of this gate and any story whose writer returned 2,001 characters
  could not be approved, reopen or no reopen. The only approve test builds
  three factory acts with no scripts — the one state that cannot express it.

  This is the `duration_ms`-in-two-tables finding with the arrow reversed:
  there, a field restored in one place had a second copy nobody asked about;
  here, a field VALIDATED in one place had a second WRITER nobody asked about.
  The question is the same and it has to be asked out loud during the change:
  **who else writes the field this rule reads, and what does that writer
  actually produce.**

  Three layers, all fixed, and the value was NOT trimmed:

  - **The value.** The cap moved to `Act::SUMMARY_MAX_CHARS = 3000` — ~1.5x
    the observed maximum, ~1.6x the p99, and under two thirds of the shortest
    act script on record, so a summary cannot quietly become a second script.
    It is derived from the act writer's distribution, not raised to fit one
    result: the summary is the running context that keeps 7,000 words
    coherent, and trimming it to fit a textarea would have spent coherence to
    save a form field. Story 28's act 4 stands at 2,026 and now validates.
    The title (100, YouTube's) and the escalation beat (1,000) moved onto the
    same model as constants, so all three form rules read the model and a
    test asserts the identity.
  - **The source.** The bound is stated in BOTH prompts and enforced in the
    Action against the decoded response, after the cost row — exactly where
    and why the outline's act count is enforced. **It is NOT a schema
    constraint, and the request assumed it could be.** Structured outputs do
    not honour `maxLength` (the official SDKs strip it and validate
    client-side), and `expression` and `rehook_line` are not schema-bound
    either; a `maxLength` written into `actSchema()` would be a 400 on every
    act call or a limit that reads as enforced and is not. A test asserts
    neither schema carries one, so nobody "fixes" it in. An over-long summary
    now refuses the act loudly, names the length and the bound, and stores
    nothing; the outline call does the same for all three fields.
  - **The silence** — see the next entry, because it is a different defect
    and it is live elsewhere.

  Drilled, each red then green: one over the bound at the form, one over at
  the act call (refused, not stored) and exactly at it (stored), an outline
  act over it (refused, no acts written), and the bar-level contract below.

- **LIVEWIRE'S VALIDATION REFUSAL IS A THIRD PATH, AND IT REACHES NEITHER
  `GateVoice`, NOR `$problem`, NOR A MODAL. ANYWHERE THAT PATH IS LIVE, THE
  SAME SILENCE IS AVAILABLE — AND IT IS LIVE ON GATE 2 TODAY.**

  `SupportValidation::exception()` catches the `ValidationException`, fills
  the component's error bag, stops propagation and answers **200**. Nothing is
  logged, no modal opens, no exception reaches a handler. The one surface a
  validation refusal has is an `@error` directive beside its field. Gate pages
  had a whole vocabulary for refusing and none of it is on that path:

  | refusal | mechanism | where it lands |
  |---|---|---|
  | capability | `abort(403)` | a Livewire error modal |
  | dispatch | `DispatchRefusedException` | `$problem`, an `.alert.err` at the top |
  | **validation** | **error bag, 200** | **`@error` beside the field, or nowhere** |

  Story 28's act summary never had an `@error` — in any version of the blade
  back to the redesign — and there was no `$errors->any()` block anywhere in
  the views. So the press re-rendered a page byte-identical to the one before
  it. **Even a present `@error` would have been useless**: the field was three
  screens above the sticky bar the press happened in.

  **The fix renders the error bag whole, inside `.gatebar`, every key** —
  labelled by act sequence rather than array index, each line a link to the
  field. Whole rather than a list somebody typed, so a rule added to
  `saveRules()` is on the bar by construction; `OutlineGateTest` walks every
  rule, violates it, and asserts its message reaches the bar, and fails if it
  cannot derive a violation for a new rule rather than counting it covered.
  `OutlineGateLayoutTest` builds the refused state — the one state no fixture
  in that file could build, which is how the missing `@error` went unrendered
  by any test for a phase — and asserts document order (inside the bar, before
  Save), `wide`, and no `GateVoice` fragment. It does NOT go through
  `GateVoice`: the voice phrases capability and position, and "this field was
  refused" is a fact about the request, not a claim about the gate.

  **The sweep, every `validate()` in the console against its blade:**

  | page | rule keys with NO renderer | was it firing? |
  |---|---|---|
  | Gate 1 `save()` | none, now | fixed above |
  | **Gate 2 `saveScene()`** | **`imagePrompt` (max 2,000), `motion`** | **YES, on 1,495 of 1,693 scenes. Fixed below, and not by raising the cap.** |
  | Gate 4 `save()` | `titleSelected` (max 100), `description` (max 5,000) | not today — largest stored 88 and 965 — fixed below |
  | New story | `format` (`in:single,anthology`) | cannot fail from the UI; a select |

  **All three now render the error bag whole through one component,
  `<x-refused-save>`, from one builder, `App\Support\RefusedFields`** — inside
  Gate 1's sticky bar, inside Gate 2's editing row above its Save, above Gate
  4's Save sheet. A page that wants the block asks for every error; a key it
  did not label still renders under its raw name rather than being dropped.
  Each page's rules are a public static (`saveRules()`, `sceneRules()`) and a
  test walks every key, violates it, and asserts the message reaches the
  block — failing rather than skipping if it cannot derive a violation for a
  new rule.

- **GATE 2 WAS MEASURING THE OPERATOR AGAINST THE APP'S OWN CONSTANT. 2,532 OF
  A 3,008-CHARACTER PROMPT WAS TEXT NOBODY ON THAT PAGE WROTE OR COULD
  CHANGE.** The Gate 1 finding one field over, and larger: `edit()` loaded
  the WHOLE stored `image_prompt` into one textarea and `saveScene()`
  validated it at a literal 2,000. Reproduced on story 12, scene 1 (3,186
  chars): a narration-only edit was refused with no message and did not
  persist. Measured over 1,681 stored prompts, by section:

  | | p50 | p90 | p99 | max |
  |---|---|---|---|---|
  | whole prompt | 3,008 | 3,281 | 3,566 | 4,206 |
  | everything the operator did not write (cast + style + constraints) | 2,838 | 3,084 | 3,335 | 3,973 |
  | frame — authored | 140 | 173 | 241 | 311 |
  | expression — authored | 48 | 73 | 92 | 117 |

  The art style constant alone is 2,226 characters and the constraints 306.
  So the answer to "should the field carry the style block" is no, and not as
  a cap decision: **the editor edits the two sections the operator writes —
  the frame and the expression — and `ImagePromptBuilder::rewrite()` puts
  them back in front of the stored tail byte for byte.** The cast block, the
  style and the constraints are the frozen cast text and two config values,
  identical across the story, and the editor's own help text already warned
  that editing the cast block there is how a face drifts at scene 90.

  **The tail is the STORED one, never rebuilt from config.** Rebuilding would
  hand one edited scene the current art style while its 250 neighbours keep
  the one they were drafted under — a single still in a different look, on a
  story `ScenesGate::styleBlock()` reports as carrying one style. A drifted
  style is that report's business; an edit must not fix one scene of it.
  Drilled by corrupting the kept sections: the byte-for-byte test goes red.

  **The bounds are derived from the authored distributions, one constant
  each on the Scene model:** `FRAME_MAX_CHARS = 600` (~2x the 311 maximum;
  ~100 words against a prompt asking 25-45, so the operator can say more
  than the writer without a frame becoming a paragraph of scene) and
  `EXPRESSION_MAX_CHARS = 250` (~2x the 117 maximum). Zero stored scenes
  exceed either. Same three layers as the act summary: the form rule reads
  the constant, the scene prompt states both bounds, `DraftScenes` refuses an
  act whose writer exceeds one after the cost row, and a test asserts the
  scene schema carries no `maxLength`. Narration keeps its floor and no
  ceiling: it is the video.

  **Gate 4 got the silence layer and only that.** Its two bounds are
  YouTube's, from `config/youtube.php`, and were never sized by feel: the
  generator drops titles past the hard limit after its call and
  `ValidateYoutubeMetadata` blocks an over-long description. What the form
  shared with the other two was that neither field had an `@error`. Both
  have one now, the block renders above Save sheet, and since `approve()`
  saves first a refused approve says "not crossed" beside the button.

  **One thing seen and left alone, on the record.** `ClaudeMetadataWriter`'s
  title schema carries `maxLength` on both title arrays, with a comment
  saying the lengths are "unreachable rather than checked". The API
  documents `maxLength` as unsupported, and metadata has generated
  successfully with it in place — so it is being tolerated, not enforced.
  The app does not rely on it: `GenerateMetadata` filters titles past the
  hard limit after the call. It is left because removing it changes a
  prompt-cached schema on a stage that works and the filter behind it is
  correct; it should not be read as the guard, and the anti-`maxLength`
  test is deliberately not extended to that writer until it is removed.

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

- **Gate 1's retry EXISTS. What is missing is the failure. CLOSED 2026-09-19:**
  Gate 1 shows the failed `outline` or `act_scripts` row with its error and
  its `FailureRemedy`, for as long as the row is the stage's latest word, and
  lists every outline truncation from the LEDGER, which a re-run cannot
  reset. The judgement call left below (show a failure older than the last
  success?) answered itself: a re-run overwrites the row, so only the ledger
  can show an older one, and it does for the one kind of failure the ceiling
  position needs seen. Worth correcting on
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

  ---------------------------------------------------------------------------
  **CORRECTION, 2026-09-12: THE "2.9x OVERSHOOT ON IDENTICAL INPUT" WAS NOT
  TEXT, AND "RE-RUN IT FIRST" WAS THE WRONG REMEDY.**
  ---------------------------------------------------------------------------

  The entry above reads story 23's truncated run as the outline text
  overshooting, and files it under generation variance beside story 21's word
  count. That is wrong, and it was wrong in a way the ledger could have shown
  at the time: story 23's stored outline is 20,086 characters, which the
  successful run billed as 5,483 output tokens. An outline that size cannot be
  written three times over inside one call. What reached 16,000 was REASONING
  billed as output tokens at effort `high` — see "THE OUTLINE CEILING WAS
  BEING SPENT ON REASONING" below, where the same thing was measured on
  stories 26, 27 and 28 and then separated by a single medium-effort run. The
  remedy this entry arrived at, "re-run", was followed on story 28 and cost
  $0.84 for no outline. It is corrected in config and below.

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

- **THE NAME MATCHER WAS DOCUMENTED FOR WESTERN NAME ORDER AND `en-CN` PUTS THE
  FAMILY NAME FIRST. EVERY GIVEN NAME MISSED; EVERY FAMILY NAME RETURNED THE
  WRONG PERSON.** Found by reading the code next to a naming question, not by
  any check — the suite, four audits and both shipped Chinese stories were green
  throughout.

  `ImagePromptBuilder::resolve()` matched exactly, then fell back to the FIRST
  token of the name being looked up. Its own docblock said why, and was right
  about the case it named: *"the cast is stored as 'Kyle Bennett' and a frame
  will reasonably say 'Kyle'"*. That is a rule about given-name-first order,
  written when every story in the database was `en-US`.

  Probed against both live Chinese casts:

  ```
  Song Yiran -> Song Yiran      Yiran  -> NULL
  Wang Suhua -> Wang Suhua      Suhua  -> NULL
  Song       -> Song Anan   (story 21's cast holds FIVE Songs)
  Lu         -> Lu Jianguo  (not Lu Wenbin, who carries 105 scenes)
  ```

  **The family-name half is the worse one and it is not a miss.** A miss drops a
  description; this pasted one specific character's description into another
  character's frame, on a still about to be bought, with nothing anywhere
  recording that a choice had been made. `DraftScenes::resolvePresent()` then
  discarded the misses in silence, on a comment that was correct about the case
  it was written for — *"a frame naming somebody who is not in the cast is
  usually the generator inventing a person"* — and could not tell that from a
  real cast member the matcher could not parse. **One null carried both
  meanings.**

  **THE MEASUREMENT THAT LOOKED LIKE EXONERATION AND PROVED NOTHING.** The first
  attempt to see whether this was live counted name forms in the stored cast
  blocks and found **0 unresolvable out of 540**. That number is worthless:
  `ImagePromptBuilder` WRITES those blocks from the stored names, so it could
  only ever have agreed with itself — the fake-TTS-deriving-its-duration-from-
  the-constant shape, in a probe. The second attempt compared narration against
  the pivot and was genuinely inconclusive: 22 given-name-only cases in story
  21, against **42 full-name cases** that resolve fine and are therefore just
  "mentioned, not in frame". Comparable rates, so nothing can be concluded, and
  that is what was reported.

  So: **the hazard is structural and confirmed by direct probe; a live instance
  is not demonstrated.** What has been holding it off is the guidance line
  *"use the same form for a character every time they are named"*, which makes
  every frame take the exact path — and the naming change below is what puts
  that line under more pressure.

  **The rule now**, in `resolve()` / `explain()`:

  1. **Exact, case-folded.** Unchanged and still first, so the path both shipped
     stories actually run is byte-for-byte the same behaviour.
  2. **Most shared tokens.** Order-free: "Kevin" finds Kevin Lin and "Suhua"
     finds Wang Suhua. "Bennett" now finds Kyle Bennett too, which the old
     first-token rule could not do in EITHER order — so this is not the old rule
     flipped, and there is a test asserting the identical thing in both orders.
  3. **A tie is AMBIGUOUS and resolves to nobody.** Not the first, not the
     longest, not the one with the most scenes: any of those is a rule for
     picking between people the generator did not distinguish.

  **Most-tokens rather than any-token is what stops (3) firing on ordinary
  input.** "Yiran Song" shares two tokens with Song Yiran and one with Song
  Anan, so it resolves cleanly rather than tying — a guard that refused a name
  plainly identifying somebody would be ignored within a week.

  **A REFUSAL YOU CAN SEE BEATS A DROP YOU CANNOT**, which is the other half and
  the reason `NameMatch` exists rather than a nullable Character. AMBIGUOUS and
  UNKNOWN both resolve to nobody and want opposite reactions — one is a person
  who is not in the story, the other is a person who is, and a description
  missing from a frame that needed it. `DraftScenes` writes them to the
  `draft_scenes` job log, after the transaction rather than inside it, because
  `RenderJob::note()` saves a row and a note written inside would be rolled back
  by the failure worth recording. Located by ACT and position, never by
  `$sequence`, which during a partial re-draft is a parked number in the 30,000s
  that matches nothing an operator can look at.

  Both other call sites moved with it — `RecordSceneCast::backfill()` reports an
  ambiguous name where it previously could not even see one — because a fix
  applied to one caller out of several is this file's most-repeated finding.

  **Six drills, each confirmed red**, including restoring the original matcher
  verbatim (8 cases red) and swapping most-tokens for any-token (the reversed
  full name goes ambiguous).

- **`en-CN` NAMES SPLIT BY GENERATION, AND THE PROVENANCE COLUMN WENT IN
  BEFORE THE GUIDANCE MOVED.**

  English given name with a Chinese family name for characters in their
  twenties — Kevin Lin, Amy Sun — and full Chinese names, family name first, for
  parents, grandparents and in-laws. Wang Suhua stays Wang Suhua.

  **It is register-consistent rather than a compromise**, and the reason is that
  the split IS the genre's fault line: young urban Chinese adopting an English
  name at university or work is real, and it marks the generation with one foot
  outside the family. The authority machinery does not run through given names
  anyway — *"characters address each other by relationship as often as by name:
  Mother, Second Uncle, Eldest Brother"* is what carries filial hierarchy and it
  is untouched.

  Three costs, named because they are real:

  - **The surname link weakens for a listening audience.** "Sun Yaqin" and "Sun
    Yaqin's grandmother" read as one family instantly; "Amy Sun" and "Wang
    Suhua" do not — and the reason they don't (Chinese women keep their natal
    surname) is more accurate, not less. In a 40-minute video with no
    scrollback, kinship that cannot be reconstructed has to be stated.
  - **It multiplies name forms in play**, which is exactly what the matcher
    above is least good at. "Kevin Lin" has a natural short form; "Wang Suhua"
    does not. The guidance now says ONE form per character for the whole story
    — including when an elder is speaking — because a character with a second
    Chinese given name is a character the resolver and the audience both have
    to reconcile.
  - **Four English given names are unusable**: Tito, Lola, Ate and Po are in the
    shared operator-leak WARN list, because each is also a Filipino honorific.
    That list is shared by every profile deliberately — it is about where the
    OPERATOR sits, not where the story is set — so moving to China does not make
    "Lola" safe. The guidance names them as forbidden, and a test asserts both
    that the guidance's own example names are clear of the list and that those
    four still collide, so the instruction cannot outlive the collision.

  **`stories.locale_guidance_fingerprint`, added and backfilled BEFORE the
  guidance moved.** The guidance is a string in a config file rather than a
  value on a row, so an edit leaves **no trace whatsoever** — a worse starting
  point than `sized_against_wpm`, where at least the constant was readable in
  one place. Same three steps in the same order, for the same reason: column,
  backfill, edit. One and two are recoverable and three is not.

  **The backfill is split by what the evidence supports, and story 21 is left
  NULL.** `config/locale.php` has been touched by exactly two commits. The
  guidance blocks were extracted from both and compared byte for byte:

  | profile | e336a3e | a3d64c7 | working tree |
  |---|---|---|---|
  | en-US | 902 chars | identical | identical |
  | en-CN | **absent** | 2415 chars | identical |

  **A commit date is an upper bound on when a change existed and never a lower
  one**, and this repository commits in batches well after the work. Story 21 is
  the proof: it is `en-CN`, created a full day BEFORE the commit that first
  records the `en-CN` profile existing at all. The profile was plainly in the
  working tree already; what cannot be shown is that its TEXT was what
  `a3d64c7` later captured.

  So en-US stories take the current digest (identical in every commit the file
  has ever had — the same strength of claim the 160 backfill made), story 23
  takes it (created after the commit, and the file is provably unchanged from
  there to now), and **story 21 is left unknown**. Marking it with the current
  digest was the comfortable answer and a false one: it would assert that its
  acts were generated against text nobody can show was in place, which is
  precisely the assumption this column exists to stop being frozen into a row.
  It costs nothing that matters — after the edit, 21 reads unknown, 23 reads the
  old en-CN digest and new stories read the new one, so 21 is still
  distinguishable from anything written afterwards.

  **Not in `StyleFingerprint`, and that was checked rather than assumed.** That
  method reads exactly four keys — `scenes.art_style`, `scenes.constraints`,
  `characters.reference_frame`, `characters.inherit_scene_style` — and none is
  this; `RunFingerprint` records `locale_profile`, the story's VALUE, and never
  the guidance text. So a naming edit stales no reference sheet, refuses no
  dispatch and stands no worker down. That is what makes it cheap, and it is the
  property most worth not losing later.

  Frozen at the OUTLINE, inside the transaction that writes the acts — because
  that is where the names, the setting and the spine are actually decided, and
  because a failed outline leaves no acts and must not attribute the story to
  guidance that produced nothing.

  **A drill passed, and the test was wrong rather than the guard.** The
  "scoped per profile" case removed the profile key from the hash entirely and
  stayed GREEN, because en-US and en-CN carry different guidance TEXT so their
  digests differ either way. The assertion was true for a reason other than the
  one it named. The state that makes the key load-bearing is two profiles whose
  guidance reads the same — a real possibility the moment one is forked from
  another — so there is now a case that builds exactly that. **Suspect the
  drill first, and then suspect the fixture.**

- **A CHARACTER'S NAME IS A GENERATION INPUT, NOT A LABEL: THE SEED IS DERIVED
  FROM IT. Inert today, and the reason it is inert is the thing that could be
  removed by accident.**

  ```php
  'seed' => crc32($story->slug.'|'.mb_strtolower(trim($profile->name)))
  ```

  Deterministic on purpose, and the call site says why: a cast rebuild lands on
  the same seeds rather than quietly re-rolling every face. The consequence has
  nothing guarding it — **changing a character's name changes their face.** A
  locked seed is half the consistency mechanism and the reference sheet is the
  other half, so a new seed means the next still of that character starts from a
  different point than the 30-90 before it.

  **It cannot fire today because nothing can rename a character.** There is no
  rename in any Livewire component, no console command and no form; `name`
  arrives once from `ExtractCharacters`, and a rebuild re-reads it from the same
  act scripts. The seed cannot move unless the scripts move, and if the scripts
  moved the faces should change.

  So this is written at `seedFor()` rather than only here, because the person
  who needs it is the one adding a rename, and they will be reading that file
  and not this one. Three options are set out there — store the seed instead of
  deriving it, let the rename stale the stills the way a retuned style stales a
  reference sheet, or refuse a rename once stills exist. **What must not happen
  is a rename that silently moves the seed**, because the cost is invisible
  until every still has been paid for, which is the exact drift the reference
  mechanism exists to prevent.

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

- **THE WORD WAS RIGHT AND THE READING WAS WRONG: `pencil` FIRED ON A PENCIL
  SKIRT.** `CharacterTextGuard` matches `\b<word>\b` with no head noun behind
  it, so a garment in the field that is FOR garments was refused as a handheld
  object — twice on story 25, $0.0869 of billed extraction, both attempts on
  the same word, because the repair loop re-asks the whole cast rather than the
  clause.

  **The fix is not to drop the words.** `pencil` is a real prop and story 12
  has a man carrying a leather folder; a list that removed every noun with a
  second sense would stop catching what it exists for. `NOT_AN_OBJECT` scopes
  the exception to the PHRASE, and **counts occurrences rather than setting a
  flag** — "carries a pencil and wears pencil skirts" must still fire, and a
  flag would let a real prop hide behind a garment in the same sentence. Both
  halves drilled red.

  Swept before touching anything: 60 stored characters across 6 stories, **zero
  garment false positives** — every live finding was a true positive, and
  `pencil` fired on nothing stored. The only instance was the extraction being
  refused in flight, which is invisible in stored data precisely because the
  guard refuses before persist. `mop` and `bowl` went in the same change and
  rank above `pencil`: they land on HAIR, and since idealised faces converge
  hair carries more of the identification than it used to.

- **TWO DEFECTS CANCELLED AND THE RESULT LOOKED LIKE A GUARD WORKING. IT IS NOT
  A FINDING ABOUT EITHER DEFECT, WHICH IS WHY IT IS ITS OWN ENTRY.**

  Story 25, 2026-09-09. Its act-script job was killed mid-run by a manual
  worker restart, leaving four of six acts written. `ExtractCharacters` then
  ran on the incomplete story and nothing stopped it — see the precondition
  entry below. It produced a four-act cast and was refused, twice, by
  `CharacterTextGuard`, for the word `pencil` in *"knee-length pencil
  skirts"* — a garment, in the field that is for garments, read as a handheld
  object.

  So the state on disk was: story 25 with zero characters. Which is the
  CORRECT state, arrived at for no correct reason. **The only thing standing
  between the operator and a four-act cast frozen onto a six-act story was an
  unrelated false positive on a skirt.**

  Neither defect knows the other exists. The guard was refusing a garment, not
  protecting a precondition; the missing precondition check was not made
  narrower by the guard firing. Change either one alone — fix the false
  positive first, or write the acts without noticing the cast — and the
  partial cast persists. **The order in which two unrelated bugs are repaired
  decided whether a bad cast was frozen**, and nothing in the codebase or in
  this file would have said so.

  Worth stating as a class rather than as this instance: **a system with
  enough guards in it will sometimes be saved by the wrong one, and the saving
  is invisible.** A green outcome is evidence about the outcome and not about
  the mechanism — the same sentence as a passing test that cannot fail, one
  level up, and with the same remedy: ask WHICH check produced the good state,
  not whether the state is good. Had the four-act cast persisted here, nothing
  would have reported it either; it would have been thirteen plausible
  characters described from two thirds of the story.

  The repair order used was acts -> cast -> guard, chosen for exactly this
  reason and recorded because the reasoning is the reusable part: the
  false positive was load-bearing until the thing it was accidentally
  blocking had been fixed properly.

- **I NAMED THIS TRAP, AND WALKED INTO ITS MIRROR IMAGE AN HOUR LATER. THAT IS
  THE ENTRY — NOT THE ORDERING.**

  The trap, as written at the end of the entry above: repairing scene text on a
  story past Gate 2 leaves the four scenes flagged, because
  `approved_narration_hash` is written at Gate 2 approval and nowhere else. The
  remedy given was to re-approve Gate 2. The warning given was "a second
  `assets:generate` would re-buy audio that is already correct".

  What happened next, with that paragraph already written down:

  1. Repaired the stored text.
  2. **Re-narrated the four scenes — $0.1274.**
  3. Re-approved Gate 2.

  At step 3 the approval record still described the PRE-repair text, so
  `narrationChanged()` was true, and `ApproveScenesGate::clearStalePaidAssets()`
  discarded all four files it had just been paid to make. Correctly, by its own
  rule — *"an asset is discarded because it demonstrably changed, never because
  we cannot prove it did not"* — and on evidence that was one step out of date.
  **The audio was current; the approval was the stale thing.**

  The recovery was free only by accident: the clear nulled `duration_ms` and the
  pointer but left `samples` standing, and 235,172 samples at 24 kHz is 9,799 ms
  — the post-repair length, not the 9,474 ms before it. ffprobe agreed with the
  row on all four. So a HALF-CLEARED ROW is what saved $0.1274, which is not an
  argument for half-clearing; it is the second time in two entries that a defect
  was rescued by an unrelated one.

  ---------------------------------------------------------------------------
  **A RULE YOU CAN ONLY FOLLOW BY REMEMBERING IT IS NOT A RULE.**
  ---------------------------------------------------------------------------

  This is the finding, and it outranks the ordering it is about.

  "Re-approve before you re-narrate" is true, was derivable from what was
  already on the page, and would have prevented this. It is also worthless as a
  safeguard: it lives in prose, it fires once per situation, it has no
  mechanism, and the person best placed to apply it had written the adjacent
  paragraph sixty minutes earlier. This file already says a prompt request with
  no mechanism reads as a guard while doing nothing — **the same is true of a
  procedure**, and the reader being the author is no protection at all.

  Three options existed and only one of them removes the need to remember:

  | | closes it? | why |
  |---|---|---|
  | re-point the files by hand | no | fixes this instance; the next reordering repeats it |
  | re-narrate again | no | pays twice for the same lesson |
  | **record what text the audio was made from** | **yes** | the order stops being a thing anyone has to get right |

  `scene_audio` recorded provider, voice, speed and simulated — WHO made the
  audio — and never WHAT WORDS were sent. So the only thing that could speak to
  staleness was a record of what the OPERATOR approved, standing in for a fact
  about the ARTIFACT. **Same axis distinction as `assertReady()` and the
  `updated_at` publication date, one layer down**: a claim about the record
  substituted for a claim about the thing.

  `narration_text_hash` is written at synthesis by `GenerateSceneNarration` and
  read by `narrationIsStale()`. Repair-then-narrate-then-approve and
  repair-then-approve-then-narrate now both keep the audio, because both end
  with a file made from the current text. There is no longer an order to get
  right.

  **NULL is UNKNOWN and falls through to the previous predicate**, deliberately.
  Reading it as "matches" would silently keep audio for text that no longer
  exists on all 971 pre-column rows; reading it as "differs" would make the
  backfill's absence a purge. Falling back changes nothing for a legacy row —
  the same discards happen as before, no more — and the trap closes for every
  row the first time it is narrated. Four rows are backfilled, the only four
  whose audio could be PROVEN to match, by ffprobe agreeing with the surviving
  sample counts.

  **And the half-clear is now a full clear**, mirroring exactly what
  `GenerateSceneNarration` writes. `samples` is what `AudioFrames` treats as
  authoritative for frame arithmetic — over `duration_ms`, deliberately — so a
  row with a null pointer and a live sample count is one another reader can
  compute frames from. Evidence belongs in a backup, not in a live row that
  other code reads as fact. **A row that can be half-believed is worse than one
  that is plainly empty**, and the fact that this instance was rescued by the
  half-belief is exactly the kind of luck the entry above says not to bank.

  Drilled: restoring `narrationChanged()` at the call site turns three of the
  four cases red, including the one that reproduces the shipped bug, while the
  unknown-provenance case stays green because that path is untouched.

- **A REPAIR VERIFIED THREE WAYS, AND ALL THREE WERE THE SAME CHECK. COMPLETE ON
  THE WRONG SUBJECT.**

  The four re-pointed scenes then failed the clip stage: *"has no audio duration,
  so its frame count is unknowable."* The re-point had been verified three times
  before it was declared done:

  | # | check | subject |
  |---|---|---|
  | 1 | ffprobe against the WAV | the artifact |
  | 2 | ffprobe's sample count against `scene_audio.samples` | the row describing the artifact |
  | 3 | `assets:generate --estimate` showing 0 pending, $0.00 | the asset stage |

  Three passes, three green, and **not one asked whether the next stage could use
  what was there.**

  **The fourth check shared the blind spot by construction, which is the detail
  that makes this land.** `assets:generate` looked like a consumer check and was
  not one: it reads `needsNarration()`, which reads `scene_audio`, which is the
  same table checks 1 and 2 were already about. It could only ever agree with
  them. Three checks that were really one, plus a fourth that inherited the same
  subject — and the appearance of independent confirmation is precisely what
  made it feel finished.

  What was actually missing was `scenes.duration_ms`, nulled one line ABOVE the
  `scene_audio` block in `clearStalePaidAssets()`. The repair was scoped to the
  table the damage had been observed in.

  **And the encoder never needed it anyway.** `Scene::framesAt()` prefers
  `scene_audio.samples`, which was populated and correct: 735, 294, 442 and 841
  frames, computable exactly. The guard tested `scenes.duration_ms` — the
  FALLBACK input — and refused rows whose authoritative input was sitting right
  there. Its own message says "its frame count is unknowable", a question
  `framesAt()` answers directly by returning null; the check never asked it.
  **A guard whose message names a question its check does not ask** is the
  proxy-for-the-real-thing shape, and it is now `if ($expected === null)`, with
  `backfillSampleCount()` moved AHEAD of it so a row this stage could repair
  from the file is not refused before it tries.

  **The drill is the part worth keeping.** Five behavioural cases were written
  for the new guard and every one of them reflected into `framesAt()`. Restoring
  the old `$scene->duration_ms === null` in the job left all of them GREEN — 8
  tests, 98 assertions, passing over the exact defect they existed for. That is
  `truncationMessage()` again: a builder that is correct, well covered, and no
  longer reachable from its call site. The delegation is asserted at the call
  site now, and drilled both ways — the old condition, and the backfill moved
  back after the guard.

  **That assertion then failed on the correct code**, because the docblock beside
  the guard QUOTES the old condition to explain the change. A source scan that
  cannot tell code from comment reports the explanation as the defect — the same
  class as blade-php-scan flagging a tag inside a block the compiler never
  compiles. It strips comments with `token_get_all` now.

  The general form, and it generalises past this repair: **counting checks is not
  counting coverage.** Three green results are one green result if all three
  interrogate the same object. When a repair is declared done, the question is
  not "how many ways did I verify it" but "which SUBJECTS did I verify, and is
  the consumer one of them" — and a check that reaches the consumer through the
  same table as the others has not left the first subject at all.

- **SECOND INSTANCE, AND IT IS THE SAME SHAPE FROM FURTHER AWAY: THE ONLY THING
  IN SIX STAGES THAT NOTICED A CORRUPT STRING WAS A GUARD ABOUT SUBTITLE
  MARKUP, WHICH CAUGHT IT BECAUSE BACKSLASH HAPPENS TO MEAN SOMETHING IN BOTH
  FORMATS.**

  The defect: on some calls the model emits a DOUBLED backslash inside its
  structured-output JSON, so `json_decode` faithfully produces the six literal
  characters `—` where an em dash was meant. The app decodes exactly once
  and correctly — this is not a missing decode, and the proof is that real em
  dashes and literal escapes coexist in the same story (story 25: 42 literal
  against 1,512 real; story 23: zero literal against 1,553 real). It is
  per-CALL: no single field value in the database has ever contained both
  forms, and an act's `script` and `summary`, which come from one call, always
  agree.

  100 occurrences, 23 rows, three stories. First appearance **story 21,
  2026-09-03**; story 23 is clean; stories 2-20 are clean. Same model
  throughout, both locales, so it is generation variance and nothing in this
  repo changed to cause it.

  **What it walked through untouched:** the outline call, the Gate 1 page an
  operator read and approved, six act-script calls, the scene draft, 250 paid
  stills, an ElevenLabs narration run and a WhisperX alignment — six stages and
  $16.64 on story 25 alone. It was stopped at the render step by
  `GenerateAssSubtitles::assertPlain()`, which refuses `[{}\r\n]` because
  braces and backslashes are ASS override markup and a stray one would corrupt
  the `{\k}` karaoke timing the whole format rests on.

  **That guard is correct and is about something else entirely.** It has no
  opinion about JSON, about model output or about text integrity; it caught
  this because `\` is both a JSON escape lead-in and an ASS control character.
  Change the corrupt character to almost anything else — a doubled `&amp;`, a
  stray BOM, a smart quote that should have been straight — and nothing in the
  pipeline would have said a word.

  **The catch reads as coverage, and that is the trap.** "The subtitle stage
  caught it" invites the conclusion that corrupt text gets caught. What
  actually happened is that one corruption happened to collide with one
  unrelated format's metacharacter, six stages downstream of where it entered
  and after every paid call had already been made. `tools/nonprintable-scan.php`
  could not have helped: it scans the repo tree, and this lives in the
  database.

  **Two asymmetries worth keeping.** The stills were untouched — `image_prompt`
  carried zero escapes across all 1,150 scenes in the database — but not
  because anything protects it; the frame sentence in those calls simply came
  back clean. The narration was NOT untouched: four scenes were synthesised
  from text containing the literal characters, and the aligner is what says so.
  A real em dash gets a 20 ms span, the aligner's floor for something unvoiced;
  the literal escapes got **401, 420, 421, 520 and 1,182 ms**. Something was
  spoken. The stored text is repaired and those four scenes are re-narrated for
  about $0.06.

  The fix is a boundary, not a fifth guard: `ModelText::undouble()`, applied
  once in `decodeJson()`, where every string the model sends enters this
  application. Four guards at four stages is the shape this file already
  rejects, and it would still leave stage five uncovered. It REPORTS — a count
  and the operation onto the open `render_jobs` row — because a boundary that
  silently repaired this would mean nobody ever learns the model is doing it,
  and the next variant would arrive with the pipeline looking healthy. The raw
  wire text is archived by `ResponseArchive` from the same method, before the
  decode, so the next question of this kind is a grep rather than an inference.

  ---------------------------------------------------------------------------
  **TWO MEASUREMENT FAILURES DURING THE INVESTIGATION, AND THE SECOND IS THE
  ONE TO KEEP.**
  ---------------------------------------------------------------------------

  **One: the sweep counted the wrong thing and under-reported.** The first
  scans looked for `\u`, found 100 occurrences and called that the population.
  Sweeping for BACKSLASH instead found seven more in story 21's act summaries —
  `entirely \"guardian one Song Yiran\"` — the same defect on a different
  character. A search shaped like the first instance finds the first instance;
  the class was "an escape the model doubled", and `\u` was one member of it.

  **Two: a probe read keys that did not exist, and the resulting silence was
  reported as a measurement.** The question was whether the corrupt text had
  been VOICED. The probe read `start` and `end` from the WhisperX timings; the
  keys are `start_ms` and `end_ms`. Every lookup returned null, and null was
  written up as "the aligner assigned no time range, so probably nothing was
  spoken" — a conclusion with the sign inverted, handed over as evidence.

  Reading the right keys reverses it. There is a free control in the same
  story: scenes whose narration holds a REAL em dash.

  | | token | aligned span |
  |---|---|---|
  | real em dash, 4 instances | `—` | **20 ms** every time — the aligner's floor for something unvoiced |
  | literal escape, 5 instances | `—` | **401, 420, 421, 520, 1182 ms** |

  Twenty to sixty times the control — which was reported as "something was
  spoken", and **that is wrong too.**

  **THE OPERATOR LISTENED. IT IS A CLEAN PAUSE: NOTHING WAS SPOKEN.** The
  escape never reached the audio, and this was a subtitles-only defect from
  start to finish.

  So the alignment data did not settle the question in EITHER direction. The
  aligner attributes a span to every token it is given, and for a token with no
  acoustic match it borrows from the silence on both sides — 401 to 1182 ms of
  pause, handed to the escape because the escape was in the text it was told to
  align. The 20 ms control does not rescue the reasoning either: a real em dash
  sits inside a normally-paced sentence with speech on both sides, so there is
  no silence for it to absorb. The two numbers differ because the SURROUNDING
  AUDIO differs, not because one was voiced and the other was not.

  **Three readings of one measurement, two of them confidently wrong and
  offered as evidence:**

  | reading | conclusion | why it failed |
  |---|---|---|
  | `start`/`end` returned null | "nothing was spoken" | the keys are `start_ms`/`end_ms`; absence read as agreement |
  | spans are 20x the control | "something was spoken" | right keys, real numbers, and the quantity does not mean what it was taken to mean |
  | the operator listened | **nothing was spoken** | settled it |

  The second failure is the more instructive one, because the first looks like
  carelessness and the second does not. The keys were right, the control was
  well chosen, the arithmetic was correct, and the ratio was real. What was
  wrong was the assumption underneath — that a forced aligner's span length is
  a proxy for whether a token was voiced. It is not, and nothing in the data
  says it is. **A measurement can be accurate, controlled, and still be
  answering a different question than the one asked**, and no amount of
  additional rigour inside the wrong frame corrects it.

  What was cheap and skipped, both times: nine seconds of listening.

  **That is absence read as agreement, inside the check written to settle the
  question** — this file's most repeated sentence, committed by the
  investigation rather than by the code, which is the version that is hardest
  to notice. A missing FIELD reads as a missing VALUE, and a missing value
  reads as zero, and zero was the answer that happened to fit the comfortable
  hypothesis. Nothing failed; the probe ran clean and printed NULL in a neat
  column.

  The practice that catches it costs one line: **a probe that reports an
  absence must prove it can report a presence.** Here that was free and sitting
  in the same table — the real em dashes are the known-answer case, and had the
  probe been pointed at them first it would have printed NULL for those too,
  which is impossible for text that is definitely there. Same rule as a guard
  that must be shown to go red, applied to a measurement instead of a check.

- **`ExtractCharacters::assertReady()` ASKS A POSITION QUESTION WHERE A
  PRECONDITION QUESTION IS NEEDED, AND THE FACT IT NEEDS IS ALREADY COMPUTED
  CORRECTLY IN TWO OTHER PLACES. Third instance of the precondition axis.**

  ```php
  if ($story->status->rank() < StoryStatus::Scripted->rank()) { throw ... }
  ```

  `scripted` says Gate 1 is behind this story. It cannot say the story carries
  six scripts, and story 25 was at `scripted` with four. It passed.

  This is the axis table's PRECONDITION row again and it is a sharper instance
  than the `voice_id` one, because here the guard is in the RIGHT PLACE —
  upstream, before the billed call, in the Action rather than the component —
  and still asks the wrong KIND of question. Placement was never the defect.
  A guard can be perfectly positioned and be checking the wrong axis, which is
  what makes the axis question worth asking separately from "is this guard
  early enough".

  **The fact is not missing. It is computed correctly twice, and neither
  copy is where the money is spent:**

  | | asks | verdict on story 25 | runs |
  |---|---|---|---|
  | `ExtractCharacters::assertReady()` | is the status >= scripted | passes | **first, and bills** |
  | `DraftScenes` (line ~190) | does THIS act have a script | throws, per act | second |
  | `OutlineGate::unwrittenActs()` | which acts have no script | reports acts 5, 6 | at Gate 1 |

  So the stage that spends money has neither, and the stage that throws runs
  after it. Rule 1 — a guard must be upstream of the thing it distrusts — with
  the guard and the thing in the right order and the WRONG STAGE holding the
  guard. `DraftSceneListJob` calls `ExtractCharacters` first by design (the
  cast feeds the scenes), so the billed stage is structurally ahead of the
  only completeness check in the pipeline.

  Not built. The repair is to ask `unwrittenActs()`'s question in
  `assertReady()` — free, one query, no new state — and it should refuse
  rather than warn, because a cast harvested from a partial story is wrong in
  a way no later stage inspects.

- **AND THE FILTER IS WHAT MAKES IT SILENT. `->filter(fn ($script) => trim($script)
  !== '')` TURNS "TWO ACTS ARE MISSING" INTO "HERE ARE FOUR SCRIPTS".**

  `ExtractCharacters` line ~83 drops the empty scripts on the way to the
  model, and the only presence check behind it is `if ($scripts === [])` —
  which asks whether there is ANYTHING, never whether there is EVERYTHING. Two
  missing acts and zero missing acts produce the same code path, the same
  prompt shape and the same success.

  **Absence read as agreement, inside a stage that spends money.** That
  sentence is the most repeated finding in this file and this is its most
  expensive placement so far: the filter is not a bug in isolation — dropping
  empty scripts is reasonable — it is that nothing counts what it dropped. A
  filter that discarded two acts and said so would have made the precondition
  gap visible without any new check at all.

  What story 25 escaped, measured after the acts were written: no character
  appears ONLY in acts 5-6, so the four-act cast would not have been missing a
  person outright. Four of thirteen — Cindy Hu, Grace Zhou, Sun Jianmin, Sun
  Jianping — have most of their named appearances in those two acts, so they
  would have been described from a minority of their material. **The hazard is
  structural and this instance did not realise its worst form**, which is
  worth recording precisely so the next reading of it is not "we checked and
  it was fine".

- **A REFUSAL MESSAGE STATED A PRECONDITION AS A FACT, HAVING CHECKED ONLY A
  STATUS. Found by being refused by it.** `OperatorAction::WriteScript` at
  `scripted`:

  > Gate 1 has been approved and **every act carries a script written against
  > this outline**. Reopen Gate 1 — that returns the story to "outlined"…

  Story 25 had four of six. `permittedAt($status)` is purely positional, so
  the clause after the "and" is an assertion nothing computed — the same
  substitution as `assertReady()` one layer out, except that here it is
  SPOKEN. A position check is at least honest about what it knows; a position
  check narrating a precondition tells the operator something false in the one
  sentence they were given to act on.

  It is the axis question's own FIGURE case, which this file lists as the
  fourth candidate and unwatched: a claim about the CONTENTS of a story, where
  no capability makes it true or false. `updated_at` standing in for a
  publication date is the same shape — right type, right-looking, never
  measured.

  Not built. The honest repairs are to drop the clause, or to compute it —
  `unwrittenActs()` is the same query the section above wants.

  Worth knowing while it stands: `story:write --acts-only=5,6` is deliberately
  scoped PAST this refusal (`$refusal !== null && $this->parseActsOnly() === []`),
  so a partial re-write is available and touches no gate state. That is the
  path story 25's acts 5 and 6 were written through.

- **THE THUMBNAIL POOL NEVER REACHED THE REVERSAL, AND THE DROP THAT SHAPED
  IT WAS SILENT.** Every story in the database with more than twelve scenes
  carried exactly six thumbnail candidates, all of them in acts 1-3:

  | story | scenes | flagged | by act |
  |---|---|---|---|
  | 21 | 270 | 6 | 2, 2, 2, 0, 0, 0, 0 |
  | 23 | 257 | 6 | 2, 2, 2, 0, 0, 0 |
  | 25 | 250 | 6 | 3, 2, 1, 0, 0, 0 |

  The prompt asked for "at most two scenes in this act" and the model
  complied, act by act. `DraftScenes::persist()` then kept nominations until
  a story-wide `thumbnail_candidates.max` of 6 was reached, walking the acts
  in order — so the cap filled by act 3 on every story, and the departure,
  the search and the refusal never contributed a candidate. `ComposeThumbnails`
  widens to the whole story only when the flagged pool is under 5, and 6 is
  never under 5, so the widened path had never run on a real story either.
  The pair score's +30 for a pair spanning the reversal — the reason
  `acts.phase` is read there at all — had fired only in its own test.

  **Nothing said so.** Everything past the sixth nomination was discarded
  with no note on the job row, no advisory at Gate 2 and no count anywhere,
  which is why a pool this shape sat under three published videos without
  anyone able to see it. The flagged badges at Gate 2 showed six honest flags;
  what they could not show was the seven or eight the model had also made.
  A nomination this stage throws away is an editorial judgement the model
  made and the operator never saw — the same silence as `resolvePresent()`
  dropping an unresolvable name, one field over.

  **Fixed as a per-act cap, not a larger story-wide one.** A story-wide cap
  walked front to back is biased toward the front by construction, whatever
  the number, and it would need re-deriving every time the act count moved
  (it has moved twice). `thumbnail_candidates.per_act` is 2, the prompt reads
  the same key so the request and the keep are one number, and nominations
  over the cap in an act are dropped WITH a line on the draft's `render_jobs`
  row naming the act and the count. The old suite was green throughout
  because the fake nominated one scene per act across a three-act fixture,
  and three against a cap of six cannot overflow — a fixture too small to
  hold the failure, which is the `--acts=` lesson again. The new case asserts
  its own size before it asserts anything else, and was drilled by
  reinstating the story-wide cap: red on act 1.

  **The three shipped stories cannot be re-pooled by this fix.** The
  nominations for their acts 4 onward were discarded at draft time and the
  response archive holds no files for stories 21, 23 or 25, so there is
  nothing to replay. The fix shapes every draft from here on; what those
  three would have flagged in their refusal acts is unrecoverable without
  asking the model again, which is a spending decision.

- **THE THUMBNAIL PROMPT AND THE THUMBNAIL RANKER HELD OPPOSITE OPINIONS
  ABOUT THE SAME FRAME, AND IT SURVIVED BECAUSE NOBODY PUT THE TWO TEXTS SIDE
  BY SIDE.** The nomination rule asked for *"a face mid-reaction, or an object
  that raises a question"*. `ThumbnailFraming` scores a frame with nobody
  recorded in it at -40, its most negative term. The model did what it was
  told: three of story 25's six flags were envelopes and documents on desks,
  and one composed pair of two empty desks scored -90 and was still offered as
  a selectable option.

  Decided one way — this niche's thumbnails are faces — and made to agree in
  both places: the object clause is gone from the prompt, which now says
  *never a frame with nobody in it*, the ranker's -40 is documented as
  deliberate, and `ThumbnailCompositionTest` holds the prompt text and the
  ranker's verdict in one assertion so a rewording of either that reopens the
  gap goes red. Drilled by restoring the old sentence — and the FIRST drill
  passed for the wrong reason: a `sed` that left an unbalanced quote produced a
  parse error, which is red with zero assertions and proves nothing. Re-done
  with an exact-match edit; two assertions, failed on the clause.

  **The rest of the scene instruction was read against every scorer that
  parses a frame, and this was the only contradiction.** The overlap check
  agrees with "never restate the sentence"; the hedged-expression advisory
  agrees with "NEVER HEDGE"; the close-frame setting check agrees with "where
  they are … what is in shot around them"; the -25 for a wide shot agrees with
  "never a wide establishing shot". One ABSENCE is worth naming so it is not
  read as covered: nothing asks a frame to state its shot scale, and the
  ranker scores an unstated scale at zero — 134 of story 21's 270 frames, 131
  of story 25's 250. That is the "asking for nothing in particular" shape
  rather than a contradiction, and it is left alone here because fixing it
  changes every frame in the video to serve one picture.

- **THE PAIR SCORE'S DOCBLOCK CLAIMED A TERM NOTHING IMPLEMENTED, AND THE
  NO-PHASE FALLBACK MEASURED THE WRONG DENOMINATOR.** Two smaller findings
  from the same pass, both closed.

  *"The pair score prefers two different leads"* had no term behind it. Story
  23 offered the same two people three times out of four; story 25 shipped
  Kevin beside Kevin in the same shirt. There is a term now: -12 when everyone
  on one panel is also on the other, with the names printed in the reason. The
  number is CHOSEN, like every weight in this area, and sized to decide a tie
  between equally framed stills without outranking the framing. Drilled by
  disabling the term: red.

  The fallback for a story with no `acts.phase` divided the gap between two
  panels by the highest sequence IN THE RANKED POOL rather than the story's
  last scene. On story 21 every flag sat inside the first 107 of 270 scenes,
  so scene 20 against 107 was reported as "opposite ends of the story" at
  0.81 while spanning 32% of the video. It divides by the story's last scene
  now, and the re-composed story 21 correctly reports no such thing.

  **Re-composed, and the pool is still what decides.** Stories 21, 23 and 25
  were re-composed after all four fixes, free, no cost row. Every composition
  on 23 and 25 still says *both panels are from the escalation phase*, because
  the pool is the same six escalation-act flags it always was and nothing in
  the pair score can reach a scene that was never flagged. What changed is the
  ORDER and the honesty of the reasons: story 23's top pair now shows two
  different couples instead of the same couple twice, and story 21 no longer
  claims two escalation scenes are opposite ends of anything. A different KIND
  of thumbnail needs a different pool, and that begins with the next draft.

  **Two things the re-compose exposed**, both closed in the entry below: the
  top-six slice in `pairs()` that gated item 1's effect one function
  downstream, and a kept selection that could name a different picture
  because the keys were positional. They are left named here because this
  re-compose is the run that re-pointed story 23's pick — see the entry below
  for what that cost and how the row was repaired.

- **THE PAIR SCORE NEVER SAW THE POOL IT WAS WRITTEN FOR, AND THE OPERATOR'S
  PICK WAS A SLOT NUMBER.** Two closures from the thumbnail pass, and the
  second is not about thumbnails.

  **`pairs()` sliced the ranked pool to six before scoring a single pair**, and
  the widened path sliced its own to eight one step earlier. Both cuts were
  taken on the per-still framing score, which cannot see the pair terms it
  feeds — so the +30 for a pair spanning the reversal was only ever applied to
  stills that had already out-framed everything else, and a late-act flag
  reached a composition only if it beat the escalation flags on framing alone.
  With the per-act cap in place that made item 1 a fix whose effect was
  blocked one function downstream. Both slices are gone: 14 flags is 91 pairs
  of arithmetic on loaded data, and a whole 270-scene story is ~36,000, still
  milliseconds. Nothing else read either number. Drilled red both ways.

  **The pick.** Compositions were keyed `thumb-1..4` and a re-compose kept the
  operator's pick whenever its KEY still existed. Story 23's pick was
  `thumb-4`; the re-compose after items 2-4 put a different pair of stills in
  slot four, and the record went on saying `thumb-4` while the delivered file
  in the operator's folder showed the pair it used to mean. **A recorded
  choice silently changed meaning while the record stayed the same** — the
  audio-provenance finding one field over: `scene_audio` recorded WHO made the
  audio and never WHAT WORDS, so nothing could speak to staleness; here the
  row recorded WHERE the pick sat and never WHAT IT SHOWED.

  The key is now the two scene ids, the option carries a fingerprint of the two
  source files (a still can be regenerated under its own scene id, and then
  the same pair names a different picture), and `carrySelection()` does one
  of three things and says two of them: keeps the pick under the new key when
  the same pair is built from the same files; clears it and names the scenes
  when the pair is gone; clears it and says a still was regenerated when the
  pair is present and the fingerprint moved. A NULL fingerprint on an old
  record is UNKNOWN and keeps the pick by scene pair — cleared because it
  demonstrably changed, never because we cannot prove it did not, which is
  `narration_text_hash`'s rule. A legacy `thumb-N` pick resolves through the
  scenes slot N HELD, not slot N of the new set. Six red/green cases.

  **The migration is only as honest as the record it reads, and story 23's
  record had already been re-pointed once.** The operator picked `thumb-4`
  when slot four held scenes 81 + 103 — the file in their `Done/` folder is
  that pair, byte for byte. The re-compose after items 2-4 ran under the OLD
  positional code and moved slot four to scenes 24 + 81 while the pick stayed
  `thumb-4`. So when the new code migrated the pick, it faithfully carried
  what the row held, which was 24 + 81, and 81 + 103 is not in the current
  set at all. The row was cleared by hand — the only value it can truthfully
  hold — and the operator picks again. Story 25's pick survived by luck: slot
  two held 41 + 59 in both runs. **A positional key is wrong on the FIRST
  re-compose after the pick, not the second, and nothing downstream can
  recover what it meant once it has moved.**

  **Title selection was checked for the same shape and does not have it.**
  `title_selected` stores the chosen TEXT, not an index into `title_options`;
  `chooseTitle()` copies the string out of the list in the same request, and a
  `--force` regeneration replaces the list and leaves the chosen text standing.
  That is the correct shape — the string is the deliverable, so the record is
  the artifact. Worth writing down because the two fields sit side by side on
  one sheet and were built the same week, and only one of them was wrong.

  **And a probe defect of my own, kept because it cost a turn.** The first
  thumbnail report said stories 23 and 25 held a selection but no delivered
  `<slug>.jpg` existed. Both files existed, in a `Done/` subfolder the operator
  had moved them into alongside the videos. I listed one level of a folder and
  reported an absence. Same rule as the WhisperX keys: a probe that reports an
  absence has to be shown able to report a presence, and here the presence was
  one `ls -R` away.

- **DOES THIS RECORD SURVIVE ITS SUBJECT BEING REGENERATED? A STANDING
  QUESTION FOR ANY STORED OPERATOR DECISION.** Two defects this week were the
  same defect one field apart, and neither is about the field it was found in.

  | | the choice | what the row recorded | what it stood for | what moved underneath |
  |---|---|---|---|---|
  | 1 | Gate 2 approval of a scene's narration | the approved TEXT, on the approval | the audio file | the text was repaired, the audio re-made, and the approval re-done in the wrong order — `scene_audio` knew WHO made the file and never WHAT WORDS it was made from |
  | 2 | Gate 4 pick of a thumbnail | a SLOT, `thumb-4` | a pair of stills | a re-compose put a different pair in slot four; the row went on saying `thumb-4` |

  In both, the record described a POSITION or a STATE — a place in a list, a
  gate crossing — rather than the artifact the operator actually chose, and in
  both it was correct for exactly as long as nothing was regenerated. That is
  why neither could be found by reading it: a slot number and the pair it holds
  agree on the day the pick is made, and the disagreement only exists after a
  second run, in a row that has not changed.

  **The question, asked of every column that holds an operator's decision:
  if the thing this decision is ABOUT were regenerated tomorrow, would this row
  still mean what the operator meant — or would it silently mean something
  else?** A record that names the artifact (its text, its scene ids, a hash of
  its bytes) survives; one that names where the artifact sat, or what state
  the story was in when it was chosen, does not. The repair is the same both
  times: `narration_text_hash` on the audio, scene ids plus a stills
  fingerprint on the composition — the record made to describe the thing.

  **`title_selected` passes, and the passing case is what makes the rule
  readable.** It stores the chosen TEXT, not an index into `title_options`;
  `chooseTitle()` copies the string out of the list in the same request, and a
  `--force` regeneration replaces the five variants and leaves the chosen
  string standing on its own. That is not an accident of implementation: the
  string IS the deliverable — it is what gets typed into YouTube — so the
  record and the artifact are the same bytes and nothing can move between
  them. Contrast `thumbnail_scene_id`, one column over on the same sheet: it
  names a scene by id, a full re-draft deletes and recreates every scene row,
  and the pointer dangles. That is the honest failure mode — it points at
  nothing rather than at something else — but it is still a record that does
  not survive its subject, and it is unchecked.

  **The luck, recorded because it is the third time.** Story 25's pick
  survived the positional re-compose because slot two happened to hold scenes
  41 + 59 in both runs. Nothing preserved it; the two lists coincided. The
  three rescues-by-coincidence this week:

  | | what was saved | by what |
  |---|---|---|
  | 1 | story 25 from a four-act cast frozen onto a six-act story | an unrelated false positive on the word `pencil` |
  | 2 | $0.1274 of re-narration from being discarded | a HALF-cleared row that left `samples` standing |
  | 3 | story 25's thumbnail pick from silently changing meaning | slot two holding the same pair twice |

  Each one made a broken mechanism look like a working one, and each was
  visible only because a neighbouring instance of the same defect failed
  properly at the same time. **A green outcome is evidence about the outcome
  and not about the mechanism**; when a decision survives a regeneration, ask
  WHAT preserved it before crediting the code.

  **And when the old pick cannot be recovered, the field stays EMPTY.** Story
  23's `thumbnail_selected` is null on purpose and stays that way. The
  operator chose scenes 81 + 103; that composition is the delivered file and
  is the one on YouTube; it is not in the current set. Writing any current key
  into the row would create a record that does not match the artifact — the
  exact defect the repair removed, reintroduced by hand so the column would
  look populated. The repair made the row unable to lie; the right response
  to an unrecoverable old decision is to leave it saying nothing was chosen
  from THIS set, because that is true. A field that is empty and honest beats
  one that is full and wrong, and "empty" is a state every reader of this
  column already handles.

- **THE OUTLINE CEILING WAS BEING SPENT ON REASONING, NOT ON THE OUTLINE —
  AND THE THREE CALLS THAT SHOWED IT LEFT NO TRACE.** Story 28 hit the
  16,000-token outline ceiling twice in a row on 2026-09-12, about $0.84 for
  no outline. The archives that should have answered "where was the output
  going when it stopped" did not exist, so the answer came from the ledger
  against the stored outlines instead:

  | story | outline text stored (chars) | output tokens billed | chars per output token |
  |---|---|---|---|
  | 21 | 16,974 | 3,638 | 4.67 |
  | 22 | 15,373 | 5,198 | 2.96 |
  | 23 | 20,086 | 5,483 | 3.66 |
  | 25 | 19,006 | 4,970 | 3.82 |
  | 26 | 16,646 | 14,727 | 1.13 |
  | 27 | 19,924 | 13,511 | 1.47 |
  | 28, at medium | 14,037 | 4,693 | 2.99 |

  Stories 26 and 27 stored outlines the same size as story 25's — 26's is the
  smallest in the table — and billed three times the output tokens. Roughly
  10,000 tokens per call were not text. The stream consumer's own comment
  names the only thing that can be: thinking deltas are billed as output and
  discarded. The outline runs at effort `high`.

  **Separated by one run, not by argument.** Story 28 was re-run once with
  `ANTHROPIC_EFFORT_OUTLINE=medium` and the ceiling left at 16,000: complete in
  77 seconds at 4,693 output tokens, $0.1495, on an input of 2,336 tokens and
  the same 3,282-token cache write as every other en-CN story. Same prompt,
  same ceiling, one setting changed, and the call that could not finish at
  high finished at a third of the ceiling at medium. The premise is ruled
  out: input was flat across five stories (2,266 to 2,336 tokens) and the
  returned outline did not grow. Splitting the call is ruled out for the same
  reason — each half would reason on its own.

  **What is NOT concluded, written as what it is.** Every input on our side
  was flat across five stories. Output tripled between two calls eight hours
  apart on 2026-09-09 — story 25 at 00:14, story 26 at 08:40 — on a `.env`
  unchanged since 09-04, with no outline code change between them, and with
  the cache write identical on both, so the system prompt was byte-identical.
  The cause of that shift is unknown and is not on our side. It is NOT
  recorded as a model-side change, because nothing here can verify one; it is
  recorded as a boundary in the data with nothing of ours on either side of
  it. **Pinning a dated model snapshot instead of the `claude-opus-5` alias**,
  if the account's model list offers one, is the option that would both
  explain and avoid a shift of that kind. It is untested.

  **The ceiling was never derived and the p99 cannot be taken from ten
  calls.** Sorted successful outline output: 3,638, 4,970, 5,169, 5,198,
  5,483, 5,516, 5,575, 6,249, 13,511, 14,727. Before 09-09 the maximum was
  6,249 and 16,000 was 2.6x it; since, two of four calls at high have exceeded
  it. The ceiling is not the lever, and the remedy in config now says so —
  the previous one said "generation variance: RE-RUN IT FIRST", which was
  followed on story 28 and is what the $0.84 bought.

  **The outline runs at effort MEDIUM by default now, and the ceiling stays
  at 16,000.** Whether a medium outline is as good was a Gate 1 judgement on
  story 28's, not a number, and the operator read it against the high-effort
  outlines and found it as good. So the default moved. What it cost to learn
  that the ceiling was being filled by reasoning rather than by the outline:
  three truncated calls and about $1.27, none of it in the ledger at the time.
  The ceiling is deliberately NOT raised to give medium headroom: a medium
  outline measures under a third of 16,000, so a truncation at medium is new
  information — something has moved again — and it should arrive as a failure
  that is seen, not be absorbed by headroom nobody would notice being used.

  ---------------------------------------------------------------------------
  **2026-09-19: STORY 37 AT 91%. THE TEXT IS GROWING STEADILY AND THE
  REASONING IS NOT GROWING AT ALL — IT IS BIMODAL. The ceiling is not raised.**
  ---------------------------------------------------------------------------

  > **STANDING POSITION, the operator's, 2026-09-19. When the outline
  > truncates, do not reach for `ANTHROPIC_MAX_TOKENS_OUTLINE`.** There were
  > about four average spine fields of room left on story 37's measurement.
  > The only lever left is outline effort `low`, which nothing has measured.
  > Whether a low-effort outline is good enough is a Gate 1 judgement on the
  > outline it produces, as medium was. A truncation is information, and it
  > has to be SEEN: the cost row records `stop_reason` and `effort` (since this
  > date), and Gate 1 lists every outline call that stopped at the ceiling,
  > read from the ledger, so the successful re-run that resets the job row
  > cannot hide it. The truncation remedy in config says the same.

  Measured, not converted: the returned outline's tokens were COUNTED with the
  outline model's own tokenizer (`countTokens`, free), exactly on stories 29,
  30 and 32 from their archived responses, and on the rest from the stored
  spine, cast and act titles plus the archived mean outline act summary (378
  tokens; the stored summaries are the act writer's once scripts exist).
  Reasoning is billed output minus that text. The rebuilt rows carry about
  ±500 tokens of estimate error, visible as small negative "reasoning" on
  calls that did not reason.

  | story | effort | output | text | reasoning | text % of 16,000 |
  |---|---|---|---|---|---|
  | 22-25 | high | 4,970-5,483 | 5,364-5,731 | ~0 | 33-35% |
  | 26, 27 | high | 14,727, 13,511 | 5,635, 5,615 | 9,092, 7,896 | 35% |
  | 28 | medium | 4,693 | 5,225 | ~0 | 32% |
  | 29 | medium | 12,001 | 6,084 exact | 5,917 | 38% |
  | 30, 32 | medium | 5,265, 4,832 | 5,263, 4,830 exact | **2 and 2** | 30-32% |
  | 33, 34 | medium | 5,619, 5,348 | 5,488, 5,224 | ~0 | 32-34% |
  | 35 | medium | 13,167 | 5,597 | 7,570 | 34% |
  | 36 | medium | 10,104 | 6,478 | 3,626 | 40% |
  | 37 | medium | 14,580 | 7,066 | 7,514 | **44%** |

  **Reasoning is bimodal, not trending.** At medium, five of nine calls
  reasoned for essentially nothing — stories 30 and 32 billed two tokens more
  than their text — and four reasoned 3,600-7,600 tokens. Story 37's own two
  attempts, on prompts 249 tokens apart, billed 5,952 and 14,580. The high-
  water mark at medium is 7,570 (story 35); story 37's 7,514 is not a new
  peak. What made 37 the highest reading is the TEXT floor under it.

  **The text is the part that grows, and it grows with every field.** 5,200-
  5,600 tokens on stories 22-35, 6,478 on 36 (3g's four accomplice and
  thought fields), 7,066 on 37 (3h's regret, 439 tokens, and the narrator
  row). Story 37's spine is fifteen fields at a mean of 273 tokens, from 88
  (the justification, the running thought) to 459 (the betrayal scene).

  **What the next field does.** Headroom for reasoning on story 37 is 16,000 −
  7,066 = 8,934 tokens. The largest reasoning seen at medium is 7,570. One
  more average field (~273) leaves ~8,660; that clears the medium peak by
  ~1,100, which is about four average fields. The margin that is ALREADY gone:
  story 26 reasoned 9,092 at high, and on today's text that call would
  truncate. So at medium the outline is roughly four fields from a call that
  reasons as hard as story 35 did failing at the ceiling — billed in full at
  about $0.48 (16,000 output tokens at $25/M, plus story 37's input) — and
  nothing about the reasoning half is predictable per call.

  The lever is not the ceiling (a truncation bills at the ceiling, and
  headroom hides the next shift) and it is not the field count on its own.
  **Unmeasured and named, not built:** effort `low` on the outline would bound
  the reasoning mode, and nothing has measured whether a low-effort outline is
  as good — that was a Gate 1 judgement for medium and would be one again. A
  field that the outline asks for and nothing downstream reads would be the
  cheap cut; there is none today, since every spine field has a reader. The
  outline-ceiling watch the memory file had closed is REOPENED on this reading.

  ---------------------------------------------------------------------------
  **THREE DEFECTS ON THE FAILURE PATH, ALL OF THEM "A CALL THAT COSTS MONEY
  AND LEAVES NO TRACE", ALL CLOSED.**
  ---------------------------------------------------------------------------

  1. **A truncated call wrote no ledger row.** The ceiling check threw before
     pricing, so the message said *"billed in full, at the ceiling"* while
     `cost_entries` said nothing — a figure claim with no row behind it, and
     non-negotiable #4 failing in the shape it is written to prevent. The same
     was true of a refusal. `TalksToClaude::settle()` now prices a failed call
     and writes the row against the stage being recorded BEFORE it throws, and
     the message names the row it made. Outside a recorded stage it says the
     spend is NOT in the ledger rather than claiming a bill.
  2. **A truncated response was never archived.** The archive line sat inside
     `decodeJson()`, after the ceiling check, so the one response its own
     docblock says is worth keeping was the one never kept. It is written in
     `settle()` now, first, before any check — and once, since the decode step
     no longer archives as well.
  3. **The configured remedy never reached the message.** `operationConfig()`
     returned model, effort and max_tokens and dropped `truncation_remedy`, so
     the shipped message said *"No truncation_remedy is configured for this
     operation"* while config carried a six-line one. `TruncationMessageTest`
     reads config directly and stayed green throughout — the builder was
     correct and the value never reached it, which is that file's own founding
     defect one key over. The remedy travels with the ceiling now, and one
     test goes through the same path the worker does.

  Each drilled red: the ledger write disabled, the archive moved back behind
  the check, the remedy dropped again. `StreamedMessage::of()` exists so the
  step after the stream can be exercised without one; before it, the
  truncation path had no test because a message could only be built by
  consuming a real stream.

  **The three truncations are NOT backfilled, and here is exactly what is and
  is not provable about them.** Story 23's second run and both of story 28's
  at high. Input is provable by identity — the prompt bytes did not change
  between attempts, and the completed runs measured 2,266 and 2,336 — and the
  3,282-token cache write is identical on every en-CN row. The output is the
  whole bill, about $0.40 of each $0.43, and it rests on the API reporting
  exactly 16,000 output tokens at `max_tokens`, which is what it does and
  which no surviving record shows for these three, because the usage object
  was discarded with the exception. The second story-28 attempt started 2.5
  minutes after the first ended, so its cache split is a guess about a 5-minute
  TTL. Rather than write three rows whose largest figure is assumed, the spend
  is recorded here: roughly $1.27 across three calls, billed by the vendor and
  absent from `cost_entries`, reconcilable against the vendor's usage page for
  2026-09-05 and 2026-09-12 by anyone who wants the exact figure.

  **And one thing seen on the way, not fixed.** `RenderJob::record()` reopens
  a stage's row with `updateOrCreate`, so the successful medium run overwrote
  job 8949 — the row that had recorded the failure — and `render_jobs` now
  shows story 28's outline as a 77-second success with no sign that two
  attempts before it hit the ceiling. The failures survive in `failed_jobs`
  and the log, not on the story's own stage list.

- **TWO REMEDY STRINGS HAVE NOW GIVEN ADVICE THE DATA CONTRADICTS, AND BOTH
  WERE WRITTEN BEFORE ANYTHING WAS MEASURED.** This is the finding; the scene
  stage below is only its second instance.

  | stage | what the remedy said | what measurement said |
  |---|---|---|
  | `generate_outline` | "generation variance: RE-RUN IT FIRST" | the ceiling was being filled by reasoning at effort `high`; the re-run was followed on story 28 and cost $0.84 for no outline. One medium-effort run fixed it |
  | `draft_scenes` | "the lever is the ACT LENGTH upstream, not anything here — raise `ANTHROPIC_MAX_TOKENS_SCENES`" | ~71% of the call was reasoning; act length is a term and not the lever; and raising the ceiling makes the failure DEARER, because a truncated call bills at whatever the ceiling is |

  The scene remedy was wrong a third time, in a detail nobody would check: it
  cited "story 21 act 3, 14,031 output tokens". Act 3 came back at 8,321. The
  14,031 was act SIX.

  **The shape, which is what makes this a class rather than two mistakes.** A
  remedy is written when a stage is BUILT, from a plausible model of why it
  would one day fail. It is read exactly once per incident, at the only moment
  anyone acts on it, by someone who has just lost a call and is deciding what
  to do next. Both were plausible. Both named a knob that was not the cause,
  and one named an env var this app does not read. **A remedy is a claim about
  CAUSE, made in prose, and nothing in this project checks that kind of claim**
  — the axis question again, one layer out from a guard: `TruncationMessageTest`
  asserts the remedy REACHES the message and has no opinion about whether it is
  true.

  What the corrected ones do differently, and it is the only protection
  available short of measuring every stage in advance:

  - **Each names the measurement it came from**, with the numbers, so the next
    reader can see what it rests on rather than trusting it.
  - **Each names the observation that would REFUTE it.** Both now say that a
    truncation at the corrected setting is new information — something has
    moved — and should be recorded before retrying. A remedy that cannot be
    wrong is the documented-guard shape in prose.
  - **Neither says to raise the ceiling.** That was the reflex in both, and it
    is backwards in both: the failure is billed at the ceiling.

  **The ceilings stay at 16,000 for the same reason in both places.** A
  corrected outline measures under a third of it and a low-effort scene call
  should too, so a truncation is a signal rather than something headroom
  quietly absorbs.

- **NOBODY CHOSE ADAPTIVE THINKING ON THE SCENE STAGE. IT ARRIVED BECAUSE THE
  CLIENT SENDS NO `thinking` PARAMETER AND SONNET 5 DEFAULTS IT ON.** The
  outline's defect one stage over, found the same way — by a truncation — and
  on the one stage in the pipeline whose job is mechanical: cut an act that
  already exists into scene ranges and describe each frame.

  `TalksToClaude::call()` builds `output_config` and nothing else, so every
  Sonnet call in this app has been running adaptive thinking since it was
  written. It is not a setting anyone picked and it is not visible in config —
  the absence of a parameter is the whole cause, which is why no amount of
  reading `config/providers.php` would have shown it.

  **Measured on story 28 act 1, where the discarded Haiku attempt is a free
  control** — the same act, the same schema, no thinking:

  | | chars of JSON | output tokens | chars per token |
  |---|---|---|---|
  | Haiku 4.5 (no thinking) | 17,143 | 4,227 | 4.06 |
  | Sonnet 5 (adaptive, medium) | 16,090 | 13,720 | 1.17 |

  About **9,800 of Sonnet's 13,720 tokens — 71% — were not the scene list.** On
  the act that truncated the figure is ~11,600 of 16,000, against a complete
  scene list that measures ~4,900.

  **`fallback_effort` is `low`, not `thinking: disabled`, and the reason is
  measured rather than stylistic.** Disabling thinking is accepted on Sonnet 5
  and would cut more. It is refused here because **no thinking is a measured
  failure mode on this exact call**: Haiku 4.5 runs with no thinking, it is the
  PRIMARY on this stage, and it has been accepted on 0 of the last 38 acts. The
  fallback exists precisely because the no-thinking attempt was not good
  enough, so answering a truncation by removing thinking from the fallback too
  would be answering it with the thing that already failed. `low` is the only
  setting between the two we have data for. Two lesser reasons: disabling
  thinking has its own documented failure modes — internal tags leaking into
  the visible response — which on a machine-parsed JSON stage is a decode
  failure and a re-bill; and `fallback_effort` already exists and is
  env-tunable, so this is one value, rehearsable and revertible, where
  disabling would be a new request field and a new axis nothing else uses.

  ---------------------------------------------------------------------------
  **WHAT STORY 12 SETTLED, AND WHAT IT DID NOT. THE SECOND HALF MATTERS MORE.**
  ---------------------------------------------------------------------------

  Two whole-story re-drafts, same story, same prompt, same code, differing only
  in `fallback_effort`. $0.84 for both. A control run was needed because the
  scene prompt gained character bounds the same morning, and comparing across
  that would have been the mixed-story defect this file already records against
  story 12 itself.

  | | B, medium (control) | C, low |
  |---|---|---|
  | sonnet output p50 / max | 3,718 / 5,708 | 3,378 / 4,152 |
  | max as % of the 16,000 ceiling | 36% | 26% |
  | scenes | 163 | 148 |
  | scene words p50 | 30 | 36 |
  | scenes under 8 words | 6.1% | 4.1% |
  | static share | 11.7% | 11.5% |
  | frame p50, chars / words | 156 / 27 | 146 / 25 |
  | expression block on peopled frames | 92.6% | 94.2% |
  | names what the face is DOING | 77.0% | 74.8% |
  | hedged, of blocks | 21.2% | 21.6% |
  | Gate 2 warnings | 5 | 4 |

  **The quality axes are flat, and that is what the run was for.** Nothing moved
  more than a couple of points, and the two that moved most moved in opposite
  directions — expression coverage up, "names what the face is doing" down —
  which is the shape of noise rather than of an effect.

  **IT DOES NOT ESTABLISH THE TOKEN SAVING, and reading it as though it does
  would be this file's own most repeated mistake.** One run per arm, no
  replicate. The only same-effort pair available — the historical draft against
  the control, both medium — moved the max from 8,892 to 5,708, a 36% swing
  with the effort UNCHANGED, which is larger than the 27% between medium and
  low. Story 12 cannot separate the effort effect from run-to-run variance and
  is not evidence about the size of it.

  What establishes the MECHANISM is the story 28 control above — the same act,
  the same schema, thinking against no thinking, 4.06 against 1.17 chars per
  output token. That is a within-call comparison and is not subject to this.

  **And story 12 is a weak proxy for the failure anyway.** Its acts are 828-955
  words; story 28's are 1,203-1,863 and the one that truncated is 1,863. The
  headroom question is answered by story 28 when it is retried, not here.

  **One movement to watch that is not a quality axis.** Scenes fell 163 to 148
  and scene length rose 30 to 36 words — fewer, longer stills. That is a pacing
  and image-spend change rather than a defect (36 words is ~11 s at 197 wpm,
  inside the 8-16 s band the render was measured against), but 148 sits under
  the 150-250 the format budgets, and the SAME movement appeared between the
  historical draft and the control with no effort change at all, 175 to 163.
  Watch it on the next full-length story rather than reading it as an effect.

- **THE QUALITY GATE IS APPLIED TO THE CHEAP MODEL AND NEVER TO THE EXPENSIVE
  ONE, AND THE EXPENSIVE ONE WOULD FAIL IT TOO. This is the answer to "is Haiku
  incapable", and it is no.**

  `ClaudeScriptWriter::scenes()` runs `unusableReason()` on the FIRST attempt,
  falls back when it fires, and never re-runs it. `DraftScenes` re-checks
  exactly one of the four axes on the final draft — tiling, which refuses. The
  other three are measured only against the attempt that gets thrown away.

  Measured on the accepted, shipped drafts — Sonnet's output, per act, against
  the very ceilings Haiku is rejected on:

  | story | worst act, short scenes (ceiling 5%) | worst act, static (ceiling 15%) |
  |---|---|---|
  | 12 | 11.1% | 20.8% |
  | 21 | 10.0% | 16.7% |
  | 23 | 7.5% | 30.2% |
  | 25 | 7.3% | 23.3% |
  | 26 | 9.1% | 37.5% |
  | 27 | 8.7% | 25.5% |

  **All six of those stories have an act over both ceilings.** Story 9 is left
  out of the table because it was drafted before the short-scene and static
  checks existed; measured anyway it is 0% short and 23.3% static, so it
  breaches one of the two as well. The 0-of-38 record is therefore not a
  statement about Haiku's relative quality; it is a statement about which model
  gets measured with a refusal.

  **The static one is invisible rather than merely tolerated**, and that is the
  sharper half. `ValidateSceneDrafts` checks the same thresholds at Gate 2 from
  the same config keys, but STORY-WIDE where the fallback gate is PER ACT.
  Story 12's worst act is 20.8% static; its story average is 11.7%; so no
  warning renders. An act that would have refused the cheap model passes
  silently in the expensive one's output because five other acts dilute it.

  **Two of the three soft axes are also never REQUESTED.** The scene prompt
  says "aim for roughly 30 words per scene" and the motion guidance says use
  static "sparingly" and "vary it". The gate says ≥8 words on 95% of scenes,
  ≤15% static and ≤55% any single preset. No number in the gate appears in the
  prompt. A model is being refused for missing a target it was never given —
  and the expensive model only clears the short-scene ceiling by about a point
  when it clears it at all.

  Not fixed here, because there are three defensible repairs and picking one is
  an editorial call: state the numbers in the prompt, re-run the gate on the
  fallback's output too, or make Gate 2's motion checks per-act so the final
  draft is judged the way the discarded one is. The first is free and would
  probably move the most.

- **MY OWN COUNT OF THAT RECORD WAS WRONG, AND IT WAS WRONG THE WAY THIS FILE
  KEEPS RECORDING.** I first reported Haiku losing "31 of 38 acts", i.e. winning
  7. The 7 were story 21, whose draft predates reason-logging: its log says
  "(first attempt discarded, fell back)" and my regex looked for "(fell back:".
  **A story that could not record a reason was counted as a story with no
  reason to record** — absence read as agreement, in a probe, again. Counted
  from the ledger instead, where a lone `draft_scenes` row means accepted and a
  pair means it fell back, the record is **0 of 38**.

  The ledger defect below corrupted the same count in the other direction:
  story 28 act 2 has one row because the Haiku row was never written, so it
  read as a Haiku win.

- **A DISCARDED SCENE ATTEMPT LEFT NO LEDGER ROW WHEN THE FALLBACK THREW.**
  `scenes()` bills Haiku, keeps its usage in `$discarded`, calls Sonnet, and
  hands both back in a `SceneDraftSet` for `DraftScenes` to record. A throw
  means there is no set to hand back, so the Haiku usage dies with the
  exception — story 28 act 2, about $0.035, billed and absent. The Sonnet half
  of the same act WAS recorded, by the truncation path added last week, which
  is the only reason the gap was visible at all: one row where there should be
  two.

  Same shape as the truncated outline calls: a call that happened and left no
  row, non-negotiable #4. Fixed by giving `TalksToClaude` a usage-taking
  `recordSpendWithNoAction()` — the body `recordFailedCallSpend()` already had
  — and calling it from the fallback's catch. The decode is inside the try for
  the same reason: a response that arrives and will not parse is one more way
  to leave the first call unrecorded.

  Asserted at the CALL SITE on comment-stripped source, because the failing
  path needs two live streams and `scenes()` builds both from a real client —
  the `truncationMessage()` lesson, where a correct builder was unreachable
  from its caller.

- **`image_prompt` HAS A READER NOBODY COULD HAVE ENUMERATED: A VENDOR'S
  CONTENT CLASSIFIER, WHICH SEES THE FRAME AND NEVER THE NARRATION THAT MAKES
  THE PICTURE BENIGN. AND IT WAS RIGHT ABOUT WHAT IT SAW.** Story 28 scene 17,
  2026-09-12. fal returned HTTP 422 `content_policy_violation`, reason
  `partner_validation_failed` — ByteDance's checker rather than fal's own — and
  no still was generated.

  The frame:

  > Amy on the bedroom floor, back against the wardrobe, knees drawn up, face
  > swollen and wet, Nathan standing over her, unmoving.

  The narration it was cut from is a CONFESSION: she sat down on the floor and
  told him all of it herself, before anyone else could, and then cried for two
  hours until her face was swollen. **Nothing about the words is wrong, and the
  checker is not wrong either.** Stripped of the narration — which is the only
  form the checker ever receives — a woman on the floor with a swollen face and
  a motionless man standing over her is the composition of an assault aftermath.
  The picture carried a meaning the text did not, and the text was not there to
  correct it.

  **This is the shared-string finding with the arrow reversed, and the reversal
  is the whole entry.** That finding says a writer cannot enumerate the readers
  of `image_prompt` from where it stands, and names four — `ThumbnailFraming`,
  `ValidateSceneDrafts`, `ScenesGate::styleBlock()`,
  `ImagePromptBuilder::frameFrom()`. All four are ours, all four are greppable,
  and the remedy offered was to name the readers in the change. **This reader is
  not in the repository.** It is a vendor's classifier, it arrived with no
  release note, it reads the string by a rule nobody here can inspect, and it is
  the only reader that can REFUSE rather than merely misread. A roster assembled
  by grepping this codebase was complete and still missed it, so "name the
  readers" is necessary and is not sufficient: the question has to include who
  reads this string OUTSIDE this application.

  **What the corpus establishes, and what it cannot.** 1,857 scenes carry a
  prompt and 1,707 had already generated, which makes them a control group: a
  word that appears in a bought still cannot by itself be the trigger.
  `swollen` appears ONCE in 1,857 scenes — here, twice inside the one prompt.
  Every other marker in the class has passed: `standing over` 5 of 6, strike
  verbs 6 of 6, raised hands and fists 4 of 4, throats 3 of 3, `on the floor`
  19 of 20, crying 14 of 16, child or baby 15 of 15. **There is no genre word to
  ban.** This genre writes violence, children and blood constantly and they
  generate.

  What the corpus CANNOT do is isolate the cause, and that is reported rather
  than guessed. Scenes combining a person DOWN with crying: zero generated
  successfully, because there are none — the refused frame is the only one. It
  is therefore unique on two axes at once, the word and the configuration, so
  nothing in the data separates them and only fal could. What the passing set
  does show is the shape: every bought `on the floor` scene has the person
  seated or kneeling of their own accord, or is about an object, and story 28's
  own scene 18 — *"both seated on the floor near dawn"* — passed. The
  distinguishing feature of the refused one is ONE PERSON DOWN AND ANOTHER
  STANDING OVER THEM.

  **Repaired as composition, not as vocabulary.** The frame now has her seated
  mid-sentence with her hands open and him sitting on the tile facing her,
  leaning in, listening; the expression keeps the crying, because that is the
  beat the scene exists for, and drops `swollen`. The picture now carries what
  the narration carries. Renaming one word on the same composition would have
  been treating the detector as the defect — and the composition was the defect,
  which is why the checker firing was a correct reading of a real problem.

  **No guard was built, and the base rate is why.** One scene in 1,857 is
  ~0.05%, about one per seven or eight stories, measured off a single event. A
  denylist would have to refuse words that have passed hundreds of times. What
  is free and was done instead: every token in the rewrite was counted against
  the 1,707-scene control group before saving, and three with no precedent —
  `crouched`, `arm's`, `heel` — were replaced with constructions that had
  already generated, `kneeling on the tile` being live in story 27. The repaired
  frame was accepted on the first attempt, for $0.0350.

  ---------------------------------------------------------------------------
  **THE PRACTICE: WHEN REPAIRING A KNOWN FAILURE, PREFER CONSTRUCTIONS THE
  CORPUS HAS ALREADY BOUGHT.**
  ---------------------------------------------------------------------------

  **Unseen is not risky; it is untested** — and those are different enough that
  the distinction only earns its keep in one specific place. Novel wording is
  fine everywhere in this pipeline, constantly, and 1,857 scenes are full of it.
  What makes a repair different is that the subject is already KNOWN to have
  tripped something, so a retry carries two unknowns at once: whether the thing
  being fixed was fixed, and whether the new phrasing introduces a second
  problem. Reusing wording with a purchase history removes the second one for
  nothing, and leaves a failed retry meaning exactly one thing.

  It costs a query against assets already paid for, which is the cheapest
  evidence in this project and the only kind that comes from outside our own
  reasoning — the same argument as rule 3, pointed at prompt text. Generalised:
  **on a retry of anything expensive, change one thing and make every OTHER
  thing something with a record of working.** It is not a rule about images or
  about classifiers; it is what makes the second attempt a measurement rather
  than a second guess.

- **`nonprintable-scan` REPORTED "0 finding(s)" ON A RUN MADE MINUTES AFTER 148
  LINES OF GENERATED PROSE WERE APPENDED TO `CLAUDE.md`, AND IT HAD NOT LOOKED
  AT `CLAUDE.md`.** Its default paths were `app, config, database, routes,
  tests, tools`; this file lives at the repository root and `docs/` was not on
  the list either. The extension filter had accepted `md` all along — nothing
  ever pointed the tool at the two `md` targets that matter.

  **The zero was honest and was still read as broader than it was.** The tool
  prints its coverage one line above the count — *"392 file(s) scanned across:
  app, config, database, routes, tests, tools"* — so the information needed to
  discount the zero was on screen, in the same output, and the zero is what
  registered. That is worth separating from a tool that lies: this one told the
  truth and the truth was in the wrong position relative to the verdict.

  **It is the probe rule, turned on an instrument instead of a measurement.** A
  probe that reports an absence must first be shown able to report a presence —
  and the subject here was the file being edited, which the instrument could not
  see at all. The sharper version, because this tool exists specifically to
  police GENERATED SOURCE: prose assembled in long generated blocks is the
  authoring route that produced all four known instances of the byte, so
  `CLAUDE.md` was not an incidental gap in the coverage, it was one of the most
  exposed surfaces in the repository and the least watched.

  Closed both ways. `docs` and `CLAUDE.md` are in the default sweep, which now
  reports 394 files and names both; and a path that is a FILE is now a legal
  target, since a directory list can never reach a file at the root. Drilled
  four ways rather than assumed: the single-file branch pointed at the known
  offender (1 finding) and at the clean half beside it (0), and a 0x08 planted
  in a scratch `docs/*.md` file, detected and then removed with the sweep
  returning to zero. The last of those is the one that matters, because it
  proves the tool can report a presence in a MARKDOWN file — which is the exact
  claim its zero on this file now rests on.

  **What was NOT done: making the verdict line restate the coverage.** It was
  the obvious second fix and it is the weaker one — it would have made the
  report harder to misread while leaving the file genuinely unscanned. Widening
  the subject beats annotating the blind spot.

- **ACT 1 IS THE ONLY PLACE THREE OPENING INSTRUCTIONS MEET, AND NOTHING HAD
  EVER ASKED WHETHER THEY CAN ALL HOLD AT ONCE. A CONTRACT THAT WAS BUILT AND
  MEASURED WAS OVERRIDDEN BY A PROMPT EDIT MADE SOMEWHERE ELSE.** Found by
  reading story 30's act 1 rather than by any check; the suite, four audits
  and every test belonging to all three instructions were green throughout.

  Story 30's act 1 did not use `stories.hook`, and two of the hook's five
  beats are missing. Beat 4 — ONE SMALL, COLD ACTION, whose own sentence says
  "not a confrontation, not a speech, not a threat" — came back as an
  answer-back. Beat 5's closing promise of the departure was not written at
  all. And the spoken chapter number went missing from the whole act. Story
  29, same premise, opened act 1 on the stored hook verbatim.

  **Three instructions reach into those first thirty seconds, and each is
  correct, current, and measured against a real video:**

  | # | where it lives | what it says | where it came from |
  |---|---|---|---|
  | 1 | `hookInstruction()` | five beats; beat 4 is a COLD ACTION and never a confrontation, because the confrontation is the final act and spending it here spends the video | the five-beat reading of stories 12 and 21 |
  | 2 | `endingFor(Escalation)` | the narrator answers back in EVERY SCENE the antagonist is in | the transcript: a narrator who says "Yes, Mother" loses the audience in three minutes |
  | 3 | `chapterInstruction()` | every chapter opens by SPEAKING ITS NUMBER | the transcript: 62 seconds of cold open, then "chapter 1" |

  **What makes act 1 different is not that it has more rules. It is that the
  antagonist SPEAKS INSIDE THE HOOK.** Beat 3 is her justification, quoted —
  that is the beat's whole content. So the hook contains a scene the
  antagonist is in, rule 2's "every scene" reaches inside rule 1's beats by
  its own plain words, and the two then disagree about what the narrator does
  there: rule 1 says a cold action and explicitly not a line, rule 2 says a
  line. **They are not ambiguous together. They are contradictory together,
  and neither knew the other existed.** Rule 2 arrived after the beats in the
  prompt and won.

  Act 4 is the same shape one rung down and it lost the same way: it carries
  the departure's own opening instruction alongside the chapter announcement,
  and it is the other act of six that announced nothing. **Two acts of six
  had a second instruction about how they open, and those are exactly the two
  that dropped one.**

  **WHY NO TEST COULD SEE IT, WHICH IS THE PART THAT GENERALISES.** All three
  instructions have tests and all three were green:
  `ChapterUnitTest` asserts the announcement is asked for,
  `ContactThroughTheMiddleTest` asserts the answer-back is asked for,
  `OutlineSpineTest` asserts the beats are asked for. **Every one of them
  asserts that its own instruction is PRESENT. Not one asserts that another
  instruction present in the same prompt leaves it followable.** Three checks
  on three subjects, none on the interaction, and the interaction is where the
  defect lives — the axis question from further up this file, pointed at a
  prompt instead of at a clause or a guard. A prompt is not a list of rules; it
  is one instruction assembled from parts, and the parts are only individually
  tested.

  **The fix is a single owner and a named exception on both sides.**
  `hookInstruction()` owns act 1's opening ORDER and opens by naming all three
  rules and saying which wins for as long as the beats last; `endingFor()`
  states the same exception on the answer-back itself, for a writer reading
  top to bottom; `chapterInstruction()` hands act 1 over to the opening block
  rather than restating the rule, so the two cannot drift the way the
  extraction retry note and `CharacterTextGuard` did. Beat 4's wording is
  UNTOUCHED — softening it to admit an answer-back was the other available
  repair and it is the wrong one, because the reason the confrontation cannot
  live in the opening has not changed.

  The stored hook now closes the block as an order ("WRITE IT — expand it into
  the five beats above… Do not replace it with an opening of your own") rather
  than trailing it as "the hook this outline asks for", which reads as a
  reference; and the opening block is the LAST thing in every act prompt,
  where the two blocks that displaced it used to sit. **Ordering is not the
  mechanism** — the words are — but an instruction about the first thirty
  seconds should not be the furthest thing in the prompt from the request that
  follows it.

  `ActOneOpeningContractTest` is the check that did not exist: it asserts all
  three are present (so the collision assertions cannot go vacuous), then
  asserts each PAIR resolves. Six drills, each confirmed red.

  **THE STANDING QUESTION, and it is cheap: when adding an instruction that
  says EVERY or ALWAYS, ask which block already claims that territory.** Rule
  2 said "every scene the antagonist is in" and rule 3 said "every chapter",
  and both were written by someone looking at the middle of a story. The
  opening is a scene and a chapter, and it already had an owner.

- **A STATED FIGURE STEERS WEAKLY; A STATED COUNT STEERS ABSOLUTELY. The same
  prompt held one of each and only one of them was being obeyed.**

  The act word target moves the writer by a fitted +0.30 — a hundred more
  words asked buys about thirty — and that is recorded above as the reason the
  target is advisory. The chapter count sat four lines away in the same
  prompt, phrased as "at this act's length that is 2 chapters", computed from
  that same advisory target. **It was obeyed 6 times out of 6**, at every act
  length story 30 produced, from 1,052 to 1,195 words. The act that ran to
  1,195 words divides honestly into three and came back as two.

  The difference is not importance. **A count is discrete and a writer can
  satisfy it exactly, so it stops being advice and becomes an instruction** —
  and it was an instruction derived from the one number in the prompt known to
  be wrong.

  So the prompt states the DIVISOR and the bounds and asks for the division to
  be done afterwards, against the text that exists. The worked example is
  anchored on `ScriptSizing::naturalActWords()` — what the writer is measured
  to produce — because an example built from the target would be the stated
  count again wearing a different hat.

  **The derivation alone would have changed nothing, and saying so is the
  honest half of this entry.** At the old 150-second chapter budget a
  1,123-word act divides into 2.25 and rounds to two: an honest derivation
  returns exactly the number the stated one did. `chapters.target_seconds`
  moved to 133 in the same change, and **that is a corrected INPUT rather
  than a target moved to match a result** — 150 came from a first reading of
  the reference that said "about 2:29 each", taken before the transcript was
  here; the transcript carries the fourteen boundaries and they mean a mean of
  134 seconds and a median of 133.

  **Section 3d had the right number all along and the config did not.** 3d
  says six acts of the natural length is "about fifteen chapters of ~440
  words, 2:15 each at 197 wpm". 440 words at 197 wpm IS 134 seconds. The
  prose that justified the design and the constant that drove the prompt
  disagreed by 11% from the day both were written, and nothing compares a
  spec sentence against a config value.

- **THE NARRATING-THE-NARRATION BAN WAS OBEYED BY PHRASE AND DODGED BY FORM,
  AND THE MEASUREMENT IS A CLEAN PAIR BECAUSE THE PREMISE WAS HELD CONSTANT.**

  | | "I want to be honest / exact / clear / fair" | "I want you to understand / know / have / hold onto" |
  |---|---|---|
  | four stories, before the ban | 16 | — |
  | story 29 | 4 | 0 |
  | story 30, with those four phrases banned by name | **0** | **5** |

  The ban worked perfectly on its own terms and the forbidden thing did not
  stop — it changed coat, on the next story, on the same premise. That is
  `CharacterTextGuard` matching `weathered` as a literal while the model
  reached for a synonym, one field over, and it is the general property of any
  rule written as a list: **a list of phrases is always one rewrite behind,
  and a ban that is obeyed literally reads as a rule that worked.**

  The prompt names the MOVE now — any sentence whose subject is the telling
  instead of the events, including the listener being told what to understand,
  know, notice, remember or hold on to — and says out loud that there is no
  list. The four old phrasings survive as examples of the form.

  **It has no mechanism and that is stated rather than left to be assumed.**
  This is the request half only. A guard on it would be false-positive-prone
  in exactly the way the hedge ban is — "I want you to have the number" is the
  move and "I wanted her to understand" is a scene — so what decides whether
  the wording is enough is the next measurement, not a green test. Named here
  so the sentence is not read as coverage.

- **A CHAPTER THAT NEVER SAYS ITS NUMBER IS INVISIBLE IN THE DATABASE: THE
  ROWS ARE PRESENT, TITLED, SEQUENCED AND BOUNDARIED.** Story 30 announced
  eight of its twelve chapters. Nothing on the Gate 1 page could say so — it
  lists the chapters an act came back as, and all twelve were there with
  titles.

  `ValidateOutlineSpine::checkChapterAnnouncements()` reads the PROSE: it
  resolves each chapter's boundary through the same `SentenceSplitter` the
  boundary was computed in, and reports three states separately, because they
  are three different repairs — nothing spoken (an instruction that lost a
  collision), the wrong number (the known limit of rewriting one act in the
  middle of a story), and the number spoken late (a marker nobody can
  navigate to).

  A WARNING rather than a problem, and not as a severity judgement: the
  problems panel is headed "The outline is missing part of its structure" and
  holds spine fields and act declarations, while this is a finding about
  returned prose whose nearest sibling — an act with no re-hook — is already a
  structural warning. Two findings of one kind in two panels is how an
  operator learns to read neither.

  Silent with announcements off, and silent on an act with no chapters, which
  is every story written before the table existed — `YoutubeMetadata::
  chapters()` reads acts for those by design, so reporting it would put a
  finding nobody can act on onto twenty stories.

  **The one chapter allowed to announce late is act 1's first**, because the
  cold open precedes "chapter 1" in the reference and in our own contract. A
  check demanding the number as the first sentence everywhere would report the
  correct shape as the defect on every story, which is how a guard gets
  switched off inside a week. It has its own green case.

  **`rehook_line` was quietly broken by the same rule and nobody had asked.**
  The schema asks for "the chapter's opening line, quoted back exactly"; with
  the number spoken first, four of story 30's twelve chapters stored "Chapter
  three." as their re-hook. So `acts.is_rehook_written` — which is
  `$chapters[0]->rehookLine !== ''` — said YES on acts whose recorded opening
  line was a chapter marker, Gate 1's re-hook advisory could not fire on them,
  and `story:write` counted them as written. The ask now says the re-hook is
  the sentence AFTER the number and never the announcement. **That is the
  consumer question paying off in the direction it usually does: a field's
  MEANING changed when a neighbouring rule was turned on, and the field's
  readers were never asked.**

  One owner for the sentence: `App\Support\ChapterAnnouncement` writes it into
  the prompt and reads it back at the gate, so a check looking for "Chapter
  five." while the prompt has started asking for "Chapter 5." is not a state
  this can reach. The fake announces by default, because a fake whose chapters
  never announced would make the new check report every fixture story in the
  suite. Five red/green cases, all drilled.

- **A DRILL WENT RED, PROVED NOTHING, AND I NEARLY WROTE IT DOWN AS A PASS:
  GIT BASH REWROTE THE PATCH TEXT IN TRANSIT.** The backslash entry above says
  an escape consumed between the author and the file produces code that is
  valid, runs, and is wrong in a way no reader can see. This is the same
  mechanism with a different layer, and it landed on the one thing in this
  project whose whole job is to be trusted when it fails.

  The drill replaced a call with `'// drilled out'`. What reached the file was
  `/ drilled out` — a parse error. The suite reported **4 failed, 2 passed**,
  which looks exactly like a guard going red, and two of those four were the
  GREEN halves of the pair, which is the only reason it was questioned at all.

  Measured directly rather than reasoned about:

  ```
  python -c "print(sys.argv[1:])"  '// drilled out'  '/tmp/x'  '//x'  'a//b'
  -> ['/ drilled out', 'C:/Users/.../Temp/x', '/x', 'a//b']
  ```

  MSYS translates an argument that BEGINS with a slash: a leading `//`
  collapses to `/`, and a leading `/tmp` becomes a Windows path. Interior
  slashes are untouched, which is why this has never bitten a file path and
  why it is invisible until the argument is source text. `MSYS2_ARG_CONV_EXCL='*'`
  disables it.

  Two rules, and the first is the one that costs nothing:

  1. **Never pass patch text — or any string that may begin with a slash —
     through a shell argument.** Put it in the script, as a literal. The
     careful re-run that produced the correct answer differed from the broken
     one in exactly that: the `//` sat inside the Python heredoc instead of in
     `argv`. The drill runner is a FILE now, in the scratchpad, with every
     needle and replacement as a literal in it.
  2. **A drill that goes red is a claim about the drill until the failure is
     read.** This file already says to suspect the drill first, and every
     earlier instance was a drill aimed at the wrong thing. This one was aimed
     correctly and was corrupted in transit — so the test is not "did it go
     red" but "did it go red FOR THE REASON IT NAMES", and a parse error and a
     detected defect are indistinguishable in a summary line. The runner
     reports any drill whose output contains a parse error as invalid rather
     than as red.

- **A CONSTANT THAT IS ACTUALLY A FUNCTION OF SOMETHING NOBODY VARIED IS THE
  SAME SHAPE AS A CHECK THAT CANNOT FIRE. `naturalActWords()` WAS ONE FOR A
  PHASE, AND IT IS THE SHARPEST FINDING IN THIS AREA.**

  For a phase this file said, in its own words, that the act writer "produces
  ~1,100 words almost regardless of what the prompt asks for". That is a
  sentence about the MODEL, and it was well earned: five observations, word
  targets varied deliberately from 800 to 1,120, a least-squares slope of
  +0.30 fitted across them, one unconfounded act isolated and named. It is the
  most carefully measured number in this project and it is the basis for the
  act count, the runtime projection on two money screens, and the worked
  example in the chapter prompt.

  **Every one of those five acts came back as one or two chapters, because
  until 2026-09-13 the prompt STATED the chapter count and the writer obeyed
  it absolutely.** The variable was pinned in every observation. Story 31
  unpinned it — the count is derived by the writer from the length it actually
  wrote — and the same premise, the same outline, the same model and the same
  effort returned three chapters per act and **1,473 words**:

  | chapters/act | words/act | measured on |
  |---|---|---|
  | 2 | 1,123 | 2026-09-05, one act, en-US |
  | 3 | 1,473 | 2026-09-13, six acts, story 31, en-CN |

  29%, from a term nobody knew was in the expression. A chapter costs a spoken
  number, a re-hook and a boundary, so the count of them is part of the length
  — and the "natural act length" is not a property of the writer at all.

  **WHY THIS IS THE SAME DEFECT AS A CHECK THAT CANNOT FIRE, AND NOT MERELY AN
  ANALOGY.** Both look settled for identical reasons: nothing has ever moved
  them, so nothing has ever disagreed with them, and **an absence of
  disagreement reads as confirmation**. That is this file's most repeated
  sentence — absence read as agreement — pointed at a MEASUREMENT instead of
  at a guard. Five observations all agreeing is not five pieces of evidence
  about the model when all five hold the same hidden variable fixed; it is one
  piece of evidence, repeated, about one corner of the space. The careful part
  of the original measurement — varying the word target across five stories —
  is what made it look thorough, and the variable it varied was the one that
  barely mattered.

  It is also more dangerous than a dead check, because a constant gets
  ARITHMETIC done to it. A guard that cannot fire is silent. This number was
  multiplied by an act count on the new-story form and at Gate 1, and after
  the chapter change every one of those projections read 25% short while
  looking exactly as authoritative as before.

  **The repair is the CONDITION, not a better number.** Two points do not fit
  a line and none is fitted. `render.script.measured_act_words_chapters`
  records how many chapters the figure was measured under, beside the figure;
  `ScriptSizing::naturalActWordsMeasuredAtChapters()` reads it; and
  `measurementStillHolds()` asserts that the configured chapter budget still
  divides the measured length into that number. So moving
  `chapters.target_seconds` without re-measuring goes RED in
  `ActCountTest` instead of quietly invalidating every runtime on the console.
  Drilled both ways: revert the figure to 1,123, or push the budget to 180
  seconds, and the assertion fires.

  **And a test was carrying the same defect in a worse form.**
  `ActCountTest` hard-coded `$natural = 1123` as a local variable, and
  `RuntimeProjectionTest` asserted `6738` and `6895` as literals. A
  measurement transcribed into an assertion is a measurement that cannot be
  corrected: at five acts the stale figure projects 28.2 minutes, so the test
  written to prove the act count lands in the window would have failed the
  CORRECT act count and been "fixed" by moving the act count back. All three
  read off `ScriptSizing` now.

  **The standing question, which is cheap and general: when a measurement is
  quoted as a property of something, ask what was held fixed in every
  observation.** Not "was it measured carefully" — this one was — but "what
  did the measurement never vary". The answer is usually in the prompt, the
  config or the fixture, and it is usually the thing somebody thought was
  settled.

- **SIX ACTS -> FIVE, AND AT FIVE A CLAMP THAT HAS NEVER DECIDED ANYTHING
  BECOMES THE ONLY THING HOLDING THE ARC TOGETHER.**

  The chapter change made acts 29% longer, so six acts is 44.4 minutes against
  a 30-40 window. Three numbers could have absorbed that and the choice is the
  entry: **`chapters.target_seconds` is now the one figure here derived from a
  measurement of the thing itself** — the reference transcript's fourteen
  boundaries, mean 134 and median 133 — so moving it to fix a runtime would be
  moving a measurement until an outcome passes. The word target steers at
  +0.30 and cannot move a runtime at all. **The act count multiplies a length
  the prompt cannot argue with**, which is the same sentence that moved it
  from seven to six. Five acts of the measured length is 37.0 minutes.

  **What five costs, exactly: one escalation act, three down to two. No phase.**
  `ActPhase::planFor(5)` is escalation 1-2, departure 3, search 4, refusal 5 —
  departure, search and refusal keep one act each, as at six and seven. The
  reversal is now three acts of five.

  **AND THE `$actCount - 2` CLAMP BINDS FOR THE FIRST TIME.** This is the part
  worth the entry. The two-thirds point of five acts is act 4; a departure
  there makes act 5 the refusal and there is **no search act at all** — the
  compressed ending the whole phase structure exists to replace. The clamp
  pulls it back to act 3 and the search survives. At six and seven the
  two-thirds point already lands on `count - 2`, so the clamp changed nothing
  and spent two act-count moves as a guarantee nobody could watch working.
  This file already recorded that it "actually bites at four and five acts";
  what is new is that four and five stopped being hypothetical. **Five is now
  the count that breaks first if `departureActFor()` is ever loosened**, and
  `ActCountTest` asserts the unclamped plan loses the search phase rather than
  leaving that in a docblock.

  Two things named rather than hidden. The reversal is 60% of the acts against
  50% at six and 43% at seven, so `genreGuidance()` no longer says the
  reversal is "roughly the last third of the runtime" — it says THE LAST THREE
  ACTS, which is true by construction at five, six and seven and does not
  drift when the count moves again. And two escalation acts is one rung of the
  "each act costs more than the last" ladder before the departure, the
  thinnest this has ever been: if a story ever reads as leaving too early,
  this is the number that did it.

- **THE SUMMARY BOUND'S LEVER, PULLED — AND THE REASON IT WAS THE RIGHT ONE IS
  A FINDING FROM THIS SAME PASS.** The bound has fired three times in twelve
  acts: story 30's act 3 at 3,121 characters, story 31's act 1 at 3,111 and
  act 4 at 3,233. Each refusal is a billed call that stores nothing — about
  $0.45 of story 31's $1.55, and it gets worse rather than better as acts grow,
  because a summary scales with the act it summarises. It was the most
  expensive untouched lever in the pipeline.

  **The bound did not move, and the ask was not trimmed.** `Act::
  SUMMARY_MAX_CHARS` is derived from the act writer's own distribution and the
  summary is the running context that keeps 7,000 words coherent; this file's
  rule is that trimming it spends coherence to save a form field.

  What changed is the SHAPE, and the diagnosis came from the chapter-count
  finding one field over. The old ask was "3-5 sentences on what happened in
  the act". **That is a stated COUNT, and a stated count steers absolutely —
  so it was obeyed.** The writer returned three to five sentences every single
  time, including at 3,233 characters. What nobody stated is how large a
  sentence may be, and the quantity that overflows is characters. **The
  instruction was counting the one thing that was never the problem.**

  So the summary is now five sentences with a JOB each — what happened, what
  was said with the line quoted, what it cost and to whom, where things stand,
  what the next act must not contradict — plus "FIVE SENTENCES, NOT FIVE
  PARAGRAPHS". Same facts, and a sentence with one thing to do is bounded by
  having one thing to do. Asserted in `ActTextBoundsTest` and drilled; whether
  it works is the next run's refusal count, which is a measurement and not a
  green test.

- **THE GUARD CHECKED WHAT THE MODEL SENT BACK AND NEVER WHAT WE SENT IT. OUR
  PROMPTS WERE TEACHING THE SPELLINGS IT REFUSES.** Found by the 3g build, when
  "apologise" in the new genre text was caught only because the fake outline
  used the same word. Swept 2026-09-17.

  `LocaleGuard` refuses a stage after the call is billed. A prompt that spells
  a word the British way teaches the writer that spelling, and the guard then
  refuses its answer: a paid call lost to text we wrote. The prompt sources
  carried **eleven "colour" and two "grey"**, plus "the year the flat was
  bought" in every act prompt and "a driveway at dusk" as a scene example on
  en-CN. Nearly all of them were in the cast and scene prompts, and cast
  descriptions and scene frames are exactly the outputs the guard refuses. All
  reworded. The deliberate ones stay: the Tito/Lola ban has to name the words,
  and "a flat courtesy" and "flat and sleek" are the adjective.

  **The sweep undercounted, and the guard's own docblock is why.** `hits()`
  said "every match… all of them, not the first", and returns the first
  occurrence of each TERM. That is right for refusing a stage and wrong for
  counting, so the first sweep reported 3 "colour" where the source held 11.
  The docblock now says what the code does, and `occurrences()` counts, on the
  same single pattern.

  **`PromptLocaleTest`, at test time, because every writer prompt is built from
  repo code and repo config and no env var reaches one.** Two halves:

  - **Static, the half that matters.** Every string literal (single, double,
    heredoc, nowdoc; comments excluded) in every app file a prompt writer can
    reach, held to the lists every profile SHARES: British spelling and
    operator idiom. **The file set is DERIVED, not listed**: class references
    from `ClaudeScriptWriter` and `ClaudeMetadataWriter`, followed through
    tokens. A hand list goes stale the way a hand matrix does, and a closure
    built from `use` lines alone missed `TalksToClaude`, which is same-namespace
    and needs no import. 72 files, and the test asserts the closure contains the
    files known to feed a prompt, so a zero is a zero about the right files.
  - **Rendered.** 352 variants: both profiles, both formats, every phase, the
    phaseless branches, optional fields filled and empty, chapter announcements
    on and off. Each is held to ITS OWN profile's lists, because en-US
    guidance correctly says "Thanksgiving" and en-CN denies it.

  Denylist: zero, with ONE exception since 2026-09-19, excised by its exact
  text: the line `LocaleGuard::americanWordsLine()` appends to every profile's
  guidance, naming each denied British term beside its American word ("car
  park → parking lot", all sixteen, from `locale.american_words`, the map the
  denylists are keyed on). The operator's decision: the list knew the wrong
  word and nothing told the writer the right one — three refusals and about
  $0.90 on "car park" while "parking lot" sat in fourteen act scripts. **The
  risk, taken knowingly:** the line quotes the banned words, and the cast
  prompt's quoted "weathered square jaw" came back as "weathered-shaped
  square face" on story 38. A denied term from the list appearing MORE often
  in act prose after this than before is the reading that reverses it. The
  Tagalog and US-institution terms have no one-word American equivalent and
  are not in the map. Otherwise zero: a denied word in a prompt is read only by the
  writer. Warnlist: allowed only by an exception keyed on its PHRASE, so the ban
  stays allowed and a new use of the same word anywhere else fails. The one
  over-report the closure produced ("queue" in `artisan queue:restart`, reached
  through `RunFingerprint`) is an exception with its reason written down. And
  the claim that the locale guidance is the only config PROSE a writer receives
  is itself asserted: every `config('…')` a reachable file reads must be non-prose
  or on a named exemption list, so a prompt sentence moved into config fails
  instead of escaping both halves.

  **Drilled, 11 drills and 14 expectations, every one as intended.** The pair
  that justifies the static half: "favourite" in a new private method no matrix
  renders turns the static test RED and leaves the rendered test GREEN. That is
  the fixture-too-small failure, caught by the half that does not depend on a
  fixture.

  **Not covered, said so it is not read as covered:**
  - Story data: premise, spine, summaries typed at Gate 1. Not in the repo; the
    output guard is still the only check on it.
  - The image generator's config strings (the art style's "hair colour", the
    reference frame's "mid-grey"). Out of scope on purpose: no writer reads
    them, the spelling means nothing to that model, and they are
    env-overridable.
  - British forms the lists do not contain. The prompts still say "ageing"
    (the cast prompt, and the `CharacterTextGuard` rule summary sent on a
    retry), "greying" (two description examples) and "centre part". The check
    enforces the lists, so it cannot see those. Adding them to the denylist
    would also refuse ACT OUTPUT that uses them, which is a guard change, not a
    prompt fix.

    **Leaving these alone was the operator's decision, and it was made on
    incomplete information (noted 2026-09-19 so it reads honestly later).**
    Nobody had checked whether another guard already catches any of them. One
    does, for a different reason: `CharacterTextGuard`'s expression ban lists
    "demeanour" beside "demeanor", so in a cast description that word was always
    a choice between a character refusal and a locale one, not between a
    refusal and nothing. "ageing", "greying" and "centre" are caught by nothing.
    "ageing" looked caught on story 38 because the refusal printed the guard's
    own category label, 'ageing texture "weathered"'; the word refused was
    "weathered". Measured the same day, with the search shown able to find
    "gray" in 49 acts: none of the three is in any act script or publish
    sheet. "centre" is in 8 stored cast descriptions ("centre part", copied
    from the prompt's example) and in 213 image prompts, which only the image
    generator reads. **The coupling to remember:** adding "centre" to the
    locale list would start refusing cast extractions, because the cast prompt
    teaches it. The list and that prompt have to change together.

- **DENIED LOCALE TERMS ARE JUDGED AT GATE 1, NOT REFUSED, FOR THE OUTLINE AND
  THE ACT SCRIPTS.** Built 2026-09-17, after story 36's act 2 was thrown away
  over "car park": a real leak, but the refusal cost the billed act (~$0.20) and
  the run, and a denied term with a reading the list never anticipated costs
  the same ("fourth of july" as a date did, before it moved to the warnlist).

  The old argument, still in `LocaleViolationException`'s docblock beside its
  reversal, was that an operator reading 7,000 words will scroll past one leaked
  idiom. That is an argument about FINDING the term, and Gate 1 now finds it:

  - The outline and act stages call `LocaleGuard::denied()`, keep the text, and
    name the terms on the stage's `render_jobs` row.
  - `GenerateActScripts::localeDenied()` recomputes them from the STORED text:
    title, cast, every spine field, each act's title, summary and beat, and each
    act's script and chapter titles. Recomputed, not recorded, so fixing the
    text clears the alert and nothing can go stale.
  - Gate 1 shows them in a `.alert.err` above the warned terms, the term in the
    refusal's red, each with its act and where it sits: an outline field is
    edited on the page, a script is kept or that act is rewritten
    (`story:write --acts-only=N`). It replaced a refusal, so it may not be
    quieter than one. `story:write` prints them as errors.

  **Scene frames and the cast still refuse**, deliberately: a frame or a
  description is one of 150-250 strings nobody reads as prose, and no page puts
  its phrase in front of anyone, so the old argument still holds there.

  Nothing blocks approval, as nothing at Gate 1 does. Three drills red: the
  throw restored, the panel hidden, scripts dropped from the read (the first
  version of that drill broke PHP syntax and was re-run; a parse error is not a
  red).

  **CORRECTED 2026-09-19: "a script is kept or that act is rewritten" was not
  a real choice. Keeping it guaranteed a paid refusal a stage later.** The two
  bolded rules above were decided the same day and contradict each other:
  scene drafting refuses a denied term in a frame, and the scene writer draws
  its frames from the script. Story 38's act 4 had "car park" in one sentence;
  Gate 1 showed it and said keep it or rewrite; scene drafting was then refused
  twice, about $0.73. The panel now says a denied term in an act script is
  refused at scene drafting and "has to come out before you approve"
  (`GateVoice::removeBeforeApproving()`, settled past Gate 1 as "Until it comes
  out, scene drafting refuses it"). Only an outline-field term is still the
  operator's judgement. Fixed on story 38 by hand, free, on the operator's
  instruction: act 4 sentence 53 "car park" -> "parking lot", sentence count
  unchanged, noted on act-scripts row #13644. See the entry on two decisions
  read together, under "Where bugs actually live".

- **A FAILURE ROW KEPT TELLING THE OPERATOR A RULE THE CODE NO LONGER HAD,
  BECAUSE THE EXCEPTION STORED ADVICE BESIDE THE FACTS.** Story 36's act 2
  failure, on the render progress page after the change above, still read *"The
  stage failed rather than passing this to Gate 1… Re-run the stage"*.
  `RenderJob::fail()` stores `getMessage()` verbatim, and the message held two
  kinds of sentence: what happened (the stage, the locale, "car park" and its
  context), which stays true for as long as the row exists, and how the
  pipeline works, which was true only until the next change to the pipeline.

  **The rule: an error stored on a job row says what happened; how-it-works
  advice is rendered at display time from current code.**
  `LocaleViolationException` now composes facts only; `adviceFor(RenderStage)`
  holds the behaviour, and `RenderProgress::failures()` attaches it beside any
  row `recognises()` as one of these. The class that writes the shape is the
  class that reads it back, since the row holds a string and not a class. A
  test builds the row from the real exception, and two drills went red:
  advice put back into the message, and the page not attaching it.

  **Swept, the same day — see the next entry.** The regex recognition and
  `adviceFor()` described above are gone; the row records a kind.

  **DECLINED BY THE OPERATOR, 2026-09-17, NOT AN OVERSIGHT: marking older rows
  as carrying retired advice.** Rows written before this change still hold the
  old advice in their stored text; the page now shows current behaviour beside
  them, but nothing says the stored sentence is out of date. Doing that needs a
  record of WHEN each behaviour changed, to compare against a row's timestamp,
  and that is a larger thing to build and keep true than a stale failure row is
  worth. Rows also age out of view as stages re-run (`updateOrCreate` overwrites
  them), which is how story 36's own row went. Do not re-open this as a gap.

- **A FAILURE ROW NAMES ITS KIND; THE REPAIR IS BUILT WHEN THE PAGE IS READ,
  AND "No known repair." IS A REPAIR STATE, NOT A GAP.** Built 2026-09-17.
  Every progress-page failure used to be its raw message, cut to 300
  characters, except locale refusals. Two remedies had already cost runs by
  naming things that were not there, and the sweep found more:

  | advice | where it lived | what was wrong |
  |---|---|---|
  | "confirm with `php artisan providers:show`" | WhisperX message, behind 328 failed alignments | no such command |
  | "`python` on PATH is often the Microsoft Store alias stub" | WhisperX, any other silence | the interpreter that WORKS here is the Store build |
  | "Re-run once, then raise ANTHROPIC_MAX_TOKENS_*" | five `truncation_remedy` strings | unmeasured re-roll, plus the ceiling raise this file calls backwards |
  | "apply_text_normalization=on against a flash model" | ElevenLabs 422 | a guess, never observed |
  | "Rate limited. Nothing was billed" | ElevenLabs 429 | never observed |
  | "Rework the premise at Gate 1" | every model decline | true only for the outline |
  | "re-run this act (story:write --acts-only=N)", "re-run it" | act, scene and outline refusals | frozen into rows, and unmeasured for all but one |

  **What is in place.** `render_jobs.failure_kind` and `failure_facts`,
  written by `RenderJob::fail()` from `FailureKind::of()`. An exception that
  knows its kind implements `ClassifiedFailure`; a vendor or database exception
  is read by class and message (cURL 28, a truncated column). Unrecognised is
  `Unclassified`. `FailureRemedy::for()` builds the repair against the code and
  the story's status as they are now. It names a button only when
  `OperatorAction::permittedAt()` allows it, says which status blocks it
  otherwise, and gives the command beside it. `story:write` and `story:scenes`
  print the same remedy. Every classified message was stripped to facts.

  **A kind exists only where the repair is known**: certain from the failure
  itself (a quota body, a missing module, a missing voice, a missing clip), or
  measured here (the summary bound, 3 of 3 retries passed; a timed-out still,
  story 28 scene 131). Everything else is "No known repair.", rendered bold
  and in the ordinary text colour, with no softer sentence. That includes the
  chapter-shape and scene-bound refusals, a narration timeout, the five
  truncation remedies now null, the pace guard, `CharacterTextGuard`, and
  every pipeline invariant.

  **CORRECTED 2026-09-18: THAT RULE COLLAPSED TWO CASES, AND THE OUTLINE
  REFUSALS WERE IN THE WRONG ONE.** "No move exists" and "one certain move
  exists and nobody has measured whether it works" both came out as "No known
  repair.", so story 37's outline, refused for its cast, showed that sentence
  above the one button that repairs it. The second case is now its own shape,
  `Remedy::unmeasuredMove()`: the button, and a separate `unmeasured` sentence
  the page renders as its own "Not measured:" line above the button, so an edit
  to the advice cannot drop the caveat. What made the old re-run advice wrong
  was the claim that it would work, not the naming of the move. The five
  refusals `GenerateOutline` makes after the cost row (act count, act text
  bounds, cast structure, reused name, coded terms) carry
  `FailureKind::OutlineRefused` with the check as a fact; the outline
  `ModelDeclined` remedy moved to the same shape. A re-run only belongs here
  when it is the ONLY move. `RemediesNameRealKnobsTest` pins it both ways at
  every status: never unknown, and never the button without the caveat.

  **CORRECTED AGAIN 2026-09-19: THE FIX WAS APPLIED TO THE OUTLINE AND NOT TO
  THE CLASS, SO IT ARRIVED ONE STAGE PER FAILURE.** The 2026-09-18 correction
  named the rule — a certain move with an unmeasured outcome is not "No known
  repair." — and wired it for the outline only. The next two real failures
  were the same shape one stage along: act-script chapter shapes, then story
  38's cast, refused twice by `CharacterTextGuard` for "often" and
  "weathered", both "No known repair." beside the draft button. The operator
  had asked for the family the first time. **A rule written down for one
  instance is the instance, not the rule** — this file's "fixed at one call
  site" finding, committed on the remedy that was written to end it.

  Now one kind for the family, `FailureKind::OutputRefused` (facts: `stage`,
  `check` from `OUTPUT_CHECKS`, `act`), thrown at every check after a billed
  text call that stores nothing: premises (no candidates), act scripts (chapter
  shape, point-of-view chapter), cast (character text, no declared names),
  scenes (frame or expression bounds, ranges that do not tile), metadata (every
  title over the hard limit, description over the limit). `ModelDeclined` on
  every operation and `LocaleRefused` on the cast and scene stages take the same
  move. `FailureRemedy::rerunStage()` holds ONE table of stage -> button,
  command and what re-running touches, and every entry carries the "Not
  measured:" line. The cast's is the one with a record, and it is said, not
  softened: on the ledger 7 of 24 extraction runs ended refused, and the three
  runs made within minutes of a refusal (story 21 twice, story 32 once) were
  refused again. `FailureRemedy::for()` takes the row's stage, because a locale
  refusal and a decline cannot say where they were thrown.

  **Deliberately still unknown:** a truncation (effort is a second lever, per
  the ceiling position, so a re-run is not the only move), a narration
  timeout (a spending question), an asset stage (no text re-run), and a cast
  refused on a REBUILD, which left the old cast standing — the draft button
  would keep it rather than extract, so the remedy says nothing is left to
  repair. **Seen, not built:** Gate 2 does not show a failed `extract_cast` or
  `draft_scenes` row at all, only `/renders/{slug}` does, though the button
  the remedy names is on Gate 2 — Gate 1's "the retry exists, the failure is
  missing" finding, one gate on. And a scene draft refused at act N discards
  acts 1 to N-1 too, billed, because `persist()` runs after the loop; the
  message used to say "no scene from this act is stored", which was true and
  short, and now says nothing from the draft is. Eleven drills, all red for the
  reason they name. 1,401 tests before, 1,408 after.

  **AND THE SWEEP THAT SHOULD HAVE BEEN PART OF IT FOUND EIGHT MORE, EACH A
  LEDGER GAP AS WELL AS A MISSING REPAIR.** The writers themselves refuse what
  some calls return: a response that is not JSON (all eight decode sites), an
  outline with no acts, an act with no text, a cast of nobody, no scenes, and
  titles, a description opening or tags that come back empty. Every one threw
  a bare exception AFTER the call finished, with the usage still in a local
  variable, so the call was billed by the vendor, never reached `cost_entries`,
  and read "No known repair." — non-negotiable #4 failing in the shape
  `TalksToClaude` already closed for truncations and declines. The writer's own
  comments name the hazard beside the checks moved to the Actions; these stayed
  behind because the Action never sees an empty result. They go through
  `TalksToClaude::refuseOutput()` now, which writes the row (and a discarded
  scene attempt's) and throws the outline's kind or the family's, with the
  operation as a fact so the remedy can find the stage without a row. The
  scene-list precondition before its call bills nothing and is a
  RuntimeException, not a refusal. `FailedCallLeavesATraceTest` asserts no bare
  ScriptWriterException is left in either writer, which is what found the three
  metadata ones. Six drills red, 1,413 tests.

  **THE SCOPED PER-FIELD CAST REPAIR IS HELD, THE OPERATOR'S DECISION,
  2026-09-19.** Time goes to three things never measured against the real
  model: the two endings, "one scene, no numbers", and whether the partner
  reaches act 5. **What holding it costs:** a refused cast is retried whole, so
  characters that were clean on the first attempt are re-sampled and can come
  back worse. On the record that has happened on two stories: story 32 (twice,
  a new violation on a different character each retry) and story 38 (attempt 1
  failed on "expression" for Marcus Pei and Felix Tan; attempt 2 fixed both and
  broke Nicole Pei and Pei Guoliang). **If it happens a third time on a story
  the operator cares about, the decision flips** and the repair is built as
  designed under "The extraction repair loop re-asks the same question". The
  record the decision rests on, stated plainly because the two readings differ:
  on the ledger 7 of 24 extraction runs ended refused, and the re-runs made
  within minutes of a refusal (story 21 twice, story 32 once) were refused
  again; a retry is not yet shown to be the cheap path that works.

  **`RemediesNameRealKnobsTest`, written first.** Every command, flag and env
  var named in any string literal in app/ and config/ (literals joined across
  `.`, comments excluded), in any view (Blade comments excluded), and in every
  remedy `FailureRemedy` can produce for every kind at every status, is checked
  against `Artisan::all()` and the env names config reads. A remedy that says
  to raise a ceiling fails ("do not raise" passes), and the cases with no
  known repair are pinned unknown. It went red on the live tree for exactly
  `providers:show` and the raise-the-ceiling remedies. The detector has
  red/green pairs, including the first false positive it found:
  `<livewire:dashboard />` read as a command. Eleven drills, all red.

  **Two things it does not cover, said so it is not read as covered:** a
  remedy that names a real knob for the wrong reason (only measurement
  catches that, which is why only measured remedies are known), and the
  history problem below. The page can only advise on a failure that is still
  the stage's latest row.

  **The backslash-in-transit hazard happened twice during this build**, in
  Python heredocs used to patch PHP: `\b` arrived as 0x08 in a test regex,
  caught by `nonprintable-scan`, and `\'` arrived as a bare quote, caught by
  `php -l`. Both were redone with exact-match edits.

- **A STICKY HEADING MEASURES FROM THE NEAREST SCROLL CONTAINER, NOT THE PAGE,
  AND WRITING DOWN THE FIRST TRIGGER DID NOT STOP THE SECOND.** Gate 1's cast
  table, story 36: the narrator's row was hidden under the column headings and
  its remove button stuck out above them. Every `th` is `position: sticky; top:
  var(--chrome-h)`. The table sits in `.scrollx`, whose `overflow-x: auto` makes
  the wrapper its own scroll container, so the 48px offset was measured from the
  wrapper and the heading was drawn over row one.

  `base-css` already documented this exact breakage beside `.panel.flush`,
  triggered there by `overflow: hidden`. That note named the PROPERTY ("no
  `overflow: hidden` here"), so the same mechanism came back through a different
  property. **Any overflow other than `visible` creates a scroll container, and
  a sticky element inside one measures from it.** That sentence is the thing to
  remember, not either trigger. Fixed with `.scrollx th { position: static; }`
  (confirmed in headless Chrome), and the `.panel.flush` note now points at it.
  The dashboard's one `.scrollx` table gets the same fix, since it had the same
  bug.

- **A SESSION SUMMARY IS A CLAIM ABOUT THE WORK, NOT A RECORD OF IT, AND IT IS
  THE ONE ARTEFACT HERE THAT NOTHING CHECKS.**

  What happened, 2026-09-18. The summary Claude wrote at the end of the
  failure-remedy work said that work listed "press Write at Gate 1" as the fix
  for outline refusals on act count, bounds, cast structure, reused name and
  coded terms. None of that was built. All five refusals in `GenerateOutline`
  threw with no kind, and the CLAUDE.md entry written in the same work said
  the opposite, in so many words: "every outline refusal" was "No known
  repair." The operator read the summary as a description of what was built,
  and when story 37's outline was refused, handed the list back as though it
  were in the repo. The report that followed started by checking whether the
  wiring was "narrower than the list says"; the list did not exist anywhere.

  **Two records of the same work, written by the same author on the same day,
  disagreed, and the one people read was the one with no check behind it.**
  The code is checked by tests, the stylesheet and views by four audits,
  CLAUDE.md at least sits beside the code it describes and gets read against
  it. The summary lives only in the chat. It is written after the work, from
  the author's memory of what they MEANT to build, in the author's own words
  about the author's own work, and nobody reads it against the diff. It is rule
  3 turned round: the one account of the work that we composed ourselves and
  that nothing outside it can contradict.

  The operator's count: **the fifth time in this project a reported thing did
  not match the built thing, and the first time the operator carried it
  forward.** That second half is the new part. Every earlier instance was
  caught by whoever read it next; this one travelled from Claude's summary,
  through the operator, and back to Claude as a premise, and it read as fact
  every step of the way because it was specific.

  **The practice, which costs a grep:** a summary's claim that something was
  built names the file and the line it lives at, and anything that cannot be
  pointed at is written as a plan. When a summary is read back, as a premise
  or as a status, the claim is checked against the code before anything is
  built on it, exactly as an absence in a probe has to be shown able to report
  a presence.

  **Where the evidence for this finding lives, and where it does not.** Story
  37's failure row, `render_jobs` #12916 (`failure_kind = unclassified`, the
  "0 narrators" refusal), will be overwritten by the successful re-run:
  `RenderJob::record()` reopens a stage's row with `updateOrCreate`, the gap
  already listed under "Still open". So the job history will not show that
  this happened. What survives is the archived raw response,
  `storage/app/private/responses/37/12916-generate_outline-1aec8a87.json.gz`
  (eight cast rows, no narrator), and the cost row: `generate_outline`, 19,654
  total tokens, $0.2273.

  **CORRECTED 2026-09-19: THE ARCHIVE DID NOT SURVIVE, AND THE SENTENCE ABOVE
  WAS FALSE WITHIN A DAY OF BEING WRITTEN.** Story 37 was rendered, and
  `PurgeRenderScratch` calls `ResponseArchive::purge()` on a successful
  render, which deletes every archived response for the story — the refused
  outline included. `responses/37/` is empty. The only record of what that
  call returned is the reading of it in this file (eight cast rows, every role
  but the narrator, every relationship written "my ...") and the conversation
  that made it. **The cost row is the one piece of evidence that survives a
  render**: it is the only record here that no stage of our own pipeline
  deletes.

  That is this entry's own finding, committed while writing it. A claim about
  what survives is a claim about the FUTURE, and it was checked against the
  disk as it stood that hour rather than against what the pipeline does to
  that disk next. The purge is correct and stays: a story that rendered is
  the case its docblock says can spare its payloads. But a failure somebody
  has written up as a finding is exactly the payload worth keeping, and
  nothing marks one as such.

- **A CHECK THAT FIRES ON GOOD OUTPUT IS WORSE THAN ONE THAT MISSES: IT SENDS
  THE OPERATOR TO FIX SOMETHING THAT WAS NEVER WRONG. Two of the three findings
  on the first premise roll were the checks.** Story 38, 2026-09-19. The
  operator read the candidates' warnings, saw three findings repeated across
  three candidates, and concluded — reasonably, from the page — that the
  prompt caused all three, and asked for the prompt to be changed three ways.
  Reading the stored rows beside the checks split them:

  | finding on the page | what the stored output held | what the check did |
  |---|---|---|
  | the accomplice's act "has no line in it" | a quoted line in every field and every premise, in single quotes | **blind**: `quotesALine()` knew only double quotes. Fired on good output three times |
  | the justification "is not said in the betrayal scene" | said verbatim, aloud, to his face, in the room, in every PREMISE; every FIELD pointed at it ("delivers the justification") | **right about the wrong subject**: it read the field, and the outline is written from the prose. The field is thrown away when a premise is picked |
  | the prose "never names" two cast members | exactly that | correct |

  Both wrong checks had a real residue under them — the accomplice speaks to
  the ROOM, and the field was a pointer — and the prompt changes made in 3i
  are aimed at those residues, not at what the warnings said. That the
  residue happened to be there is luck of the same kind as the three rescues
  recorded above: had the lines been said to the narrator in single quotes,
  the warning would have fired identically and the right repair would have
  been none.

  **Why it is worse than a miss, which this file has said about tools and not
  yet about an operator.** A miss leaves a defect in the output. A false fire
  puts a defect in the PROMPT: somebody reads it, believes it, and edits a
  thing that works to satisfy an instrument that is wrong. Repetition made it
  more convincing, not less — three candidates carrying the same warning reads
  as a cause, and a blind detector produces exactly that pattern, because it
  is blind the same way every time. **A finding that repeats across
  candidates is evidence about the prompt OR about the check, and only the
  stored output says which.**

  The practice, and it is the probe rule turned on a warning: **before a
  warning becomes a prompt change, read the text it fired on.** Here that was
  one query and one archived file. The prompt changes would have landed either
  way; what reading bought was knowing which three, and fixing the detector
  instead of teaching the writer to satisfy it.

  **Three sense-blind markers are now on record in the betrayal and departure
  checks** — "announces" (story 33, the antagonist announcing), "her phone"
  (story 36, holding it out for a photo, never noticed) and "booked" (story 38,
  the restaurant booked for the anniversary) — plus `pencil` in
  `CharacterTextGuard`. Measured on the discovery list: 2 fires in 8 real
  `betrayal_scene` texts, 0 true.

  **BUILT, 2026-09-19, on the operator's word: two kinds of word, not a
  longer list.** The discovery list mixed them. VERBS are the finding by
  themselves (`found out`, `discovered`, `overheard`, `copied me`) and fire
  alone, as before — `DISCOVERY_MARKERS`. NOUNS are evidence (`booking`,
  `booked`, `email`, `messages`, `photos`, `posted`, `receipt`, `her phone`) and
  are only a discovery when somebody comes upon them, so they fire only with a
  `DISCOVERY_FINDERS` word — found, read, saw, noticed, copied, forwarded — in
  the SAME SENTENCE: `DISCOVERY_EVIDENCE`, through
  `ValidateOutlineSpine::discoveryHits()`. No exception phrase per false fire.
  Both real false fires are GREEN cases in `GuardsGoRedTest`, word for word,
  and story 38's roll no longer reports "found". Four drills, each red for the
  reason it names: the finder requirement removed (both verbatim cases red),
  the evidence pass dropped, the verbs dropped, and the finder looked for across
  the whole field instead of the sentence — story 36's last sentence says "has
  found his level", so a field-wide window puts the false fire straight back.

  **THE LIMIT, PLAINLY: THIS CHECK HAS NEVER CAUGHT A TRUE CASE.** No real
  `betrayal_scene` has ever described a found betrayal — the field was added
  after stories 23 and 25, the two whose betrayals were found — so every
  positive it keeps is one written by hand, in a fixture. Its recall on real
  output is unmeasured. **A quiet discovery check is not evidence that the
  premises or outlines are clean**; it is evidence that none of these words
  appeared in these shapes. Read the scene.

  **And one wrong case it keeps, pinned as a test so it is not mistaken for
  coverage:** "She reads the booking aloud to the table" is the GOOD case, the
  betrayal done in public, and has a noun and a finder in one sentence, so it
  is called found. The check cannot tell WHO comes upon the evidence or whether
  anybody is watching. If that pinned test ever goes green, this paragraph is
  out of date.

  **"announces" is left alone, deliberately.** It is the same class by
  SUBJECT — whose announcement it is — which is sentence parsing, for one
  false fire (story 33).

- **TWO DECISIONS, EACH REASONABLE ALONE, BROKEN AS A PAIR — AND NOTHING IN THIS
  PROJECT READS TWO DECISIONS TOGETHER.** Story 38, 2026-09-19. On 2026-09-17
  two locale rules were settled in one entry, a paragraph apart:

  | decision | its reasoning, sound on its own |
  |---|---|
  | a denied term in act prose is KEPT and shown at Gate 1 for judgement | refusing threw away a billed act over a word the list may have misread |
  | a denied term in a scene frame is REFUSED | a frame is one of 150-250 strings nobody reads, so nobody would catch it |

  Each is right about its own stage. Together they are wrong, because one
  stage feeds the other: the scene writer draws its frames from the act
  script. A term "kept" at Gate 1 is not kept at all; it is a refusal deferred
  to the next stage, after its calls are billed. Gate 1 offered a choice that
  did not exist ("if this one does, leave it") and story 38 paid for two
  refused scene drafts, about $0.73, for "car park" in one sentence of act 4.
  The phrase had been on the Gate 1 page, in red, the whole time.

  **Why nothing caught it.** Every check here is about one decision: a guard
  tests its rule, a remedy is checked for real knobs, the voice is checked for
  claims at each status. Nothing asks whether what one stage lets through, the
  next stage refuses. It is the axis question one level up. 3a-3j record
  decisions one at a time, and a decision is read when its own entry is read,
  never beside the entry about the stage that consumes its output. This is the
  act-1 finding again ("three instructions, each tested for presence, none for
  whether they can all hold at once") moved from a prompt to the pipeline: act
  1 held three instructions that contradicted each other; this is two stages
  whose rules did.

  **Fixed as the finding, not the phrase.** The Gate 1 panel says what is
  true: a denied term in an act script is refused at scene drafting and has to
  come out before approval; only an outline-field term is a judgement. And the
  writer is now told the right word for each denied British term
  (`LocaleGuard::americanWordsLine()`), which is the same pair read from the
  other side: the list knew what was wrong and nothing said what was right.

  **The standing question, cheap and not a tool:** when a stage is told to
  KEEP something (a warning, a kept term, a soft rule), name the stage that
  consumes its output and ask what that stage does with it. When a stage is
  told to REFUSE something, name the stages upstream that are allowed to
  produce it. If the two answers disagree, one of the decisions is only
  deferring the other's cost. **One more pair already on the record, of the same
  shape and latent:** the cast prompt teaches "centre part" and the cast stage
  refuses denied terms, so adding "centre" to the list would make the prompt
  produce what the stage refuses (recorded beside the British-spelling
  decision). No full sweep of stage pairs has been done.

Still open, none blocking, all findable here rather than one gate at a time:

- **The job history cannot answer questions about past failures, so any claim
  starting "this has never happened" is unsupported by it.** `RenderJob::record()`
  reopens a stage's row with `updateOrCreate`, and a later success overwrites
  the failure. Story 28's two outline truncations were lost from its stage list
  this way, and on 2026-09-17 it meant nobody could say whether one of our own
  prompts had ever cost a paid call: `render_jobs` showed one locale refusal,
  `failed_jobs` two, and neither is a complete record. Until failures are kept,
  an absence in `render_jobs` is not evidence of an absence.

- **The one-voice-per-narrator-gender rule: A IS BUILT (2026-09-19), B IS
  NOT.** Costed 2026-09-14; A went in with the premise generator, because the
  generator needs the narrator's gender and one form input answers both. See
  Voice under Target audience. B stays unbuilt, per the recommendation below.

  *A — a narrator field on the new-story form.* `providers.narrator_voices`
  (`male` => Brian, `female` => Sarah) beside `default_voice_id`;
  `CreateStory` takes a narrator key and resolves the voice at insert,
  refusing an unknown key; a required radio on `NewStory` with a rule in
  `saveRules`-style walked by the form test, and the existing narrator panel
  keyed off the selection; `story:write --premise` gains a REQUIRED
  `--narrator` (a default there is the per-story pick in disguise). No new
  column: `voice_id` is the record of the choice, which is the stored-decision
  rule. Consumers already right: `story:fork` copies `voice_id`,
  `PreflightAssetDispatch` checks the id is on the account, `voices:list --set`
  stays the override. About 150 lines, eight tests, one blade change (so the
  class and theme audits matter), $0. **What it does not catch:** the wrong
  radio pressed. It removes the default trap, which is the failure that
  actually happened.

  *B — the check that catches the failure itself.* At asset dispatch, compare
  the narrator character's gender with the voice's. Needs a narrator marker on
  the cast (`characters.is_narrator`, from the extraction schema — a cached
  schema change), the vendor's gender label as data (it arrives today only
  inside a display string, so `SpeechSynthesizer::voices()` widens and every
  fake with it), and a WARNING in the preflight — a deliberate cross-gender
  narration is legal. About 250 lines, a provider contract change, an
  extraction prompt change. Upstream of the spend and catches a wrong pick as
  well as a forgotten one.

  Recommendation: A before the next woman's story; B only if a mismatch
  reaches dispatch after A exists.

- **THE GATE 2 EDITOR CANNOT CHANGE WHO IS IN A PICTURE, IN EITHER DIRECTION,
  AND THE EDITOR IS THE ONE PLACE ON THE PAGE THAT DOES NOT SHOW IT. Sized
  2026-09-14, not built.** Who is drawn is decided at draft and lives in two
  places: the cast block inside the stored prompt, and `scene_character`.
  `ImagePromptBuilder::rewrite()` keeps the first byte for byte and the save
  never touches the second. So:

  - **Adding** a name to a frame reaches the generator with no description
    and no reference sheet — an invented face. Found on story 33 #72, which
    was reframed as an empty room for exactly this reason.
  - **Removing** a name is the sharper half and the likelier edit: the
    character's description stays in the prompt and `ResolveSceneReferences`
    still attaches their sheet from the pivot, so a frame edited to take
    somebody out is conditioned on their face anyway.

  The ROW shows the cast as "+ Name" chips in frame view; the inline editor,
  where the frame is actually changed, hides them, and its help text says the
  cast block is "added after this on save, exactly as stored" — true, and
  silent about what that means for a name typed in or taken out.

  *Step 1 — say it, ~40 lines, $0.* The cast chips inside the editor with one
  sentence (these descriptions and reference sheets go with this scene
  whatever the frame says), and a save-time notice when the edited frame names
  a cast member the scene does not carry, resolved through
  `ImagePromptBuilder::explain()` so an ambiguous name is reported rather than
  guessed. One layout assertion, one red/green pair.

  *Step 2 — make it editable, ~120 lines, $0, no migration, no provider
  change.* A checkbox list of the story's cast in the editor; the save syncs
  `scene_character` by id and `rewrite()` gains the cast section — found by its
  label, rebuilt from the frozen `characters.description`, with the style and
  constraints sections still taken from the stored prompt and never from
  config. Refuse more characters than `ImageGenerator::maxReferences()` at save
  rather than at dispatch. Every other reader follows the pivot and is right by
  construction: `ResolveSceneReferences`, `ThumbnailFraming`, the sheet
  estimate's scene counts, the cast check in `ValidateSceneDrafts`. About eight
  tests, led by the tail staying byte-identical with the cast swapped.

  None of story 33's ten edits hit either half: #25 names Sophie, who was
  already its cast; #72 names nobody and carries nobody.

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
- **`CharacterTextGuard`'s refusal does not say which findings are a word to
  remove and which are a sentence to decide.** Each line names its category,
  but only texture, posture and expression carry a repair ("put age in
  hairline...", "belongs to the frame"); a conditional, a carried verb or a
  handheld object gets none, and the closing paragraph is one block for all.
  "often a white blazer" is not a word to delete: whether she wears it in every
  frame is a decision, and today nobody but the next re-roll makes it, because a
  refused cast stores nothing and cast text is read-only in the console. **The
  operator's call, 2026-09-19: a small change worth making when something else
  takes you into that file, and not on its own.**
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
  lesson. **Thirteen self-defeating checks so far, and not one was found on
  purpose. Six of the thirteen were found only because something ADJACENT was
  being changed** — which is the part that should be uncomfortable, because
  there is no reason to think the adjacent change was the last one.

  **Twelve of the thirteen are defects in code somebody here wrote. The
  thirteenth is not** — see the rows below the table.

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

  | 11 | `test_the_narrating_ban_names_the_move`, asserting a phrase the prompt really does contain | the guidance is a wrapped heredoc and the needle straddled a line break. Number 6 again, in a test written by somebody who had read number 6 that morning |
  | 12 | the in-person encounter probe, reporting a 12.6-minute stretch with no contact in story 31 | it required the antagonist's NAME, and the scene inside that stretch is written entirely as "she". **It reported an absence across a scene that is nothing but presence** |
  | 13 | a guard drill that went RED and proved nothing | the patch text never reached the file. Git Bash rewrote `'// drilled out'` to `'/ drilled out'` in transit; the suite reported "4 failed, 2 passed", which is what a working guard looks like |

  **NUMBER 13 IS THE FIRST ONE THAT IS NOT OUR CODE'S FAULT, AND THAT MAKES IT
  THE HARDEST OF THE THIRTEEN.** Every other row is a defect somebody here
  wrote: a wrong regex, a fixture too small, a needle that straddles a line, a
  belief about a compiler that was never checked. All of them are fixable by
  being more careful in the file you are looking at.

  This one is not in any file. The drill was aimed correctly, the needle
  matched once, the replacement was right, and the tool reported the guard
  going red. What happened is that **MSYS translates an argument that BEGINS
  with a slash** — a leading `//` collapses to `/`, a leading `/tmp` becomes a
  Windows path — so the patch reached the file as a syntax error and the
  "failure" was a ParseError. Measured, not inferred:

  ```
  python -c "print(sys.argv[1:])"  '// drilled out'  '/tmp/x'  '//x'  'a//b'
  -> ['/ drilled out', 'C:/Users/.../Temp/x', '/x', 'a//b']
  ```

  **No amount of care inside the test would have caught it**, which is what
  separates it from the twelve above. The test file was never wrong. The
  drill's INTENT was never wrong. The transport between them changed the text,
  and a `Tests: 4 failed` line cannot distinguish a guard catching a defect
  from a parser rejecting a broken file. The only reason it was questioned is
  that two of the four failures were the GREEN halves of the pair — a guard
  that reports the good case too is reporting something other than the thing
  it names.

  Three things follow, and the first is the cheap one:

  1. **Never pass patch text, or any string that may begin with a slash,
     through a shell argument.** Put it in a script file as a literal. The
     drill runner is a file now for exactly this reason, and so is every
     multi-line patch in this session.
  2. **A drill that goes red is a claim about the drill until the failure is
     READ.** "Suspect the drill first" was already the rule here, and every
     earlier instance was a drill aimed at the wrong thing; this is the first
     aimed correctly and corrupted in flight. The runner now reports any drill
     whose output contains a parse error as INVALID rather than as red.
  3. **A red/green PAIR is what made it visible.** A drill with only a red
     half would have passed inspection. The pair exists because a rule that
     reports everything satisfies RED — and here the thing reporting
     everything was the PHP parser.

  This is the same family as the backslash-consumed-in-transit entry further
  up: a layer between the author and the file changes the text, and what lands
  is valid, runs, and is wrong in a way no reader can see. That entry is about
  source; this one is about the instrument that checks source, which is a
  worse place for it.

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
  **AND ITS TWIN, WHICH IS THE SAME RULE POINTED AT A MEASUREMENT: A PROBE THAT
  REPORTS AN ABSENCE MUST FIRST BE SHOWN TO REPORT A PRESENCE.**
  ---------------------------------------------------------------------------

  These two belong side by side rather than one inside the other. Everything
  above is about CHECKS — a guard, an assertion, a tool — and every lesson in it
  applies unchanged to the ad-hoc probe somebody writes to answer a question
  during an investigation. That probe is never committed, never reviewed, and
  its output is handed over as evidence, which makes it the least scrutinised
  instrument in the project and the one most likely to decide something.

  The instance: a probe read `start` and `end` from WhisperX word timings. The
  keys are `start_ms` and `end_ms`. Every lookup returned null, the probe ran
  clean, printed NULL in a neat column, and the null was written up as "the
  aligner assigned no time range, so nothing was spoken" — a conclusion with
  the sign inverted, offered as a finding. **A missing FIELD read as a missing
  VALUE, and a missing value read as zero**, and zero happened to fit the
  comfortable hypothesis.

  The check was free and was sitting in the same table: point the probe at
  something that is definitely there. Real em dashes in the same story would
  have printed NULL too — impossible for text that exists — and the defect
  would have been visible in one run.

  **Both rules are the same shape and both were skipped in the same week.** A
  guard nobody drills and a probe nobody calibrates are both instruments whose
  silence is indistinguishable from a pass, and both produced a confident wrong
  answer that went into a report as fact.

  **The corollary, learned when the SECOND reading of that same measurement was
  also wrong:** calibrating the probe is necessary and not sufficient. The
  corrected probe read the right keys, used a well-chosen control and produced
  real numbers — and the conclusion drawn from them ("the spans are 20x the
  control, so something was spoken") was still false, because a forced aligner
  borrows silence for tokens it cannot match. **A measurement can be accurate,
  controlled and still be answering a different question than the one asked.**
  When the answer matters and a direct observation is cheap, take the direct
  observation: nine seconds of listening settled what two rounds of alignment
  arithmetic could not.

  **The second corollary: a measurement is only as good as the thing that
  produced it, and a docblock is not that thing.** The prompt locale sweep
  (2026-09-17) counted with `LocaleGuard::hits()`, whose docblock said "every
  match… all of them, not the first". It returned the first occurrence of each
  WORD. The sweep reported 3 "colour" where the source held 11, and the operator
  sized the rewording decision on that number, wrong by nearly 4x. The control
  passed, because the control used each term once. Nothing was wrong with the
  calibration; the counter underneath was not what its description said. When a
  number decides something, read the code that produced it, not the sentence
  above the code, and pick a control that could expose the difference: a term
  used twice, not once.

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

- **A 422 AND A TIMEOUT ARE STRUCTURALLY IDENTICAL, AND ONE RETRY BUTTON
  RE-DISPATCHES BOTH. One is free and succeeds; the other cannot succeed
  however many times it is pressed.** Story 28, 2026-09-12: scene 131 a cURL 28
  timeout, scene 17 a `content_policy_violation`. Both landed as
  `render_jobs.status = failed`, `stage = images`, on the same page, under the
  same heading. Scene 131 retried clean on the first attempt for $0.0350; scene
  17 would have refused again, and would refuse every time, because the checker
  is deterministic on the same prompt.

  `FalSeedreamImageGenerator::run()` throws one `RuntimeException` for every
  failed response whatever the status code, so there is no column, no enum case
  and no exception subclass separating "the network dropped" from "this prompt
  will never be accepted". Gate 2 prints the stage label `Images` and the first
  300 characters of the error for both — which does carry the difference, the
  words "flagged by a content checker" survive the truncation — and the button
  above them reads `Retry failed scenes` and re-dispatches the whole outstanding
  set.

  **The premise to correct, because it runs the other way.** A 422 writes NO
  cost row. Story 28 holds 201 `generate_image` rows and $7.0350 against 201
  successes; the two failures are absent, because the throw in `run()` precedes
  pricing. So pressing retry on a refusal is not a second charge in this ledger
  — it is a cycle that cannot succeed. Whether fal bills for a rejected 422 is
  a question only their usage page can answer, which is rule 3: our ledger is
  our own bookkeeping, and an internal zero is not an external zero.

  **What telling them apart would take**, and it is small:

  - A typed exception. The HTTP status is in hand at the throw site and ends up
    only in a string. `ContentRefusedException` beside the generic one, thrown
    when the status is 422 and the body names `content_policy_violation`, is the
    whole mechanism.
  - A third scene state. `SceneStatus::Failed` means "try again"; a refusal
    means "this prompt needs editing before anything is dispatched". Without the
    distinction `SceneChangeSet` keeps the refused scene in the retry set for
    ever, which is exactly what makes the button wrong.
  - A button that says which. `Retry failed scenes (1 of 2 — scene 17 needs its
    frame edited first)` rather than one verb for two states.

  Not built, and the frequency is the argument: one refusal in 1,857 scenes,
  about one per seven or eight stories. What is NOT an argument for waiting is
  the cost of pressing it, which is zero dollars and a cycle — the reason to
  build it is that the button currently promises something it cannot deliver,
  and the operator has to read a 300-character provider error to find that out.

- **A BATCH ROW THAT CAN NEVER FINISH — SECOND INSTANCE, HARMLESS TWICE, AND
  THE SPEC LINE IS NOW HALF-TRUE IN CODE.** Story 28's asset batch stands at
  `total 406, pending 2, failed 2, finished_at NULL` and will stand there for
  ever, beside story 23's `total 514, failed 258, finished_at NULL` recorded
  further up this file.

  The mechanism, read off the framework rather than inferred:
  `DatabaseBatchRepository::incrementFailedJobs()` writes
  `'pending_jobs' => $batch->pending_jobs` — unchanged. A failed job increments
  the failure count and never decrements the pending count, so a batch holding
  any permanent failure never reaches `pending_jobs = 0`, never sets
  `finished_at`, and its completion callback never fires.

  **Harmless both times, for a reason nobody chose.** Story 28's reconcile hangs
  off the TIMINGS batch, which had no failures and did finish, so
  `DispatchAssetGeneration::reconcile()` ran, flagged the two scenes and parked
  the story at `assets_generating` exactly as designed. The asset batch's row is
  therefore cosmetic — and it means the batch progress display for stories 23
  and 28 reads 404/406 and 256/514 permanently. That is luck about which batch
  carries the callback, not a property of the design. Move the reconcile onto
  the asset batch and it stops running at all.

  **The spec line is the part worth correcting.** "Batch failure policy: if 3
  scenes out of 200 fail image generation, the batch should complete and flag
  them for retry, not fail the whole video." The flagging works. **The batch
  does not complete, and cannot.** The intent is delivered by a reconcile on a
  different batch from the one the sentence describes, so a sentence that reads
  as a description of the mechanism is only a description of the outcome.

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
