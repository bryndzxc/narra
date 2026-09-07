<?php

namespace App\Services\Claude;

use Anthropic\Client;
use App\Contracts\ScriptWriter;
use App\Enums\ActPhase;
use App\Enums\MotionPreset;
use App\Enums\StoryFormat;
use App\Models\Act;
use App\Models\Story;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use App\Support\Providers\ActOutline;
use App\Support\Providers\ActScriptDraft;
use App\Support\Providers\CharacterCast;
use App\Support\Providers\CharacterProfile;
use App\Support\Providers\OutlineDraft;
use App\Support\Providers\SceneDraft;
use App\Support\Providers\SceneDraftSet;
use App\Support\Providers\ScriptWriterException;
use App\Support\ScriptSizing;
use RuntimeException;

/**
 * The script writer, on Claude.
 *
 * Chunked, and sequential where it has to be. A 30-40 minute video is
 * 5,500-8,000 words of narration and no single call holds that coherently, so
 * the shape is premise -> outline -> per-act, each act given the full outline
 * plus a running summary of the acts already written. `actScript()` is called
 * in order by GenerateActScripts and cannot be fanned out.
 *
 * Three things this class owns that the callers must not:
 *
 *  1. **Its own rate card.** Usage comes back on every response and is priced
 *     here, from config. A call site that priced a response itself would drift
 *     the moment a rate changed.
 *  2. **The locale instruction.** Injected into every call from the story's
 *     locale_profile, as data rather than prose baked into a prompt string.
 *     The denylist check that backs it up runs in the Action, after the call —
 *     a provider that validated its own output would have to decide what to do
 *     about a failure, and that decision is the pipeline's.
 *  3. **Structured output.** The outline comes back as JSON against a schema,
 *     not as prose to be parsed. Parsing "Act 1: ..." out of a paragraph is the
 *     kind of thing that works for a month.
 *
 * Streaming on every call. An act on a thinking model runs long enough to
 * outrun a non-streaming HTTP timeout, and on this platform there is no
 * `queue:work --timeout` to catch it — pcntl does not exist in Windows PHP.
 */
class ClaudeScriptWriter implements ScriptWriter
{
    use TalksToClaude;

    public function __construct(
        private readonly Client $client,
        private readonly LocaleGuard $locale,
        // Held so the retry prompt can render the guard's OWN rules rather
        // than a hand-written copy of them. The copy is what failed: the
        // guard banned `weathered` and the copy did not name it, so a
        // rejected extraction was corrected with a note that never
        // mentioned the word it was rejected for.
        private readonly CharacterTextGuard $text,
    ) {}

    public function outline(Story $story, int $actCount): OutlineDraft
    {
        if ($actCount < 3) {
            throw new RuntimeException(
                "An outline of {$actCount} acts cannot work: acts become YouTube chapters, and YouTube "
                .'ignores a chapter list shorter than three.'
            );
        }

        [$content, $usage] = $this->call(
            system: $this->outlineSystemPrompt($story),
            userMessage: $this->outlinePrompt($story, $actCount),
            operation: 'generate_outline',
            schema: $this->outlineSchema(),
        );

        $decoded = $this->decodeJson($content, 'outline');

        // The phase plan is OURS, not the model's. It is not in the schema and
        // it is not asked for: the prompt states which slot is which phase and
        // the model writes to that, while the column that the act generator
        // later branches on is assigned here from the same arithmetic the
        // prompt was built from. A phase the model returned could disagree with
        // the slot it was given, and there would be no way to tell which of the
        // two the summary was actually written for.
        $plan = $story->format === StoryFormat::Anthology
            ? []
            : ActPhase::planFor($actCount);

        $acts = [];

        foreach (($decoded['acts'] ?? []) as $index => $act) {
            $acts[] = new ActOutline(
                sequence: $index + 1,
                title: trim((string) ($act['title'] ?? '')),
                summary: trim((string) ($act['summary'] ?? '')),
                escalationBeat: trim((string) ($act['escalation_beat'] ?? '')),
                phase: $plan[$index + 1] ?? null,
            );
        }

        if ($acts === []) {
            throw new ScriptWriterException('The outline call returned no acts.');
        }

        // The act count is NOT checked here, deliberately. Structured outputs
        // reject any minItems other than 0 or 1, so an exact length cannot be
        // a schema constraint and has to be verified against the response —
        // but verifying it inside the provider means throwing after the call
        // has already been billed and before its usage has been handed back,
        // which loses the cost row for a call that cost money. GenerateOutline
        // checks it, after recording the cost. See requestedActCount below.

        return new OutlineDraft(
            title: trim((string) ($decoded['title'] ?? $story->title)),
            acts: $acts,
            usage: $usage,
            hook: trim((string) ($decoded['hook'] ?? '')),
            narratorGrievance: trim((string) ($decoded['narrator_grievance'] ?? '')),
            antagonistJustification: trim((string) ($decoded['antagonist_justification'] ?? '')),
            withheldInformation: trim((string) ($decoded['withheld_information'] ?? '')),
            exposureMoment: trim((string) ($decoded['exposure_moment'] ?? '')),
            departure: trim((string) ($decoded['departure'] ?? '')),
            reversalBeats: trim((string) ($decoded['reversal_beats'] ?? '')),
            refusal: trim((string) ($decoded['refusal'] ?? '')),
            requestedActCount: $actCount,
        );
    }

    public function actScript(
        Story $story,
        ActOutline $act,
        array $fullOutline,
        array $priorSummaries,
        int $targetWords,
    ): ActScriptDraft {
        [$content, $usage] = $this->call(
            system: $this->actSystemPrompt($story),
            userMessage: $this->actPrompt($story, $act, $fullOutline, $priorSummaries, $targetWords),
            operation: 'generate_act_script',
            schema: $this->actSchema(),
        );

        $decoded = $this->decodeJson($content, "act {$act->sequence}");

        $script = trim((string) ($decoded['script'] ?? ''));

        if ($script === '') {
            throw new ScriptWriterException("Act {$act->sequence} came back with an empty script.");
        }

        return new ActScriptDraft(
            sequence: $act->sequence,
            script: $script,
            summary: trim((string) ($decoded['summary'] ?? '')),
            rehookLine: trim((string) ($decoded['rehook_line'] ?? '')),
            usage: $usage,
        );
    }

    public function characters(Story $story, array $scripts, array $rejectionNotes = []): CharacterCast
    {
        [$content, $usage] = $this->call(
            system: $this->characterSystemPrompt($story),
            userMessage: $this->characterPrompt($story, $scripts, $rejectionNotes),
            operation: 'extract_characters',
            schema: $this->characterSchema(),
        );

        $decoded = $this->decodeJson($content, 'character extraction');

        $profiles = [];

        foreach (($decoded['characters'] ?? []) as $entry) {
            $name = trim((string) ($entry['name'] ?? ''));
            $description = trim((string) ($entry['description'] ?? ''));

            if ($name === '' || $description === '') {
                continue;
            }

            $profiles[] = new CharacterProfile(
                name: $name,
                description: $description,
                styleNotes: trim((string) ($entry['style_notes'] ?? '')),
                importance: trim((string) ($entry['importance'] ?? 'supporting')),
            );
        }

        if ($profiles === []) {
            throw new ScriptWriterException(
                'Character extraction returned nobody. Every scene prompt is built from this cast, so '
                .'drafting scenes without it would mean 150-250 stills each inventing their own '
                .'description of the same people.'
            );
        }

        return new CharacterCast($profiles, $usage);
    }

    public function scenes(
        Story $story,
        Act $act,
        array $sentences,
        array $cast,
        int $targetScenes,
    ): SceneDraftSet {
        if ($sentences === []) {
            throw new ScriptWriterException("Act {$act->sequence} has no script to split into scenes.");
        }

        $system = $this->sceneSystemPrompt($story, $cast);
        $prompt = $this->scenePrompt($story, $act, $sentences, $cast, $targetScenes);

        [$content, $usage] = $this->call(
            system: $system,
            userMessage: $prompt,
            operation: 'draft_scenes',
            schema: $this->sceneSchema(),
        );

        $scenes = $this->decodeScenes($content, $act);

        // The quality gate, and it is deliberately a structural one rather than
        // a judgement.
        //
        // "Fall back if quality drops" needs a signal a machine can read, and
        // for this call there is an exact one: the scenes must tile the act's
        // sentences from 1 to N with no gap and no overlap. A gap drops
        // narration out of the finished video; an overlap says a line twice.
        // Both are invisible in the output and both are arithmetic here.
        //
        // DraftScenes asserts the same property and throws. That is not
        // duplication — this retries, that refuses. A cheap model that miscounts
        // is worth one more call; a story that still miscounts after the
        // fallback must not reach Gate 2.
        $discarded = [];
        $fallback = (string) (config('providers.anthropic.operations.draft_scenes.fallback') ?? '');

        $reason = $this->unusableReason($scenes, $sentences);

        if ($reason !== null && $fallback !== '') {
            // The first attempt was billed. It is kept and handed back so it
            // writes its own cost row: a fallback that quietly swallowed the
            // wasted call would report a saving it did not make.
            $discarded[] = $usage;

            [$content, $usage] = $this->call(
                system: $system,
                userMessage: $prompt,
                operation: 'draft_scenes',
                schema: $this->sceneSchema(),
                override: [
                    'model' => $fallback,
                    'effort' => config('providers.anthropic.operations.draft_scenes.fallback_effort'),
                ],
            );

            $scenes = $this->decodeScenes($content, $act);
        }

        if ($scenes === []) {
            throw new ScriptWriterException("Scene drafting for act {$act->sequence} returned no scenes.");
        }

        return new SceneDraftSet($scenes, $usage, $discarded, $reason);
    }

    /**
     * @return array<int, SceneDraft>
     */
    private function decodeScenes(string $content, Act $act): array
    {
        $decoded = $this->decodeJson($content, "scenes for act {$act->sequence}");

        $scenes = [];

        foreach (($decoded['scenes'] ?? []) as $entry) {
            $first = (int) ($entry['first_sentence'] ?? 0);
            $last = (int) ($entry['last_sentence'] ?? 0);
            $frame = trim((string) ($entry['frame'] ?? ''));

            if ($first < 1 || $last < $first || $frame === '') {
                continue;
            }

            $scenes[] = new SceneDraft(
                firstSentence: $first,
                lastSentence: $last,
                frame: $frame,
                charactersPresent: array_values(array_filter(array_map(
                    fn ($name): string => trim((string) $name),
                    (array) ($entry['characters_present'] ?? [])
                ))),
                motionPreset: trim((string) ($entry['motion_preset'] ?? '')) ?: null,
                isThumbnailCandidate: (bool) ($entry['thumbnail_candidate'] ?? false),
                expression: trim((string) ($entry['expression'] ?? '')),
            );
        }

        return $scenes;
    }

    /**
     * Whether this draft is good enough to keep, or worth paying again for.
     *
     * Two checks, and the second one exists because the first one was not
     * enough. Both are structural — there is no model judging another model
     * here, only arithmetic on what came back.
     *
     *  1. **The ranges tile.** Scenes must cover sentences 1..N once each, in
     *     order. A gap drops narration out of the finished video; an overlap
     *     says a line twice. Both are invisible downstream.
     *
     *  2. **The scenes are watchable lengths.** Measured on a real story: the
     *     expensive model left 1% of scenes under the minimum, the cheap one
     *     left 12%. A four-word scene is ~3 seconds on screen, the Ken Burns
     *     move never completes, and the cut reads as a flicker.
     *
     * Check 1 alone shipped first and never fired once across six acts —
     * because counting sentences is the part a cheap model gets RIGHT. The
     * failure was in how it chopped them up. A gate that only tests the strong
     * axis is a gate that always opens.
     *
     * @param  array<int, SceneDraft>  $scenes
     * @param  array<int, string>  $sentences
     */
    private function unusableReason(array $scenes, array $sentences): ?string
    {
        if (! $this->rangesTile($scenes, count($sentences))) {
            return sprintf(
                'sentence ranges did not tile the act (%d scenes over %d sentences)',
                count($scenes),
                count($sentences),
            );
        }

        $minWords = (int) config('scenes.min_words', 8);
        $threshold = (float) config('scenes.fallback_short_share', 0.05);

        $short = 0;

        foreach ($scenes as $scene) {
            $words = 0;

            // Counted from the sentences the range points at, because a
            // SceneDraft carries a range rather than its narration — the text
            // is joined later, by the Action.
            for ($i = $scene->firstSentence; $i <= $scene->lastSentence; $i++) {
                $words += str_word_count((string) ($sentences[$i - 1] ?? ''));
            }

            $short += $words < $minWords ? 1 : 0;
        }

        $shortShare = $short / max(count($scenes), 1);

        if ($shortShare > $threshold) {
            return sprintf(
                '%.1f%% of scenes under %d words, ceiling %.0f%% (%d of %d)',
                100 * $shortShare,
                $minWords,
                100 * $threshold,
                $short,
                count($scenes),
            );
        }

        return $this->motionReason($scenes);
    }

    /**
     * Why the camera is too monotonous across this act, or null if it is fine.
     *
     * Returns a MEASUREMENT, not a verdict. A gate that fires on seven acts
     * out of seven and records only "fell back" costs the same diagnosis
     * every time: story 21 spent $0.16 on discarded attempts and the log
     * could not say which of the three axes had failed, so it had to be
     * inferred by measuring the finished draft against all three.
     *
     * The third axis, added for the same reason as the second: the gate only
     * tested what it already knew to test. Length was checked, motion was not,
     * and a re-draft came back with 30% of the video holding still — a share
     * the expensive model never went near (11%) — while passing every existing
     * check.
     *
     * Two ceilings, because the presets are not equivalent. `static` is the
     * absence of a camera move rather than one of the moves, so a run of it
     * reads as a broken slideshow far sooner than a run of push-ins does; it
     * gets 15% against 55% for everything else.
     *
     * Judged per act, which is what a per-act call can see. A story can still
     * drift across acts without any single act failing — that is what the Gate
     * 2 warning is for, and it reads the same two numbers.
     *
     * @param  array<int, SceneDraft>  $scenes
     */
    private function motionReason(array $scenes): ?string
    {
        $total = count($scenes);

        // Too small a sample to call monotonous. Three scenes sharing a preset
        // is a coincidence, not a pattern, and failing on it would re-bill an
        // act for nothing.
        if ($total < 8) {
            return null;
        }

        $counts = [];

        foreach ($scenes as $scene) {
            $preset = $scene->motionPreset ?? '';

            if ($preset !== '') {
                $counts[$preset] = ($counts[$preset] ?? 0) + 1;
            }
        }

        $static = $counts[MotionPreset::Static->value] ?? 0;

        $staticCeiling = (float) config('scenes.static_share_threshold', 0.15);

        if ($static / $total > $staticCeiling) {
            return sprintf(
                '%.1f%% static, ceiling %.0f%% (%d of %d scenes hold still)',
                100 * $static / $total,
                100 * $staticCeiling,
                $static,
                $total,
            );
        }

        $monotony = (float) config('scenes.motion_monotony_threshold', 0.55);

        foreach ($counts as $preset => $count) {
            if ($preset !== MotionPreset::Static->value && $count / $total > $monotony) {
                return sprintf(
                    '%.1f%% of scenes are "%s", ceiling %.0f%% (%d of %d)',
                    100 * $count / $total,
                    $preset,
                    100 * $monotony,
                    $count,
                    $total,
                );
            }
        }

        return null;
    }

    /**
     * Whether these scenes cover sentences 1..N exactly once, in order.
     *
     * @param  array<int, SceneDraft>  $scenes
     */
    private function rangesTile(array $scenes, int $sentenceCount): bool
    {
        if ($scenes === [] || $sentenceCount < 1) {
            return false;
        }

        $expected = 1;

        foreach ($scenes as $scene) {
            if ($scene->firstSentence !== $expected || $scene->lastSentence < $scene->firstSentence) {
                return false;
            }

            $expected = $scene->lastSentence + 1;
        }

        return $expected === $sentenceCount + 1;
    }

    // -- Prompts -------------------------------------------------------------

    /*
     * The genre is an aggrieved-narrator melodrama, and it has a structure.
     *
     * The first version of these prompts asked for "long-form narrated stories"
     * and got competent literary fiction back: well-observed, well-written, and
     * no reason to keep watching. That is a genre failure, not a quality one,
     * and no amount of asking for "more engaging" prose fixes it — the missing
     * thing is structural.
     *
     * Six rules, and they are load-bearing rather than stylistic:
     *
     *  1. FIRST PERSON, AND WRONGED. The narrator is the injured party, not an
     *     observer of someone else's injury. A narrator watching a wrong happen
     *     to a third party is the literary reflex and it kills the format.
     *
     *  2. THE ANTAGONIST IS SELF-JUSTIFIED. The infuriating part is the EXCUSE,
     *     not the villainy. An antagonist who knows they are being cruel is a
     *     cartoon and the viewer disengages; one who genuinely believes they
     *     were owed it is what holds thirty-five minutes.
     *
     *  3. ESCALATION, NEVER RESOLUTION. Each act makes it worse. An act that
     *     resolves anything has spent the tension the rest of the video runs on.
     *
     *  4. INFORMATION ASYMMETRY. The narrator knows something the antagonist
     *     does not. This is what makes escalating humiliation watchable rather
     *     than merely unpleasant — the viewer is waiting for a specific thing.
     *
     *  5. EXPOSURE, NOT REVENGE, AND IN FRONT OF WITNESSES. The payoff is the
     *     truth landing publicly. Witnesses are load-bearing; the same reveal in
     *     private is a different and much worse video.
     *
     *  6. THE TITLE STATES THE ENDING. This niche does not withhold. The title
     *     is the hook precisely because it promises the payoff.
     */

    /** The genre contract, injected into every call for a story. */
    private function genreGuidance(Story $story): string
    {
        return <<<'TEXT'
        GENRE: first-person aggrieved-narrator melodrama. This is a specific
        format with a specific structure, not "a story told in first person".

        THE NARRATOR
        - First person, past tense. "I", never "she".
        - The narrator is the person who was wronged. Not a witness to someone
          else's wrong, not a bystander who pieces something together, not a
          relative watching a family fall apart. It happened to them.
        - They are reasonable, restrained, and specific. They do not rant. The
          restraint is what makes the audience furious on their behalf.
        - They are not a saint and not a victim in their own telling. They tried
          to be fair. That is what makes the antagonist's excuse land.

        THE ANTAGONIST
        - Their behavior is self-justified. They have a reason, they say it out
          loud, and they believe it. "You don't need it as much as we do." "You
          were always the strong one." "Family helps family."
        - The infuriating part is the EXCUSE, not the cruelty. Never write an
          antagonist who knows they are the villain, enjoys it, or announces it.
          A cartoon switches the viewer off in ninety seconds.
        - They are supported by other people who find the excuse reasonable.
          Isolation of the narrator is the mechanism.

        THE SHAPE — FIVE MOVEMENTS, IN THIS ORDER
        This is the part most often got wrong, and getting it wrong produces a
        video that is competent and that nobody finishes. The arc is NOT
        escalation -> exposure -> end.

        1. ESCALATION. Every act costs the narrator more than the last: money,
           standing, a relationship, dignity, in front of more people each
           time. Nothing is resolved. No act ends with the narrator winning a
           round, being vindicated, or getting an apology that sticks. The
           narrator holds information the antagonist does not have, established
           early and not used.

        2. THE DEPARTURE. The narrator goes. Not a threat, not an ultimatum,
           not a final speech — they leave, and THEY DO NOT ANNOUNCE IT. This
           is load-bearing: an announced departure cannot be searched for, and
           the search is the next third of the video. The antagonist finds out
           later, from somebody else, that they are simply gone.

        3. THE SEARCH. The antagonist looks for them, and each attempt costs
           HER more than the last — money, standing, the people who backed her
           excuse. These are the humiliation beats running the other way and
           they escalate the same way. A search that costs her nothing is a
           montage of somebody looking worried.

        4. THE REFUSALS. She finds them. The narrator says no. Each refusal
           answers ONE specific earlier humiliation, in the words it was done
           in. This is the private payoff, and it is the thing the audience has
           been waiting the whole video for.

        5. THE END, within a few sentences of the last refusal landing.

        The reversal — movements 2, 3 and 4 — is a PHASE, and it is roughly the
        last third of the runtime. It is not a scene at the end. A story that
        escalates for thirty minutes and gives the narrator power in the final
        ninety seconds has written the wrong video.

        THE TWO PAYOFFS
        - PUBLIC: exposure, in front of witnesses. The truth comes out at a
          moment the antagonist chose and controlled. The antagonist's own
          excuse is what convicts them — the best version is the antagonist
          repeating their justification in front of people who now know it is
          false.
        - PRIVATE: the refusal. Said to the antagonist, usually with nobody
          else there, and it answers something specific she said or did
          earlier. The public one is what the title promises. The private one
          is what the viewer stayed forty minutes for. A video with only one of
          them is half a video.
        - NEITHER is revenge. The narrator does not sabotage, retaliate, or
          destroy anything. They produce the truth, and then they decline, and
          both are allowed to do their own work.
        - No violence, no crime by the narrator, no supernatural element.

        WHAT THIS IS NOT
        - Not literary fiction. No ambiguity, no unresolved endings, no
          "deciding whether to" closings, no lyrical drift.
        - Not a mystery. The audience is never confused about who is wrong.
        - Not a thriller. Nobody is in physical danger.
        TEXT;
    }

    private function outlineSystemPrompt(Story $story): string
    {
        return implode("\n\n", [
            'You structure long-form narrated stories for a YouTube channel in a single, '
            .'specific genre. Everything below is the format, not a suggestion.',
            $this->genreGuidance($story),
            $this->formatGuidance($story),
            $this->locale->guidanceFor((string) $story->locale_profile),
            $this->lengthGuidance($story),
        ]);
    }

    private function actSystemPrompt(Story $story): string
    {
        return implode("\n\n", [
            <<<'TEXT'
            You write first-person narration for long-form YouTube story videos. The text
            you produce is read aloud by a single narrator over still illustrations. Nobody
            reads it on a page.

            That means:
            - Write for the ear. Short sentences carry; subordinate clauses do not.
            - No headings, no scene labels, no stage directions, no bracketed notes.
            - No "Act One" or "Chapter" markers in the prose itself.
            - Dialogue is quoted plainly. "she said" and nothing fancier.
            - Concrete detail over interiority. Name the amounts, the dates, the rooms,
              the exact words people used. Specifics are what make it feel true.
            - The narrator is telling this to someone, after the fact, in order. They
              already know how it ends and they are not hiding it.
            TEXT,
            $this->genreGuidance($story),
            $this->formatGuidance($story),
            $this->locale->guidanceFor((string) $story->locale_profile),
            $this->lengthGuidance($story),
        ]);
    }

    private function formatGuidance(Story $story): string
    {
        return $story->format === StoryFormat::Anthology
            ? <<<'TEXT'
                FORMAT: ANTHOLOGY. Each act is a self-contained story with its own narrator.

                Be aware this fights the genre: escalating humiliation compounds across a
                single continuous narrative and cannot compound across five separate ones,
                so each act has to build and pay off its own escalation in a fifth of the
                runtime. Prefer the single-narrative format for this genre unless the
                operator has explicitly chosen otherwise.
                TEXT
            : <<<'TEXT'
                FORMAT: SINGLE NARRATIVE. One narrator, one grievance, one antagonist,
                across every act.

                This is the right shape for this genre. The humiliation compounds from act
                to act — the same people, the same relationship, each time worse — and the
                information the narrator is holding stays unused until the final act. Act
                titles double as YouTube chapter titles, so they mark stages of the
                escalation without giving away the exposure.
                TEXT;
    }

    private function lengthGuidance(Story $story): string
    {
        // The SIZING rate, so the word range this asks the model for is the
        // same one `targetWordsPerAct()` will hold the result to. Read from the
        // raw constant, these two agreed only for as long as nothing corrected
        // one of them.
        $wpm = ScriptSizing::wpmFor($story);

        return sprintf(
            'TARGET RUNTIME: %d-%d minutes of narration, roughly %s-%s words in total at the '
            .'pace this is read. This is a watch-time format: the length is the product. Do '
            .'not rush to the exposure, and do not stretch a thin situation to reach a count. '
            .'The escalation is what fills the time.',
            $story->target_duration_min,
            $story->target_duration_max,
            number_format($story->target_duration_min * $wpm),
            number_format($story->target_duration_max * $wpm),
        );
    }

    private function outlinePrompt(Story $story, int $actCount): string
    {
        // The act count is enumerated rather than stated. "Produce exactly 5
        // acts" was asked twice and came back with 4 both times: a bare count is
        // an instruction a model can satisfy approximately, whereas a numbered
        // list of slots is a shape it has to fill. The exact count matters
        // because each act becomes one YouTube chapter, and it cannot be a
        // schema constraint - structured outputs reject any minItems other
        // than 0 or 1.
        // Each slot names its PHASE, not just its number. A bare numbered list
        // produced escalation all the way down and a reversal crushed into the
        // last act, which is the exact shape story 21 shipped as. The phases
        // are assigned here rather than asked for, from the same arithmetic
        // `acts.phase` is written from, so the plan the model writes to and the
        // plan the act generator later reads cannot disagree.
        $plan = $story->format === StoryFormat::Anthology
            ? []
            : ActPhase::planFor($actCount);

        // The hook's betrayal deadline, in words rather than seconds, because
        // that is the unit the writer is working in. It goes through the same
        // frozen rate as the act word target, from the one place that owns the
        // conversion: two rates in one prompt is the $2.12 / $4.24 / 42,017
        // shape reproduced inside a single string. See ScriptSizing.
        $hookWords = ScriptSizing::hookBetrayalWords($story);

        $slots = implode("\n", array_map(
            fn (int $n): string => isset($plan[$n])
                ? sprintf('  %d. <act %d> — %s. %s', $n, $n, strtoupper($plan[$n]->value), $plan[$n]->guidance())
                : "  {$n}. <act {$n}>",
            range(1, $actCount)
        ));

        return sprintf(
            "Build the outline for this story.\n\nPREMISE:\n%s\n\n"
            .'First establish the spine. Everything else is built on it, so be specific — '
            ."names, amounts, dates, relationships. Vague answers here produce a vague video.\n\n"
            ."- narrator_grievance: who wronged the narrator and how, in the narrator's own "
            .'first-person words. Two or three sentences. Name the relationship and the '
            ."specific thing that was taken.\n"
            ."- antagonist_justification: the antagonist's own account of why they were "
            .'entitled to do it, in THEIR words. It has to be something a real person would '
            ."say and believe. If it reads as an admission of wrongdoing, it is wrong.\n"
            .'- withheld_information: the specific thing the narrator knows and the '
            .'antagonist does not. It must already be true at the start of the story, and '
            ."the narrator must have a plausible reason not to say it.\n"
            .'- exposure_moment: where the truth comes out, and WHO IS IN THE ROOM. Name the '
            ."occasion and the witnesses. This is the PUBLIC payoff.\n"
            .'- departure: how and when the narrator leaves, and what finally makes staying '
            .'impossible. THEY DO NOT ANNOUNCE IT and they make no farewell speech — they go, and '
            .'the antagonist finds out later, from somebody else, that they are gone. An announced '
            ."departure cannot be searched for, and the search is the next third of the video.\n"
            .'- reversal_beats: what the antagonist does to find them, as at least two escalating '
            .'attempts, and what each one COSTS HER. Money, then standing, then the people who '
            .'backed her excuse. These are the humiliation beats running the other way, and a '
            ."search that costs her nothing is a montage of somebody looking worried.\n"
            .'- refusal: what the narrator says when they are finally found, and WHICH EARLIER '
            .'MOMENT EACH REFUSAL ANSWERS. Name that moment. The strongest version hands back the '
            ."antagonist's own sentence from act 2 or 3, in her words, from the other side of it. "
            ."This is the PRIVATE payoff and it is what viewers stay forty minutes for.\n"
            .'- hook: the first thirty seconds of the video, as five beats in this order. This is '
            .'the highest-leverage text in the whole script, and it is the one place where writing '
            ."the chronological beginning loses the viewer. DO NOT START AT THE BEGINNING:\n"
            .'    1. ONE sentence of setup. One. Not a second one, and never a sentence about the '
            .'video itself, no "I want to start there", no "to understand this you need to know". '
            ."A second sentence of setup is the beat this most often loses.\n"
            .sprintf(
                '    2. The betrayal itself, stated within %s words. Not its aftermath and not a '
                .'summary of how it turned out: the thing that was done, being done, in the room '
                ."it happened in. That is %d seconds of narration at the rate this script is "
                ."being written to.\n",
                number_format($hookWords),
                (int) round(ScriptSizing::hookBetrayalSeconds()),
            )
            .'    3. Evidence in EXACT WORDS. A line of dialogue, a message or a document, quoted '
            .'rather than described. Draw on the antagonist_justification you wrote above: the '
            .'hook is where it lands first, as the bait. THE ACT KEEPS IT. This genre plays the '
            .'same line twice, once here in a single sentence and again in act 2 or 3 at length, '
            .'in the room it was said in, and the second landing is stronger for the first. Do '
            ."not spend it here, and do not paraphrase it in either place.\n"
            .'    4. ONE small, cold action by the narrator. Not a confrontation, not a speech and '
            .'not a threat: something quiet and exact. A spreadsheet opened and named, a bag '
            .'packed, a flat courtesy said to somebody expecting a fight. The confrontation is '
            ."the final act, and spending it here spends the video.\n"
            .'    5. A closing line promising the DEPARTURE. Not revenge, not the exposure and not '
            .'a reckoning: that the narrator is going to be GONE, and that somebody is going to '
            .'have to look for them. Use the specific language of the departure you wrote above, '
            .'because that is the promise this video actually pays off, and Gate 1 checks this '
            .'line against it. A hook promising revenge on a story whose payoff is a refusal is '
            ."selling a different video.\n\n"
            .'Then fill in every one of these %d slots. Return exactly %d act objects, in '
            ."this order:\n\n%s\n\n"
            .'Do not merge slots, do not leave one out, and do not add another. Each slot '
            .'becomes one YouTube chapter, so the count is fixed before any of it is written. '
            .'The phase on each slot is fixed too: an act written in the wrong phase is worse '
            .'than a missing one, because the escalation has to stop when the narrator leaves '
            ."and start running against the antagonist instead.\n\n"
            ."For each act give:\n"
            .'- title: works as a YouTube chapter title. 2-6 words. Marks a stage of the '
            ."escalation. Does not give away the exposure. No numbering, no 'Act One'.\n"
            .'- summary: 3-5 sentences. What actually happens, concretely. The act script is '
            ."written from this and nothing else, so anything vague here gets invented later.\n"
            .'- escalation_beat: one sentence naming what this act COSTS, and to whom. In the '
            .'escalation and departure phases that is the narrator, each act costing more than '
            .'the one before it, and nothing resolving — no round won, no apology that sticks. '
            .'In the search and refusal phases it is the ANTAGONIST, escalating the same way. '
            ."The cost changes direction at the departure and never changes back.\n\n"
            .'Finally, the title of the whole video. Under 70 characters. This genre does '
            .'NOT withhold: the title states the ending, because the promise of the payoff '
            .'is the hook. Front-load the grievance, then name what happens. Something in '
            .'the shape of "My Sister Took X - So At Her Y, I Showed Everyone Z".',
            trim((string) $story->premise),
            $actCount,
            $actCount,
            $slots,
        );
    }

    /**
     * @param  array<int, ActOutline>  $fullOutline
     * @param  array<int, string>  $priorSummaries
     */
    private function actPrompt(
        Story $story,
        ActOutline $act,
        array $fullOutline,
        array $priorSummaries,
        int $targetWords,
    ): string {
        $outlineBlock = implode("\n", array_map(
            fn (ActOutline $entry): string => sprintf(
                "%d. [%s] %s\n   %s\n   COSTS: %s%s",
                $entry->sequence,
                // The phase is in the context block, not only on the act being
                // written. An act 6 that cannot see act 5 was the departure
                // has no way to know the narrator is already gone.
                strtoupper($entry->phase?->value ?? 'act'),
                $entry->title,
                $entry->summary,
                $entry->escalationBeat,
                $entry->sequence === $act->sequence ? "\n   <-- WRITE THIS ONE" : ''
            ),
            $fullOutline
        ));

        // The running summary is what makes chunked generation coherent. Act 4
        // without it repeats act 2 and contradicts act 3.
        $priorBlock = $priorSummaries === []
            ? 'This is the first act. Nothing has been written yet.'
            : implode("\n\n", array_map(
                fn (string $summary, int $index): string => sprintf('Act %d: %s', $index + 1, $summary),
                $priorSummaries,
                array_keys($priorSummaries)
            ));

        $isLast = $act->sequence === count($fullOutline);

        $rehook = $act->sequence === 1
            ? $this->hookInstruction($story)
            : sprintf(
                'This act opens at roughly minute %d, where viewers leave. Its first two sentences '
                .'are a re-hook: give someone about to close the tab a reason not to. Open on the '
                .'next indignity already in progress. Do not open by recapping act %d.',
                // The same rate the word target was derived from, necessarily:
                // this converts that target back into minutes, and a different
                // divisor would place the re-hook at a minute the script never
                // reaches.
                (int) round(($act->sequence - 1) * $targetWords / ScriptSizing::wpmFor($story)),
                $act->sequence - 1,
            );

        $ending = $this->endingFor($story, $act, $isLast);

        return implode("\n\n", [
            'THE SPINE OF THIS STORY:',
            sprintf(
                "Grievance: %s\n\nThe antagonist's justification: %s\n\nWhat the narrator knows and "
                ."they do not: %s\n\nWhere it comes out: %s",
                $story->narrator_grievance,
                $story->antagonist_justification,
                $story->withheld_information,
                $story->exposure_moment,
            ),
            'FULL OUTLINE (for context — write only the marked act):',
            $outlineBlock,
            'WHAT HAS ALREADY BEEN WRITTEN:',
            $priorBlock,
            sprintf('NOW WRITE ACT %d: %s', $act->sequence, $act->title),
            $act->summary,
            sprintf('%s: %s', $act->phase?->beatLabel() ?? 'What this act must cost the narrator', $act->escalationBeat),
            $rehook,
            $ending,
            sprintf(
                "Target %s words of narration. Return:\n"
                ."- script: the narration itself, first person, as continuous prose.\n"
                ."- summary: 3-5 sentences on what happened in it, written for the next act's "
                .'writer — names, what was said, what it cost, where things stand. This is the '
                ."only thing the next call will know about this act.\n"
                .'- rehook_line: the opening line you actually used, quoted back.',
                number_format($targetWords),
            ),
        ]);
    }

    /**
     * What act 1 is told about its own first thirty seconds.
     *
     * -----------------------------------------------------------------------
     * THE MEASUREMENT THIS REPLACES
     * -----------------------------------------------------------------------
     *
     * The old instruction was four sentences: open in the middle of the
     * grievance, state what was taken, do not open with scene-setting. It is
     * not wrong and it did not work. Both shipped stories open on context —
     * story 12 on a pot boiled black on a stove in March 2020, story 21 on the
     * square meterage of an apartment — and neither states its betrayal inside
     * forty scenes.
     *
     * **What the measurement actually found is that the beats are all there
     * and all late.** Story 21 has the best cold action in the database, *I
     * said, "Have a good trip. I'll take you to the airport."*, at 3:09. Story
     * 12 opens a spreadsheet and names it MOM EXPENSES 2020 at 3:24. Neither
     * writer failed at anything; neither was told where the opening starts, and
     * the chronological beginning is what a writer produces by default, because
     * context is what comes first in time.
     *
     * So this does not ask for better writing. It names five beats and the
     * order they go in, and it hands over the hook the outline already wrote,
     * so act 1 is executing a decision made at Gate 1 rather than making one.
     *
     * -----------------------------------------------------------------------
     * COPYING, NOT MOVING
     * -----------------------------------------------------------------------
     *
     * Beat 3 draws on the antagonist's justification and DOES NOT CONSUME IT.
     * In this genre the same line lands twice — once here as one quoted
     * sentence of bait, once in act 2 or 3 played out at length in the room it
     * was said in — and the second landing is stronger for the first. A
     * generator told to "use it in the hook" spends it and leaves the act
     * paraphrasing itself, so the instruction says so in both directions.
     *
     * -----------------------------------------------------------------------
     * THE WORD BUDGET
     * -----------------------------------------------------------------------
     *
     * `ScriptSizing::hookBetrayalWords()` converts the twenty seconds at THIS
     * STORY'S frozen sizing rate — the same rate `$targetWords` came from. A
     * second rate in this prompt would be two beliefs about one narration in
     * one string.
     *
     * A story outlined before `stories.hook` existed has none, and gets the
     * beats without the outline's own answer to them rather than a blank where
     * one should be. Four stories are in that position and it is the same
     * legacy case Gate 1 reports once.
     */
    private function hookInstruction(Story $story): string
    {
        $hook = trim((string) $story->hook);
        $words = ScriptSizing::hookBetrayalWords($story);

        $beats = sprintf(
            'THIS ACT OPENS THE VIDEO, and its opening is the highest-leverage text in the whole '
            ."script. Five beats, in this order, before anything else happens:\n\n"
            ."1. ONE sentence of setup. One. Do not write a second, and never write a sentence "
            ."about the video itself.\n"
            .'2. The betrayal itself, inside the first %s words. Not its aftermath, not a summary '
            .'of how it turned out, not a line about what the family said afterwards — the thing '
            ."that was done, being done, in the room it happened in.\n"
            .'3. Evidence in EXACT WORDS: one line of dialogue, a message or a document, quoted. '
            .'The strongest version is the antagonist\'s own justification, said in her words. '
            .'THE LATER ACT KEEPS IT — this genre plays that line twice, once here as bait in a '
            .'single sentence and again later at length, in the room it was said in, and the '
            ."second landing is stronger for the first. Quote it here; do not exhaust it here.\n"
            .'4. ONE small, cold action by the narrator. Not a confrontation, not a speech, not a '
            .'threat: something quiet and exact that the audience understands and the antagonist '
            ."does not. The confrontation is the final act and spending it here spends the video.\n"
            .'5. A closing line that promises the DEPARTURE — that the narrator will be gone and '
            .'somebody will have to look for them. NOT revenge, NOT the courtroom, NOT the '
            ."exposure. Those are the payoff and this is the promise, and they are not the same.\n\n"
            .'Do not open with weather, a childhood memory, a house, a room, a date, or the '
            .'chronological beginning of events. The beginning in time is almost never the '
            .'beginning of the video.',
            number_format($words),
        );

        return $hook === ''
            ? $beats
            : $beats."\n\nTHE HOOK THIS OUTLINE ASKS FOR — write these beats as this:\n\n".$hook;
    }
    /**
     * What this act has to do with its ending, decided by its PHASE.
     *
     * This branched on "is this the last act" for two phases, and that is the
     * defect the reversal was added to fix. Every non-final act was told to end
     * worse off than it started, which is right for an escalation act and the
     * precise opposite of what a search act needs — there the narrator is
     * already gone and the ground is being lost by the ANTAGONIST. A sequence
     * number cannot express that, so the phase is carried on the act.
     *
     * `$isLast` survives as the fallback for an outline with no phases: an
     * anthology, where each act is a self-contained story running the whole arc
     * itself, and the stories outlined before the reversal existed, whose acts
     * are still re-writable one at a time. Neither should be handed a phase
     * instruction that its outline was never built for.
     */
    private function endingFor(Story $story, ActOutline $act, bool $isLast): string
    {
        return match ($act->phase) {
            ActPhase::Escalation => 'This act is in the ESCALATION phase. The withheld information '
                .'does not come out here and the narrator does not leave here. Nothing is resolved: '
                .'no round is won, no real apology arrives, no ally fixes anything. End the act '
                .'worse off than it started.',

            ActPhase::Departure => "THIS IS THE DEPARTURE ACT. The narrator goes:\n\n"
                .$story->departure."\n\n"
                .'It is still an escalation act until they leave — this is the worst it gets, and it '
                .'is what makes staying impossible. Then they go. THEY DO NOT ANNOUNCE IT: no '
                .'ultimatum, no farewell speech, no note, no final phone call. They are simply not '
                .'there any more, and the antagonist has not worked that out yet when the act ends. '
                .'The withheld information does not come out here. Do not explain where they went — '
                .'the audience may know, the antagonist must not.',

            ActPhase::Search => 'This act is in the SEARCH phase. The narrator is gone, and the '
                ."antagonist is looking for them:\n\n"
                .$story->reversal_beats."\n\n"
                .'The direction of the escalation has reversed. Everything in this act costs the '
                .'ANTAGONIST — money, standing, the people who found her excuse reasonable — and it '
                .'costs her more than the last attempt did. She does not find them in this act. The '
                .'narrator does not gloat, does not send a message, and is not watching: they are '
                .'living, elsewhere, and the little the audience sees of that should be quiet. End '
                .'the act with her worse off than she started it and no closer.',

            ActPhase::Refusal => 'THIS IS THE FINAL ACT. Both payoffs land here, in this '
                ."order.\n\nFIRST, the exposure — the public one:\n\n"
                .$story->exposure_moment."\n\n"
                .'The withheld information comes out here and nowhere earlier. Put the witnesses in '
                .'the room and name them. The antagonist repeats their justification in front of '
                ."people who now know it is false.\n\nTHEN the refusal — the private one, and the "
                ."thing the audience has waited the whole video for:\n\n"
                .$story->refusal."\n\n"
                .'She asks. The narrator says no, and each refusal answers ONE specific earlier '
                .'humiliation in the words it was done in — hand her own sentence back to her. The '
                .'narrator does not retaliate, gloat, or explain the moral. End within a few '
                .'sentences of the last refusal landing: no epilogue about what everyone learned, '
                .'no ambiguity, no "I still think about it sometimes".',

            // No phase: an anthology act, or an outline written before the
            // reversal phase existed. The old shape, unchanged, because that is
            // the shape its outline was built to.
            null => $isLast
                ? "THIS IS THE FINAL ACT. It contains the exposure:\n\n"
                    .$story->exposure_moment."\n\n"
                    .'The withheld information comes out here and nowhere earlier. Put the witnesses '
                    .'in the room and name them. The antagonist repeats their justification in front '
                    .'of people who now know it is false — that is the moment the video exists for. '
                    .'The narrator does not retaliate, gloat, or explain the moral. They state the '
                    .'fact, and the room reacts. End within a few sentences of the reveal landing: no '
                    .'epilogue about what everyone learned, no ambiguity, no "I still think about it '
                    .'sometimes".'
                : 'This is NOT the final act. The withheld information does not come out here. '
                    .'Nothing is resolved: the narrator does not win a round, get a real apology, or '
                    .'find an ally who fixes anything. End the act worse off than it started.',
        };
    }

    // -- Character extraction --------------------------------------------------

    private function characterSystemPrompt(Story $story): string
    {
        // Filtered, because castAgeBlock() returns an empty string for a story
        // that states no age range, and an unfiltered implode would open that
        // section with a blank gap where an instruction used to be.
        return implode("\n\n", array_filter([
            <<<'TEXT'
            You write character sheets for an ANIME-STYLE illustrated video. Each description
            you write will be pasted, WORD FOR WORD AND UNCHANGED, into 150-250 separate
            image generation prompts across a 35-minute video.

            THE ONE RULE THAT DECIDES EVERYTHING ELSE: write SILHOUETTE, not TEXTURE.

            Most of these frames are mid shots and wide shots, and this is an anime style.
            Fine surface detail does not survive either. "Faint smile lines at the corners
            of her eyes" and "a light spray of freckles" are real observations that render
            as nothing at conversational distance, so a character built out of them is a
            character who is identifiable in close-up and anonymous everywhere else. That
            was measured, not guessed: a woman described that way read as mid-forties in a
            close portrait and mid-thirties in a two-person kitchen shot, from the same
            description, in the same run.

            So every character must be identifiable from their HAIR AND HEAD SHAPE before a
            single feature is read.

            DO NOT DESCRIBE BUILD, HEIGHT OR FRAME. Not "broad-shouldered", not "stocky",
            not "small and frail", not "tall". This was measured and it is not a matter of
            taste: the illustration style draws every body toward one idealised frame, so a
            character written "broad and thick through the chest" is drawn lean and one
            written "small and frail with stooped shoulders" is drawn upright. A style
            instruction saying stated build is preserved exactly was tried and changed
            nothing. Words spent on build are words the renderer discards, and they read as
            coverage that is not there — so the room goes to hair and face, which do render.

            - HAIR IS THE PRIMARY IDENTIFIER, and it must differ in SHAPE across the cast,
              not merely in colour and length. Three women all described as "shoulder-length"
              plus a colour will collapse into one another at any distance.

              Default to LONGER, STYLED hair. This look is long hair with visible styling,
              not short and practical, so reach first for: long and straight with a centre
              part, long with a high ponytail, a long braid over one shoulder, waist-length
              with heavy volume, half-up with the rest loose, a low twisted knot with strands
              down, long and layered with a deep side part. A bob, a crop or a tight bun is
              a deliberate choice for one character, not the default for the cast.

              THAT MAKES DISTINCTNESS HARDER, NOT EASIER, and it is the reason the check at
              the bottom of these instructions is written the way it is. Three women with
              long hair converge fast. Length alone stops separating them, so each one must
              differ on the axes that still read at distance:
                * where the mass sits — down the back, over one shoulder, piled high, at the
                  nape, swinging free at the jaw
                * up or down — and if up, how high and how tight
                * the parting — centre, deep side, none, severe
                * volume and texture in outline — flat and sleek, heavy and wavy, tight
                  curls with width at the sides
              Say what shape the hair makes, not just how long it is.
            - AGE MUST READ IN THE HEAD. Three places carry it and they are the three that
              survive a wide shot: hairline (receding, thinning, widow's peak), hair colour
              (steel grey, white, salt-and-pepper, still dark) and face shape (gaunt,
              jowled, softly rounded, angular, heavy-browed). State the decade explicitly as
              well. Do not reach for the body: a stooped, narrow frame reads as age to a
              reader and is drawn as an upright one, so it buys nothing.
            - NEVER WRITE AGEING TEXTURE. Not "deeply lined", not "sagging", not
              "weathered", not creased, furrowed, leathery, crepey or liver-spotted —
              and the ban is on the WORD, not on one phrase it appears in. "Weathered
              skin" and "weathered square jaw" are the same instruction to a generator;
              the second one was written after the first was banned. This is not a
              stylistic preference: this is drawn in an anime style, where the only thing a
              generator has for photoreal ageing is to draw it literally, and it will. A
              woman written as "late sixties, deeply lined round face, soft sagging
              jawline" came back at eighty-five with the lines and the spots drawn on, in
              every frame she appeared in. Every one of those words has a structural
              replacement that reads at distance and none of them do — say what shape the
              face is, not what the skin has been through.
            - THEN the fixed features, all of them above the collar: face shape, eye shape
              and colour, eyebrow shape, facial hair, glasses shape, and any single strong
              distinguishing mark. Eye SHAPE matters here more than eye colour — an anime
              style draws eye shape distinctly and reads colour as an afterthought.
            - Habitual clothing goes in style_notes, not in the description: what someone
              usually wears is stable, but it is not their face.
            - NO HATS unless the script actually requires one. A hat is clothing, so nothing
              else in these rules stops you writing one — but it covers the hair, and hair
              is the thing this cast is told apart by. A cast in caps is a cast with one
              silhouette. If the script gives someone a hat for a reason, keep it; if you
              are reaching for one to make a character feel ordinary, do not.
            - style_notes is CLOTHING ONLY. Never props, never anything held or carried,
              never anything the word "often" or "sometimes" would apply to. style_notes is
              pasted into every one of that character's prompts, so "often holding a
              handheld microphone" puts a microphone in all 36 scenes they appear in,
              including the ones in a parking lot four acts before the speech, and "and a
              wooden cane" puts a cane in the hand of a man sitting at a kitchen table. If
              they carry something in a particular scene, that scene's frame will say so.
            - NEVER anything that changes between scenes. No mood, no expression, no
              posture, no gait, no action, no location, no lighting, no camera angle. Those
              belong to the individual frame and will be written separately for each one.
              "Walks with a stiffness in one hip" asks for a man mid-stride in every frame
              he is in, including the ones where he is sitting down.
            - NEVER a hedge. No "usually", "often", "typically", "sometimes". These fields
              are applied unconditionally, so "hair usually pulled back" means hair pulled
              back in all 92 of her scenes — write the one state you want drawn every time.
            - NEVER anything a picture cannot show. Not their job history, not their
              motives, not how the narrator feels about them.
            - One flowing description, 25-45 words. No lists, no bullet points, no labels.

            USABLE — hair shaped, age in the hairline and the face, nothing below the collar:
              "Man in his late sixties, deeply receding white hairline swept back from a long
              gaunt face, heavy grey eyebrows, deep-set narrow eyes, square rimless glasses."

            USABLE — a woman, hair carrying the identification:
              "Woman in her early thirties, long black hair in a high tight ponytail with a
              deep side part, heart-shaped face, wide round eyes, fine arched brows."

            NOT USABLE — build doing work the renderer discards:
              "Mid-forties, heavy through the shoulders and thick-waisted, short greying hair
              receding at the temples, square jaw, deep-set brown eyes."

            NOT USABLE — texture doing the work, and it disappears past close-up:
              "Mid-forties, short greying brown hair receding at the temples, deeply lined
              brow, crow's feet, two-day stubble."

            NOT USABLE — vague; renders differently every time:
              "Tired-looking, worn down by life."

            Before you answer, read your own cast back AS A GROUP and check every pair of
            characters against each other. Two of them may both have long hair only if the
            SHAPES read differently at a distance where no face is legible — different
            parting, different volume, one up and one down, or the mass sitting somewhere
            else. "Both long and dark" is two characters the viewer will confuse, however
            different their faces are on the page.

            Check age the same way: the oldest and the youngest must be orderable from
            hairline, hair colour and face shape alone, with no body to help. If any pair
            fails, change one of them. A viewer tells these people apart at a glance or not
            at all.

            Consistency across the whole video depends on this text never varying. If a
            description is vague, the generator fills the gap differently in every scene and
            the character's face changes halfway through the video.
            TEXT,
            $this->castAgeBlock($story),
            $this->locale->guidanceFor((string) $story->locale_profile),
        ], fn (string $block): bool => trim($block) !== ''));
    }

    /**
     * The story's stated cast age range, if it has one.
     *
     * This is where a per-story casting decision belongs, and it is here
     * rather than in the art style constant for a reason worth keeping. The
     * style constant is one string appended to every prompt in every story;
     * asking it to carry "this cast is young" means asking one sentence to be
     * true of a workplace marriage drama and of a story about a dead mother and
     * an uncle with a cane at the same time. It cannot be, so it compensates —
     * and a rendering rule compensating for a casting decision is how the style
     * ended up instructing an anime generator to draw photoreal ageing.
     *
     * Note what this deliberately does NOT do: it does not override the script.
     * The extractor's job is to read the cast that was written, and a profile
     * that could rewrite a character's stated age would put the picture in
     * contradiction with the narration — the exact failure the style line is
     * also written to avoid. What it steers is INFERENCE, which is most of the
     * work: scripts rarely state an age, the model guesses one, and in this
     * genre it guesses old.
     *
     * Empty when the story states nothing, and array_filter drops it. Null
     * means no intention was expressed, not that a young cast was intended.
     */
    private function castAgeBlock(Story $story): string
    {
        $profile = trim((string) $story->cast_age_profile);

        if ($profile === '') {
            return '';
        }

        return "THE INTENDED AGE RANGE OF THIS CAST, from the operator:

  {$profile}

"
            .'Use this for every character whose age the script does not state outright. Where '
            .'the script DOES state an age, or makes one unambiguous — a grandmother, a '
            .'retirement, a character described as decades older than another — the script wins '
            .'and you write that age. A description that contradicts the narration is worse than '
            .'one outside the intended range, because the viewer hears both.';
    }

    /**
     * @param  array<int, string>  $scripts
     */
    /**
     * What a previous attempt got wrong, handed back verbatim.
     *
     * Re-asking the same question is not a repair — it re-rolls the same
     * mistake at the same rate. The first fix here corrected the SYSTEM prompt
     * and left the user message still asking for "habitual clothing and
     * recurring props"; the model followed the more specific instruction, which
     * was the wrong one. Naming the exact offending text is what makes a retry
     * a different question rather than the same one.
     *
     * @param  array<int, string>  $rejectionNotes
     */
    private function rejectionBlock(array $rejectionNotes): string
    {
        if ($rejectionNotes === []) {
            return '';
        }

        return "YOUR PREVIOUS ANSWER WAS REJECTED. Fix exactly this and change nothing else:\n\n  "
            .implode("\n  ", $rejectionNotes)
            ."\n\nThe rule you broke, in full:\n\n"
            .$this->text->ruleSummary()
            ."\n\nBoth description and style_notes are applied to EVERY prompt for that "
            .'character, so anything conditional, carried, momentary or textural becomes '
            .'unconditional and permanent. Remove the offending words and leave the rest of '
            ."each description exactly as it is.\n\n";
    }

    private function characterPrompt(Story $story, array $scripts, array $rejectionNotes = []): string
    {
        $body = '';

        foreach ($scripts as $index => $script) {
            $body .= sprintf("--- ACT %d ---\n%s\n\n", $index + 1, trim($script));
        }

        return sprintf(
            'Read the whole script below and list every character who APPEARS IN MORE THAN '
            ."ONE SCENE or who matters to the story.\n\n"
            .'Include the narrator. The narrator is on screen constantly and is the single '
            .'most important character to pin down — a narrator whose face changes is the '
            .'fastest way to lose a viewer. Name them from the script if they are named '
            // Was "a plain American name", hardcoded, for the whole of the time
            // there was only one setting to be wrong about. It would have named
            // the narrator of a story set in China "Karen" — and that name is
            // then pasted into every prompt she appears in and read aloud in
            // every act. The setting is already in this call's system prompt,
            // from the story's locale profile, so this defers to it rather than
            // carrying a second opinion about it.
            .'there; if the script never names them, give them a plain name that fits '
            ."the setting described in your instructions, and use it consistently.\n\n"
            .'Do NOT include people mentioned once in passing, people who are only spoken '
            .'about and never seen, or crowds. Every entry costs prompt space in every '
            ."scene they appear in.\n\n"
            .'For each: name, description (physical, fixed, silhouette-first, 25-45 words '
            .'— hair SHAPE distinct from every other character and longer and styled by '
            .'default, age carried by hairline, hair colour and face shape, never by skin '
            .'and never by build, height or frame, which this illustration style discards), '
            .'style_notes '
            .'(habitual CLOTHING ONLY, one short phrase — no props, nothing held or carried, '
            .'and no "often" / "sometimes" / "usually"; style_notes is pasted into every '
            .'prompt this character appears in, so anything conditional in it becomes '
            ."permanent), and importance (lead, supporting, or minor).\n\n"
            .'%s'
            ."THE SCRIPT:\n\n%s",
            $this->rejectionBlock($rejectionNotes),
            trim($body)
        );
    }

    // -- Scene drafting --------------------------------------------------------

    /**
     * @param  array<int, CharacterProfile>  $cast
     */
    private function sceneSystemPrompt(Story $story, array $cast): string
    {
        return implode("\n\n", [
            <<<'TEXT'
            You break a narrated script into still images. The video is 150-250 still
            illustrations with a slow camera move over each one, and a narrator reading the
            script over the top. You decide where each still starts and what is in it.

            YOU DO NOT WRITE OR EDIT THE NARRATION. You choose sentence ranges. The script
            was approved by a human and is read aloud exactly as written.

            THE FRAME IS NOT THE SENTENCE. This is the part that gets done wrong.

            The narration is spoken OVER the picture. The picture is not an illustration of
            the words — it is what the camera is looking at while those words are said. If
            the line is "I paid it on a Tuesday and did not tell anyone", the frame is NOT
            "a woman paying a bill on a Tuesday". It is a composed shot: a kitchen table at
            night, a laptop open on a banking screen, a woman in her forties sitting back
            with her hands in her lap, one lamp on, the rest of the house dark.

            Write each frame as: who is in it, where they are, what their expression and
            posture are, what the light is doing, and what is in shot around them.

            - Never restate the sentence. If your frame reads like a paraphrase of the
              narration, it is wrong.
            - Vary the shot. Faces, hands, objects, empty rooms, wide establishing shots,
              things seen over a shoulder. Twenty consecutive medium shots of people talking
              is the same failure as a slideshow.
            - Not every scene needs a person in it. An envelope on a doormat, a driveway at
              dusk, a phone face-down on a table — cutaways are what make the people land
              when they come back.
            - Present tense, concrete nouns. No metaphor.
            - EVERY SCENE WITH A PERSON IN IT NEEDS AN EXPRESSION, in the `expression` field,
              and it must name what the face is DOING. "Jaw set, eyes down." "Openly crying,
              tears on both cheeks." "Mouth open mid-word, brows driven down." Describe the
              face rather than labelling the emotion — "brows drawn together, mouth set hard"
              rather than "angry" — but a plain emotional state stated as a visible fact is
              better than nothing. Leave it empty ONLY when nobody is in the frame.
            - NEVER HEDGE AN EXPRESSION. "Slightly", "faintly", "a little", "somewhat",
              "barely", "almost", "subtly", "a touch", "half" applied to a face render as
              NOTHING — the picture comes back with the neutral expression it would have had
              if you had written no expression at all. Write the expression at the strength it
              actually is, or leave it out and spend the words on the room. A hedged
              expression is strictly worse than no expression: it costs words and buys a
              blank face.
            - 25-45 words per frame.

            DO NOT describe the art style, the medium, the colour palette, the rendering or
            the aspect ratio. Those are applied identically to every image afterwards and
            anything you say about them will conflict with them.

            DO NOT re-describe what a character looks like. Name them and list them in
            characters_present; their fixed description is pasted in automatically. Writing
            your own is exactly how a face drifts over 200 images.
            TEXT,
            $this->castBlock($cast),
            $this->motionGuidance(),
            $this->locale->guidanceFor((string) $story->locale_profile),
        ]);
    }

    /**
     * @param  array<int, CharacterProfile>  $cast
     */
    private function castBlock(array $cast): string
    {
        if ($cast === []) {
            return 'THE CAST: none extracted. Use no character names in characters_present.';
        }

        $lines = array_map(
            fn (CharacterProfile $c): string => sprintf('- %s (%s): %s', $c->name, $c->importance, $c->description),
            $cast
        );

        return "THE CAST — these are the only names you may put in characters_present:\n"
            .implode("\n", $lines)
            ."\n\nList a name only when that person is VISIBLE in the frame. Someone being "
            .'talked about is not someone in the picture.';
    }

    private function motionGuidance(): string
    {
        return <<<'TEXT'
        MOTION. Each still gets one slow camera move. Choose it from what the frame is:

        - zoom_in: pushes in. Faces, reactions, a detail becoming important.
        - zoom_out: pulls back. A reveal, or isolating someone in a bigger space.
        - pan_left / pan_right: travels sideways. Wide shots, interiors, landscapes,
          rooms with several people. A pan needs width to travel across.
        - static: no move. Use sparingly, for a held beat. More than a few in a row
          makes the video look broken.

        Vary it. A run of the same move reads as mechanical.
        TEXT;
    }

    /**
     * Where this act sits in the story, and what it is costing whom.
     *
     * THE SECOND CALL SITE OF A FINDING RECORDED AS CLOSED. `escalation_beat`
     * was required at outline, checked by ValidateOutlineSpine, editable at Gate
     * 1, shown on the page — and never reached `GenerateActScripts`, which
     * printed "COSTS: %s" as a blank for two phases. That was found and fixed.
     * It was fixed AT ONE CALL SITE.
     *
     * This call also decides what 150-250 pictures contain, and it has been
     * receiving `Act $act` and `Story $story` the whole time. The phase, the
     * beat and the whole genre spine were sitting on those two objects,
     * unasked. Nothing noticed, because nothing asks which OTHER callers read a
     * field — the same gap that let `CostUnit::TotalTokens` be added in code
     * while eleven migrations built their columns from the enum.
     *
     * Why a scene generator wants it: the frame is the picture the narration is
     * spoken over, and what a face should be doing depends entirely on whether
     * this act is an escalation the narrator is losing or a refusal they are
     * winning. Without it the generator has the sentences and nothing else, and
     * the safest picture to draw from a sentence is a neutral one.
     *
     * Kept short on purpose. This rides in every act's scene call, so it is
     * input tokens on 6-7 calls per story; the spine fields are one line each
     * and the phase is a word.
     */
    private function sceneContext(Story $story, Act $act): string
    {
        $lines = [];

        if ($act->phase !== null) {
            $lines[] = sprintf('PHASE: %s — %s', $act->phase->value, match ($act->phase->value) {
                'escalation' => 'the narrator is losing ground and absorbing it',
                'departure' => 'the narrator leaves, and does not announce it',
                'search' => 'the antagonist is looking for them, and it is costing her',
                'refusal' => 'the narrator is found and says no',
                default => 'unstated',
            });
        }

        foreach ([
            'COSTS' => $act->escalation_beat,
            'THE GRIEVANCE' => $story->narrator_grievance,
            'THE ANTAGONIST BELIEVES' => $story->antagonist_justification,
        ] as $label => $value) {
            if (trim((string) $value) !== '') {
                $lines[] = $label.': '.trim((string) $value);
            }
        }

        return $lines === [] ? '' : "\n".implode("\n", $lines)."\n";
    }

    /**
     * @param  array<int, string>  $sentences
     * @param  array<int, CharacterProfile>  $cast
     */
    private function scenePrompt(
        Story $story,
        Act $act,
        array $sentences,
        array $cast,
        int $targetScenes,
    ): string {
        $numbered = '';

        foreach ($sentences as $index => $sentence) {
            $numbered .= sprintf("%d. %s\n", $index + 1, $sentence);
        }

        $total = count($sentences);
        $wordsPerScene = (int) config('scenes.words_per_scene');

        return sprintf(
            "ACT %d: %s\n%s\n"
            ."Break the numbered script below into about %d scenes.\n\n"
            ."RULES FOR THE RANGES:\n"
            ."- Every sentence from 1 to %d must be in exactly one scene.\n"
            .'- Scenes are contiguous and in order: the first scene starts at sentence 1, '
            .'the last ends at sentence %d, and each scene starts at the sentence after the '
            ."previous one ended.\n"
            ."- Never split a sentence. Ranges are whole sentences only.\n"
            .'- Aim for roughly %d words of narration per scene — usually one to three '
            .'sentences. Break where the picture would naturally change: a new place, a new '
            ."person speaking, a jump in time.\n"
            .'- A long sentence can be a scene on its own. Several short ones can share a '
            ."scene if they are the same moment.\n\n"
            ."FOR EACH SCENE GIVE:\n"
            ."- first_sentence, last_sentence: the range.\n"
            ."- frame: the composed shot. What is in the picture, not what the line says.\n"
            ."- characters_present: names from the cast who are VISIBLE. Empty if nobody is.\n"
            ."- motion_preset: zoom_in, zoom_out, pan_left, pan_right or static.\n"
            .'- expression: what the faces are DOING, named plainly. Empty ONLY if nobody is '
            ."in the frame.\n"
            .'- thumbnail_candidate: true for at most two scenes in this act — the ones that '
            .'would stop someone scrolling. A face mid-reaction, or an object that raises a '
            ."question. Never a wide establishing shot.\n\n"
            ."THE SCRIPT (%d sentences):\n\n%s",
            $act->sequence,
            $act->title,
            $this->sceneContext($story, $act),
            $targetScenes,
            $total,
            $total,
            $wordsPerScene,
            $total,
            trim($numbered),
        );
    }

    // -- Schemas -------------------------------------------------------------

    /**
     * The outline's shape, with the genre spine as REQUIRED fields.
     *
     * Required in the schema rather than merely asked for in the prompt, and
     * that is the point of putting them here: a model asked in prose for five
     * things will reliably give four when one is awkward, and the awkward one
     * is usually `antagonist_justification` — writing a self-justifying
     * antagonist is harder than writing a villain. A required field cannot be
     * skipped, only answered badly, and a bad answer is something Gate 1 can
     * see. See ValidateOutlineSpine.
     *
     * @return array<string, mixed>
     */
    private function outlineSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],

                // The first thirty seconds. Required in the schema for the
                // reason the other seven are — a model asked in prose for
                // several things drops the awkward one — and this is an
                // awkward one in a specific way: the beats it asks for are
                // the LAST things that happen in the chronology it wants to
                // open with. Left optional it would come back as a summary of
                // the premise, which is what the opening already is.
                'hook' => ['type' => 'string'],

                'narrator_grievance' => ['type' => 'string'],
                'antagonist_justification' => ['type' => 'string'],
                'withheld_information' => ['type' => 'string'],
                'exposure_moment' => ['type' => 'string'],

                // The reversal half. Required for the same reason the first
                // four are: asked for in prose, the awkward one gets dropped,
                // and these are the awkward ones — a model trained on this
                // genre's most common shape will happily escalate to an
                // exposure and stop, which is exactly the video story 21 was.
                'departure' => ['type' => 'string'],
                'reversal_beats' => ['type' => 'string'],
                'refusal' => ['type' => 'string'],

                'acts' => [
                    'type' => 'array',
                    // No minItems/maxItems: structured outputs reject any
                    // minItems other than 0 or 1, so the exact count is
                    // enforced against the decoded response instead. The
                    // prompt still enumerates the slots.
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'summary' => ['type' => 'string'],
                            'escalation_beat' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'summary', 'escalation_beat'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => [
                'title',
                'hook',
                'narrator_grievance',
                'antagonist_justification',
                'withheld_information',
                'exposure_moment',
                'departure',
                'reversal_beats',
                'refusal',
                'acts',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function actSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'script' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'rehook_line' => ['type' => 'string'],
            ],
            'required' => ['script', 'summary', 'rehook_line'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function characterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'characters' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'style_notes' => ['type' => 'string'],
                            'importance' => ['type' => 'string', 'enum' => ['lead', 'supporting', 'minor']],
                        ],
                        'required' => ['name', 'description', 'style_notes', 'importance'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['characters'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sceneSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'scenes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'first_sentence' => ['type' => 'integer'],
                            'last_sentence' => ['type' => 'integer'],
                            'frame' => ['type' => 'string'],
                            'characters_present' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'motion_preset' => [
                                'type' => 'string',
                                'enum' => ['zoom_in', 'zoom_out', 'pan_left', 'pan_right', 'static'],
                            ],
                            'expression' => ['type' => 'string'],
                            'thumbnail_candidate' => ['type' => 'boolean'],
                        ],
                        'required' => [
                            'first_sentence',
                            'last_sentence',
                            'frame',
                            'characters_present',
                            'motion_preset',
                            // REQUIRED, and empty only when nobody is in frame.
                            // Asked for in prose since Phase 2 and supplied on
                            // 22.2% of peopled frames; the other 77.8% bought a
                            // still wearing the reference portrait's neutral
                            // face. A required field cannot be skipped, only
                            // answered badly, and a bad answer is visible at
                            // Gate 2.
                            'expression',
                            'thumbnail_candidate',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['scenes'],
            'additionalProperties' => false,
        ];
    }
}
