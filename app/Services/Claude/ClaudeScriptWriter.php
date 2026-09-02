<?php

namespace App\Services\Claude;

use Anthropic\Client;
use App\Contracts\ScriptWriter;
use App\Enums\MotionPreset;
use App\Enums\StoryFormat;
use App\Models\Act;
use App\Models\Story;
use App\Support\LocaleGuard;
use App\Support\Providers\ActOutline;
use App\Support\Providers\ActScriptDraft;
use App\Support\Providers\CharacterCast;
use App\Support\Providers\CharacterProfile;
use App\Support\Providers\OutlineDraft;
use App\Support\Providers\SceneDraft;
use App\Support\Providers\SceneDraftSet;
use App\Support\Providers\ScriptWriterException;
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

        $acts = [];

        foreach (($decoded['acts'] ?? []) as $index => $act) {
            $acts[] = new ActOutline(
                sequence: $index + 1,
                title: trim((string) ($act['title'] ?? '')),
                summary: trim((string) ($act['summary'] ?? '')),
                escalationBeat: trim((string) ($act['escalation_beat'] ?? '')),
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
            narratorGrievance: trim((string) ($decoded['narrator_grievance'] ?? '')),
            antagonistJustification: trim((string) ($decoded['antagonist_justification'] ?? '')),
            withheldInformation: trim((string) ($decoded['withheld_information'] ?? '')),
            exposureMoment: trim((string) ($decoded['exposure_moment'] ?? '')),
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

        if (! $this->draftIsUsable($scenes, $sentences) && $fallback !== '') {
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

        return new SceneDraftSet($scenes, $usage, $discarded);
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
    private function draftIsUsable(array $scenes, array $sentences): bool
    {
        if (! $this->rangesTile($scenes, count($sentences))) {
            return false;
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

        if (($short / max(count($scenes), 1)) > $threshold) {
            return false;
        }

        return $this->motionIsVaried($scenes);
    }

    /**
     * Whether the camera does more than one thing across this act.
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
    private function motionIsVaried(array $scenes): bool
    {
        $total = count($scenes);

        // Too small a sample to call monotonous. Three scenes sharing a preset
        // is a coincidence, not a pattern, and failing on it would re-bill an
        // act for nothing.
        if ($total < 8) {
            return true;
        }

        $counts = [];

        foreach ($scenes as $scene) {
            $preset = $scene->motionPreset ?? '';

            if ($preset !== '') {
                $counts[$preset] = ($counts[$preset] ?? 0) + 1;
            }
        }

        $static = $counts[MotionPreset::Static->value] ?? 0;

        if ($static / $total > (float) config('scenes.static_share_threshold', 0.15)) {
            return false;
        }

        $monotony = (float) config('scenes.motion_monotony_threshold', 0.55);

        foreach ($counts as $preset => $count) {
            if ($preset !== MotionPreset::Static->value && $count / $total > $monotony) {
                return false;
            }
        }

        return true;
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

        THE SHAPE
        - Escalating humiliation. Every act costs the narrator more than the
          last: money, standing, a relationship, dignity, in front of more
          people each time.
        - Nothing is resolved before the end. No act ends with the narrator
          winning a round, being vindicated, or getting an apology that sticks.
        - The narrator holds information the antagonist does not have. It is
          established early and never used until the end.

        THE PAYOFF
        - Exposure, in front of witnesses. The truth comes out publicly, at a
          moment the antagonist chose and controlled.
        - NOT revenge. The narrator does not sabotage, retaliate, or destroy
          anything. They produce the truth and let it do the work.
        - The antagonist's own excuse is what convicts them. The best version is
          the antagonist repeating their justification in front of people who
          now know it is false.
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
        $wpm = (int) config('render.narration.words_per_minute');

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
        $slots = implode("\n", array_map(
            fn (int $n): string => "  {$n}. <act {$n}>",
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
            ."occasion and the witnesses. This is the payoff of the whole video.\n\n"
            .'Then fill in every one of these %d slots. Return exactly %d act objects, in '
            ."this order:\n\n%s\n\n"
            .'Do not merge slots, do not leave one out, and do not add another. Each slot '
            .'becomes one YouTube chapter, so the count is fixed before any of it is '
            ."written.\n\n"
            ."For each act give:\n"
            .'- title: works as a YouTube chapter title. 2-6 words. Marks a stage of the '
            ."escalation. Does not give away the exposure. No numbering, no 'Act One'.\n"
            .'- summary: 3-5 sentences. What actually happens, concretely. The act script is '
            ."written from this and nothing else, so anything vague here gets invented later.\n"
            .'- escalation_beat: one sentence naming what this act COSTS the narrator that '
            .'the previous act did not. Each act must cost more than the one before it. No '
            ."act resolves anything, wins a round, or produces an apology that sticks.\n\n"
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
                "%d. %s\n   %s\n   COSTS: %s%s",
                $entry->sequence,
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
            ? 'This act opens the video. Its first two sentences are the 15-second hook and the '
                .'highest-leverage text in the whole script. Open in the middle of the grievance — '
                .'the moment it became undeniable — not with background. State plainly what was '
                .'taken and by whom. Do not open with scene-setting, weather, or a childhood memory.'
            : sprintf(
                'This act opens at roughly minute %d, where viewers leave. Its first two sentences '
                .'are a re-hook: give someone about to close the tab a reason not to. Open on the '
                .'next indignity already in progress. Do not open by recapping act %d.',
                (int) round(($act->sequence - 1) * $targetWords / (int) config('render.narration.words_per_minute')),
                $act->sequence - 1,
            );

        $ending = $isLast
            ? "THIS IS THE FINAL ACT. It contains the exposure:\n\n"
                .$story->exposure_moment."\n\n"
                .'The withheld information comes out here and nowhere earlier. Put the witnesses in '
                .'the room and name them. The antagonist repeats their justification in front of '
                .'people who now know it is false — that is the moment the video exists for. '
                .'The narrator does not retaliate, gloat, or explain the moral. They state the fact, '
                .'and the room reacts. End within a few sentences of the reveal landing: no epilogue '
                .'about what everyone learned, no ambiguity, no "I still think about it sometimes".'
            : 'This is NOT the final act. The withheld information does not come out here. Nothing '
                .'is resolved: the narrator does not win a round, get a real apology, or find an '
                .'ally who fixes anything. End the act worse off than it started.';

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
            'What this act must cost the narrator: '.$act->escalationBeat,
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

    // -- Character extraction --------------------------------------------------

    private function characterSystemPrompt(Story $story): string
    {
        return implode("\n\n", [
            <<<'TEXT'
            You write character sheets for an illustrated video. Each description you write
            will be pasted, WORD FOR WORD AND UNCHANGED, into 150-250 separate image
            generation prompts across a 35-minute video.

            That is the whole job, and it dictates the form:

            - PHYSICAL AND FIXED ONLY. Age, build, height, hair colour and cut, face shape,
              skin, eyes, facial hair, glasses, distinguishing marks.
            - Habitual clothing goes in style_notes, not in the description: what someone
              usually wears is stable, but it is not their face.
            - style_notes is CLOTHING ONLY. Never props, never anything held or carried,
              never anything the word "often" or "sometimes" would apply to. style_notes is
              pasted into every one of that character's prompts, so "often holding a
              handheld microphone" puts a microphone in all 36 scenes they appear in,
              including the ones in a parking lot four acts before the speech. If they
              carry something in a particular scene, that scene's frame will say so.
            - NEVER anything that changes between scenes. No mood, no expression, no
              posture, no action, no location, no lighting, no camera angle. Those belong
              to the individual frame and will be written separately for each one.
            - NEVER anything a picture cannot show. Not their job history, not their
              motives, not how the narrator feels about them.
            - Concrete and unambiguous. "Mid-forties, heavy through the shoulders, short
              greying brown hair receding at the temples, square jaw, deep-set brown eyes,
              two-day stubble" is usable. "Tired-looking, worn down by life" is not — it
              renders differently every time.
            - One flowing description, 25-45 words. No lists, no bullet points, no labels.

            Consistency across the whole video depends on this text never varying. If a
            description is vague, the generator fills the gap differently in every scene and
            the character's face changes halfway through the video.
            TEXT,
            $this->locale->guidanceFor((string) $story->locale_profile),
        ]);
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
            ."\n\nstyle_notes is applied to every prompt for that character. Write only what they "
            ."wear in EVERY scene. If they carry something in one scene, leave it out entirely.\n\n";
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
            .'there; if the script never names them, give them a plain American name that '
            ."fits and use it consistently.\n\n"
            .'Do NOT include people mentioned once in passing, people who are only spoken '
            .'about and never seen, or crowds. Every entry costs prompt space in every '
            ."scene they appear in.\n\n"
            .'For each: name, description (physical, fixed, 25-45 words), style_notes '
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
            - Present tense, concrete nouns. No metaphor. No emotion words as such; show the
              expression instead.
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
            "ACT %d: %s\n\n"
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
            .'- thumbnail_candidate: true for at most two scenes in this act — the ones that '
            .'would stop someone scrolling. A face mid-reaction, or an object that raises a '
            ."question. Never a wide establishing shot.\n\n"
            ."THE SCRIPT (%d sentences):\n\n%s",
            $act->sequence,
            $act->title,
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

                'narrator_grievance' => ['type' => 'string'],
                'antagonist_justification' => ['type' => 'string'],
                'withheld_information' => ['type' => 'string'],
                'exposure_moment' => ['type' => 'string'],

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
                'narrator_grievance',
                'antagonist_justification',
                'withheld_information',
                'exposure_moment',
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
                            'thumbnail_candidate' => ['type' => 'boolean'],
                        ],
                        'required' => [
                            'first_sentence',
                            'last_sentence',
                            'frame',
                            'characters_present',
                            'motion_preset',
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
