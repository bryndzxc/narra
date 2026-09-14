<?php

namespace App\Services\Claude;

use Anthropic\Client;
use App\Contracts\ScriptWriter;
use App\Enums\ActPhase;
use App\Enums\ActTimeframe;
use App\Enums\MotionPreset;
use App\Enums\StoryFormat;
use App\Models\Act;
use App\Models\Chapter;
use App\Models\Scene;
use App\Models\Story;
use App\Support\ChapterAnnouncement;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use App\Support\Providers\ActOutline;
use App\Support\Providers\ActScriptDraft;
use App\Support\Providers\ChapterDraft;
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
                // The model's declaration, unlike the phase above, which is
                // ours. A value outside the enum decodes to null rather than
                // to present: null is "not said", and Gate 1 treats it as such.
                timeframe: ActTimeframe::tryFrom(trim((string) ($act['timeframe'] ?? ''))),
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
            narratorAtExposure: trim((string) ($decoded['narrator_at_exposure'] ?? '')),
            departure: trim((string) ($decoded['departure'] ?? '')),
            reversalBeats: trim((string) ($decoded['reversal_beats'] ?? '')),
            refusal: trim((string) ($decoded['refusal'] ?? '')),
            betrayalScene: trim((string) ($decoded['betrayal_scene'] ?? '')),
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

        // The act comes back AS chapters, and the script is their texts
        // joined. The count and the per-chapter bounds are NOT checked here,
        // for the reason the outline's act count is not: throwing inside the
        // provider loses the cost row for a call that was billed.
        // GenerateActScripts checks them after recording the spend.
        $chapters = [];

        foreach ((array) ($decoded['chapters'] ?? []) as $entry) {
            $text = trim((string) ($entry['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $chapters[] = new ChapterDraft(
                title: trim((string) ($entry['title'] ?? '')),
                rehookLine: trim((string) ($entry['rehook_line'] ?? '')),
                text: $text,
            );
        }

        $script = implode("\n\n", array_map(fn (ChapterDraft $c): string => $c->text, $chapters));

        if ($script === '') {
            throw new ScriptWriterException("Act {$act->sequence} came back with an empty script.");
        }

        return new ActScriptDraft(
            sequence: $act->sequence,
            script: $script,
            summary: trim((string) ($decoded['summary'] ?? '')),
            // The act's opening line is its first chapter's.
            rehookLine: $chapters[0]->rehookLine ?? '',
            usage: $usage,
            chapters: $chapters,
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

            try {
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
            } catch (\Throwable $e) {
                // THE DISCARDED ATTEMPT WAS BILLED AND ITS ROW DIES WITH THIS
                // EXCEPTION UNLESS IT IS WRITTEN NOW. Every usage above is
                // handed back in the SceneDraftSet for DraftScenes to record,
                // and a throw means there is no set to hand back. Story 28 act
                // 2: Haiku billed ~$0.035, Sonnet truncated, and only the
                // Sonnet row reached the ledger.
                //
                // The decode is inside the try for the same reason — a
                // response that arrives and will not parse is one more way to
                // leave the first call unrecorded.
                $this->recordSpendWithNoAction($discarded[0], 'draft_scenes');

                throw $e;
            }
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
        - THEY ARE PLAIN-SPOKEN AND FUNNY ABOUT IT. Dry, blunt, and specific,
          with a joke where a dignified narrator would go quiet. This was
          measured against a working video in this niche that holds an
          audience for 35 minutes; ours were dignified and mostly silent, said
          "Yes, Mother" nine times in one story, and lost the audience inside
          three minutes. The humour is the narrator's own, aimed at the
          situation and at themselves as often as at the antagonist. They
          still do not rant: a wisecrack is one sentence, not a paragraph.
        - THE CRUDE LINE IS WHAT THEY THINK; THE CONTROLLED LINE IS WHAT THEY
          SAY. Keep the two apart, because the pairing is what makes the
          narrator fun to be inside without making them a ranter. In the
          reference, when she says "it won't affect our wedding", the narrator
          THINKS, to the listener: "Our wedding? Marry you my ass." — and then
          SAYS, out loud, to her, a few seconds later: "I get it. You've
          brought him here and shown him off to all of us. So are you staying
          for a breakup dinner, or going out on a date with your new boy toy?"
          The first is narration and nobody in the room hears it. The second
          is dialogue: cool, exact, and funnier for being controlled. Never
          put the crude thought in the narrator's mouth, and never let the
          spoken line go crude.
        - MILD LANGUAGE ONLY, and none in the first thirty seconds. "My ass",
          "hell", "damn" are fine. No f-word, no s-word, nothing stronger:
          strong or frequent profanity limits the ads on a format that exists
          for mid-roll ads, and the first thirty seconds are what YouTube
          reads hardest.
        - When they speak in a scene, they say something. A short, funny,
          exact line — not a speech, and not "I said all right." A narrator
          who answers back is not a narrator who wins the round; what the
          line costs the antagonist, if anything, is decided by the phase.
        - NEVER NARRATE THE NARRATION, AND THIS IS A BAN ON A MOVE RATHER THAN
          ON A LIST OF PHRASES. The move is any sentence whose subject is the
          telling instead of the events: addressing the listener about what
          they should understand, know, notice, remember or hold on to;
          announcing what you are about to say, or being honest, exact, clear
          or fair about it; promising to come back to something. "I want to be
          honest about this." "I want you to understand that nobody asked me."
          "I need you to know what that number meant." "Let me be clear."
          "I'll get to that." Four stories carried sixteen of the first form.
          Those four phrasings were then banned by name and the next story
          carried five of "I want you to understand" instead — the same move
          in a coat the list did not cover. THERE IS NO LIST. If a sentence is
          about how to read the story rather than about what happened in it,
          cut it and say the thing. The narrator never tells the listener what
          to feel or what to notice; they say what was done and let it land.
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

        1. ESCALATION, AND IT OPENS ON THE BETRAYAL AS A SCENE, NOT A
           DISCOVERY. The first chapter after the hook is the betrayal being
           DONE in front of people, in the story's present — not found in a
           message, a booking or somebody's photos. The reference: she arrives
           late to a dinner of nine people holding another man's hand; a
           friend asks "Who's this? Your younger brother?"; she says "He's my
           boyfriend" one word at a time; then she justifies it to the
           narrator's face — "I want to see a different view before I get
           married... it won't affect our wedding" — and says it again to the
           friend after the man has left. The person it is done with is IN THE
           ROOM and does not need a line: that man never speaks in the whole
           video. The antagonist's justification is first said HERE, aloud,
           to the narrator, with an audience — not saved for a banquet ten
           minutes in. The narrator answers back, and loses the round.
           Every act after it costs the narrator more than the last: money,
           standing, a relationship, dignity, in front of more people each
           time. Nothing is recovered: no cost comes back, no apology sticks,
           no ally fixes anything. BUT THE NARRATOR ANSWERS IN THE ROOM. In
           every scene where the antagonist is present, the narrator says
           something — one short, exact, funny line — and it lands. It changes
           nothing about the cost; the ledger still runs against the narrator
           until the departure. The exchange is won and the round is lost,
           which is the sawtooth this niche actually runs: inside the betrayal
           scene itself the reference narrator asks whether she is staying
           for a breakup dinner or going on a date with her new boy toy, and
           she still turns her own best friend's anger back onto him. A
           narrator who says "all right" and "Yes, Mother" for twenty minutes
           was measured against that and lost the audience inside three. The
           narrator holds information the antagonist does not have, established
           early and not used.
           THE ESCALATION ACTS ARE SET IN THE STORY'S PRESENT. A prior incident
           — the first time this happened, the year the house was bought, what
           was said at a banquet years ago — is CITED in one sentence, with its
           date, inside a present-day act. It is never given a scene and never
           given an act. The line the antagonist said years ago is quoted there
           in one sentence and STAGED at its most recent saying, now, in a room
           the present-day story is in. A history-shaped premise ("the first
           time... the second time... the third time") is the one most likely
           to lose this: the third time is the story, and the first two are a
           sentence each.

        2. THE DEPARTURE. The narrator goes. Not a final speech — they leave,
           and WHERE THEY WENT IS NOT ANNOUNCED. The break itself may be said
           out loud, plainly and once: the reference narrator cancels the
           wedding through her parents and tells her to her face "we broke
           up", and that is not the departure. The departure is the leaving,
           and it is unannounced — no note, no farewell, no address, and
           everyone who knows is asked not to tell her. The antagonist finds
           out later, from somebody else, that they are simply gone.

        3. THE SEARCH, AND THE MEETINGS. The antagonist looks for them AND
           REACHES THEM. This is the part the first version of this contract
           got wrong, and it was measured: a working video in this niche puts
           the antagonist and the narrator in the same scene five times after
           the betrayal — at 9:53, 16:43, 18:21, 27:00 and 28:27 of 34:46 —
           and every one is worse for her. Ours kept them apart for eleven to
           twenty-three minutes and lost the audience. So in this phase she
           runs into them, follows them, sits down across from them, turns up
           where they are — on her initiative or by chance, in front of more
           people each time — and each meeting costs HER more than the last:
           money, standing, the people who backed her excuse, and finally her
           face in public. The narrator answers in a line and leaves. They do
           not go back, do not explain, do not search for her, and do not send
           a message. The search may SUCCEED: she may find out where they are.
           What it must never do is hand her the scene on her terms — when she
           finds them, the finding is the cost (the reference: she finds him
           on a street in another city with someone else, and kneels in
           public), and the narrator decides where and how long they talk.

        4. THE REFUSALS. The narrator is in the room for the exposure, by
           their own choice or because she came to where they are, and they
           produce the withheld information in person. Then she asks them to
           come back, and the narrator says no. Each refusal answers ONE
           specific earlier humiliation, in the words it was done in. This is
           the private payoff, and it is the thing the audience has been
           waiting the whole video for. The strongest form is a loss she can
           no longer repair: in the reference, the moment he walked away her
           innocence became unprovable, and "how can you prove that" is the
           line that ends her.

        5. THE END. The last refusal lands and the narrator walks away. THEN
           AN EPILOGUE IS ALLOWED, AND IT IS SHORT: a time jump in the
           narrator's own voice — a year later — that shows what the
           narrator's life is now and what her loss looks like from outside.
           The reference closes on one: a year on, the narrator's wedding, and
           "Among all our mutual friends Sophia was the only one who didn't
           show up." That sentence is the epilogue's job. It is not a moral,
           not what everyone learned, and not "I still think about it
           sometimes".

        The reversal — movements 2, 3 and 4 — is a PHASE, and it is THE LAST
        THREE ACTS: one to leave in, one to be searched for in, one to refuse
        in. It is not a scene at the end. A story that escalates for thirty
        minutes and gives the narrator power in the final ninety seconds has
        written the wrong video.

        THE TWO PAYOFFS
        - PUBLIC: exposure, in front of witnesses. The truth comes out at a
          moment the antagonist chose and controlled. The antagonist's own
          excuse is what convicts them — the best version is the antagonist
          repeating their justification in front of people who now know it is
          false. THE NARRATOR IS IN THE ROOM FOR IT and produces the withheld
          information themselves. A document, a lawyer or a friend can carry
          the fact; the narrator is what makes it a scene rather than a
          report, and a payoff the narrator hears about later, from somebody
          who was there, is hearsay in the one place the video cannot afford
          it. THE BETRAYAL SCENE IS PUBLIC TOO, AND IT IS NOT THIS PAYOFF: in
          the betrayal scene she says her justification in front of people
          and wins the room; at the exposure she says it again in front of
          people who now know it is false and loses it. Witnesses in chapter
          one do not spend the exposure — they are what makes it land. Nor do
          later scenes have to be bigger than the betrayal scene; they have to
          cost more.
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
            - No "Act One" or "Chapter" markers in the prose itself, except the
              spoken chapter number the chapter instruction below asks for.
            - Dialogue is quoted plainly. "she said" and nothing fancier.
            - Concrete detail over interiority. Name the amounts, the dates, the rooms,
              the exact words people used — of what is happening NOW. What happened
              years ago gets one sentence and its date, never a scene. Specifics are
              what make it feel true.
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
            .'- betrayal_scene: THE BETRAYAL AS A SCENE, NOT A DISCOVERY. The first chapter after the '
            .'hook is the betrayal being DONE, in the story\'s present, in a room with people in it — '
            .'not a message found, a booking read, photos posted or news heard later. Say where it '
            .'happens and name who is watching, including the one who asks the question that makes '
            .'her say it out loud. Name WHO IT IS DONE WITH OR FOR and put them in the room — they do '
            .'not have to speak; in the reference the other man never says a word, he stands there '
            .'holding her hand, smiling awkwardly, and leaves when she tells him to. Then she says '
            .'the antagonist_justification ALOUD, to the narrator\'s face, in front of all of them, '
            .'in the words you wrote above — this is where it is first said, not a banquet in act 2. '
            .'Then the narrator\'s one line back, and how the narrator still loses the round. If the '
            .'premise has the betrayal found, the scene is the moment she confirms it aloud in front '
            ."of people rather than the moment it was found.\n"
            .'- withheld_information:the specific thing the narrator knows and the '
            .'antagonist does not. It must already be true at the start of the story, and '
            .'the narrator must have a plausible reason not to say it. AND WHAT THE NARRATOR '
            .'MUST PRODUCE IN PERSON: a fact a document, a lawyer or a friend can produce on '
            .'their own lets the narrator stay eight hundred kilometers away while it comes '
            .'out, and the public payoff arrives as hearsay. Make it something only the '
            .'narrator, present in the room, can put on the table — a signature only they can '
            ."give, a vote that needs them there, a bag only they carry.\n"
            .'- exposure_moment: where the truth comes out, and WHO IS IN THE ROOM. Name the '
            .'occasion and the witnesses. This is the PUBLIC payoff, and the narrator is in '
            ."the room for it.\n"
            .'- narrator_at_exposure: how the narrator comes to be in that room — by their own '
            .'choice, unexpected, or because she has found where they are and come — and what only '
            .'they produce there. Either way the scene is the NARRATOR\'S: they decide where and how '
            .'long, and she does not get it on her terms. Reuse the specific thing from '
            ."withheld_information, because Gate 1 checks this against it.\n"
            .'- departure: how and when the narrator leaves, and what finally makes staying '
            .'impossible. The break may be said out loud once; WHERE THEY WENT IS NOT ANNOUNCED — no '
            .'note, no farewell speech, no address, and the people who know are asked not to tell '
            ."her. She finds out later, from somebody else, that they are gone.\n"
            .'- reversal_beats: what the antagonist does to find them and to reach them, as at least '
            .'two escalating attempts, and what each one COSTS HER. Money, then standing, then the '
            .'people who backed her excuse, then her face in public. She and the narrator are IN THE '
            .'SAME SCENE in at least two of these — she runs into them, follows them, sits down '
            .'across from them — and each meeting goes worse for her than the last. These are the '
            ."humiliation beats running the other way, and a search that costs her nothing is a "
            ."montage of somebody looking worried.\n"
            .'- refusal: what the narrator says when the antagonist asks them to come back, and '
            .'WHICH EARLIER MOMENT EACH REFUSAL ANSWERS. Name that moment. The strongest version '
            ."hands back the sentence she said in the betrayal scene, in her words, from the other "
            .'side of it, and names a loss she can no longer repair. This is the PRIVATE payoff and '
            ."it is what viewers stay forty minutes for.\n"
            .'- hook: the first thirty seconds of the video, as five beats in this order. This is '
            .'the highest-leverage text in the whole script, and it is the one place where writing '
            ."the chronological beginning loses the viewer. DO NOT START AT THE BEGINNING:\n"
            .'    1. ONE sentence of setup. One. Not a second one, and never a sentence about the '
            .'video itself, no "I want to start there", no "to understand this you need to know". '
            ."A second sentence of setup is the beat this most often loses.\n"
            .sprintf(
                '    2. The betrayal itself, stated within %s words. Not its aftermath and not a '
                .'summary of how it turned out: the thing that was done, being done, in the room '
                ."it happened in — the betrayal_scene you wrote above, compressed to a sentence. "
                ."That is %d seconds of narration at the rate this script is being written to.\n",
                number_format($hookWords),
                (int) round(ScriptSizing::hookBetrayalSeconds()),
            )
            .'    3. Evidence in EXACT WORDS. A line of dialogue, a message or a document, quoted '
            .'rather than described. Draw on the antagonist_justification you wrote above: the '
            .'hook is where it lands first, as the bait. THE BETRAYAL SCENE KEEPS IT. This genre '
            .'plays the same line twice, once here in a single sentence and again straight after '
            .'the hook, in chapter one, in full, said aloud in the room with everyone watching — '
            .'and the second landing is stronger for the first. Do not spend it here, and do not '
            ."paraphrase it in either place.\n"
            .'    4. ONE small, cold action by the narrator. Not a confrontation, not a speech and '
            .'not a threat: something quiet and exact. A spreadsheet opened and named, a bag '
            .'packed, a flat courtesy said to somebody expecting a fight. THE RECKONING is the '
            .'final act, and spending it here spends the video. A round the narrator LOSES is not '
            .'the reckoning: the betrayal scene after the hook is one, and the narrator answers '
            ."back in it.\n"
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
            .'- summary: 3-5 sentences, at most '.number_format(Act::SUMMARY_MAX_CHARS).' characters. '
            .'What actually happens, concretely. The act script is '
            ."written from this and nothing else, so anything vague here gets invented later.\n"
            .'- escalation_beat: one sentence naming what this act COSTS, and to whom. In the '
            .'escalation and departure phases that is the narrator, each act costing more than '
            .'the one before it, and nothing resolving — no round won, no apology that sticks. '
            .'In the search and refusal phases it is the ANTAGONIST, escalating the same way. '
            ."The cost changes direction at the departure and never changes back.\n"
            .'- timeframe: "present" or "prior". Every act is PRESENT: it takes place in the '
            .'story\'s now, and anything that happened years earlier is cited inside it in one '
            .'sentence with its date. An act whose summary is a year-old banquet, the first '
            .'betrayal told in full, or four years of night shifts is "prior", and Gate 1 refuses '
            .'the outline for it — so if you find yourself writing one, fold it into a sentence '
            ."of a present-day act instead and mark that act present.\n\n"
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
                "%d. [%s%s] %s\n   %s\n   COSTS: %s%s",
                $entry->sequence,
                // The phase is in the context block, not only on the act being
                // written. An act 6 that cannot see act 5 was the departure
                // has no way to know the narrator is already gone.
                strtoupper($entry->phase?->value ?? 'act'),
                $entry->timeframe === null ? '' : ' · '.strtoupper($entry->timeframe->value),
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

        return implode("\n\n", array_filter([
            'THE SPINE OF THIS STORY:',
            sprintf(
                "Grievance: %s\n\nThe antagonist's justification: %s%s\n\nWhat the narrator knows and "
                ."they do not: %s\n\nWhere it comes out: %s%s",
                $story->narrator_grievance,
                $story->antagonist_justification,
                // To every act, not only act 1 that stages it. An act 3 that
                // does not know the justification was already said aloud in
                // front of nine people re-stages its "first" saying at a
                // banquet — which is what stories 29-32 did — and a refusal
                // act that does not know it cannot hand that sentence back.
                trim((string) $story->betrayal_scene) === ''
                    ? ''
                    : "\n\nWhere she first said it aloud, to the narrator's face, in chapter one: "
                        .$story->betrayal_scene,
                $story->withheld_information,
                $story->exposure_moment,
                // The fifth spine line, to every act and not only the last:
                // an escalation act that knows the narrator will produce the
                // bag in person at the banquet plants the bag, and a search
                // act that knows it does not write the antagonist finding it.
                trim((string) $story->narrator_at_exposure) === ''
                    ? ''
                    : "\n\nHow the narrator is in the room for it: ".$story->narrator_at_exposure,
            ),
            'FULL OUTLINE (for context — write only the marked act):',
            $outlineBlock,
            'WHAT HAS ALREADY BEEN WRITTEN:',
            $priorBlock,
            sprintf('NOW WRITE ACT %d: %s', $act->sequence, $act->title),
            $act->summary,
            sprintf('%s: %s', $act->phase?->beatLabel() ?? 'What this act must cost the narrator', $act->escalationBeat),
            $this->timeframeInstruction($act),
            $ending,
            $this->chapterInstruction($story, $act),
            // THE OPENING INSTRUCTION GOES LAST, on every act and not only on
            // act 1. Story 30 lost two of act 1's five beats and both of its
            // special-case chapter announcements to the two blocks above this
            // one, which arrived after it and said "every scene" and "every
            // chapter". Recency is not the mechanism — the blocks above now
            // name their own exception, and this block outranks them in words
            // — but an instruction about the first thirty seconds should not
            // be the furthest thing from the request that follows it.
            $rehook,
            sprintf(
                "Target %s words of narration across the whole act. Return:\n"
                ."- chapters: the act, in order, as the chapters described above. Each has:\n"
                ."    - title: 2-6 words, at most %d characters. A YouTube chapter title: it marks a "
                ."stage without giving away what comes. No numbering.\n"
                ."    - rehook_line: the chapter's RE-HOOK, quoted back exactly — the first "
                ."sentence of the chapter proper. Never the spoken chapter number: that is the "
                ."announcement, not the re-hook, and a chapter whose recorded opening line is "
                ."\"Chapter four.\" has no re-hook on record at all.\n"
                ."    - text: that chapter's narration, first person, continuous prose. Every "
                ."chapter's text ends on a complete sentence.\n"
                ."- summary: FIVE SENTENCES, ONE EACH, in this order and nothing else:\n"
                ."    (1) what happened in this act;\n"
                ."    (2) what was said that matters, with the one line quoted;\n"
                ."    (3) what it cost, and to whom;\n"
                ."    (4) where things stand at the end;\n"
                ."    (5) the one thing the next act must not contradict.\n"
                ."  FIVE SENTENCES, NOT FIVE PARAGRAPHS. This is the only thing the next call "
                .'will know about this act, so every sentence carries facts — names, amounts, '
                .'dates, the quoted line — and none of them re-tells the act. At most %s '
                .'characters. Three of the last twelve acts broke that bound by writing '
                .'paragraphs where sentences were asked for; a summary over it is refused, the '
                .'call is billed in full, and the act is written again from nothing.',
                number_format($targetWords),
                Chapter::TITLE_MAX_CHARS,
                // The bound the form and the Action enforce, stated to the
                // writer rather than assumed. Not a schema constraint:
                // structured outputs do not honour maxLength. See
                // Act::SUMMARY_MAX_CHARS.
                number_format(Act::SUMMARY_MAX_CHARS),
            ),
        ]));
    }

    /**
     * What an act is told about the chapters it comes back as.
     *
     * -----------------------------------------------------------------------
     * THE MEASUREMENT
     * -----------------------------------------------------------------------
     *
     * A working video in this niche runs fourteen chapters in 34:46, about
     * 2:29 each, with a re-hook at every one. Ours ran six acts of 5:54 to
     * 9:44, so the first re-hook after the opening landed at 6:45 to 7:47 —
     * on a format whose one measured failure is people leaving inside the
     * first three minutes.
     *
     * The act stays the unit this call writes, because the writer returns
     * ~1,100 words of it whatever it is asked and fourteen acts of that is a
     * 75-minute video. The chapter goes UNDER it: two or three per act, so
     * the act's natural length is the thing that fits rather than the thing
     * being fought. See config/chapters.php.
     *
     * The budgets go through the story's own frozen sizing rate, the same
     * one the act's word target came from. Two rates in one prompt is two
     * beliefs about one narration inside a single string.
     *
     * -----------------------------------------------------------------------
     * THE COUNT IS DERIVED BY THE WRITER, NOT STATED TO IT, AND THAT REVERSES
     * THE FIRST VERSION
     * -----------------------------------------------------------------------
     *
     * This used to say "at this act's length that is N chapters", with N
     * computed from the act's word TARGET. Story 30 came back as two chapters
     * per act every time — including the act that ran to 1,195 words, where
     * the honest answer was three — because the writer obeyed the stated
     * number rather than the length it had just written.
     *
     * That is the word-target finding with the sign flipped, and the pair is
     * what makes it worth reading as a rule. A stated FIGURE steers weakly:
     * the act word target moves the writer by about 0.30 words per word
     * asked, so a hundred more words buys thirty. A stated COUNT steers
     * absolutely: it was obeyed 6 times out of 6, at every act length from
     * 1,052 to 1,195 words. **The difference is not that one number is more
     * important. It is that a count is discrete and a writer can satisfy it
     * exactly, so it stops being advice and becomes an instruction.**
     *
     * So the prompt states the DIVISOR and the bounds and asks for the
     * division to be done afterwards, against the text that exists. The
     * worked example is anchored on `naturalActWords()` — what the writer is
     * measured to produce — rather than on the target, because an example
     * built from the target would be the stated count again wearing a
     * different hat.
     *
     * The divisor alone would not have moved anything, and that is recorded
     * in config/chapters.php rather than here: at the old 150-second chapter
     * budget a 1,123-word act divides into 2.25 and rounds to two, so an
     * honest derivation returns exactly the number the stated one did. The
     * budget moved to the transcript's measured 133 seconds in the same
     * change, and only the two together change the cadence.
     */
    private function chapterInstruction(Story $story, ActOutline $act): string
    {
        $chapterWords = ScriptSizing::chapterTargetWords($story);
        $min = (int) config('chapters.min_per_act', 2);
        $max = (int) config('chapters.max_per_act', 4);
        $minWords = (int) config('chapters.min_words', 150);

        // The worked example is anchored to what the writer is MEASURED to
        // produce, not to what it was asked for. That is the whole point of
        // the change: see the docblock.
        $natural = ScriptSizing::naturalActWords();
        $naturalChapters = ScriptSizing::chaptersPerAct($story, $natural);

        // The reference speaks its chapters as a bare number — "chapter 1",
        // "chapter 2" — with no title, and the cold open comes BEFORE
        // "chapter 1" (0:00-1:02, then the announcement). So the number is
        // narration and the title is metadata.
        $first = $this->firstChapterNumberFor($story, $act);

        $announce = ChapterAnnouncement::enabled()
            ? sprintf(
                ' EVERY CHAPTER OPENS BY SPEAKING ITS NUMBER, as narration, as its own sentence — '
                .'"%s" — the number in words, no title — and THEN that chapter\'s re-hook '
                .'sentence. Chapters are numbered across the whole video, not within the act: '
                .'this act\'s first chapter is chapter %d and they count upward from there, one '
                .'number per chapter you write. The title is never spoken; it goes to the chapter '
                .'list.%s',
                ChapterAnnouncement::sentenceFor($first),
                $first,
                // Act 1 does NOT get the rule restated here. It gets a pointer
                // to the one block that owns its opening order, because act 1
                // is where three instructions collide and the fix was to give
                // them a single owner rather than a copy each. See
                // hookInstruction().
                $act->sequence === 1
                    ? ' THIS ACT IS THE EXCEPTION and the OPENING block below sets its order: the '
                        .'hook is the cold open and is spoken before any chapter number, so '
                        .'"Chapter one." comes after it. Every later chapter in this act opens '
                        .'with its number as normal.'
                    : '',
            )
            : ' The title is not spoken: it goes to the chapter list, not the narration.';

        return sprintf(
            'WRITE THIS ACT AS CHAPTERS. A chapter is about %d seconds of narration — roughly %s '
            .'words — and it is the unit the viewer experiences: a title in the progress bar and, '
            ."more importantly, a fresh re-hook.\n\n"
            .'HOW MANY CHAPTERS IS DECIDED BY THE LENGTH YOU ACTUALLY WRITE, not by the word '
            .'target: divide the words you wrote by %s and round. Acts come back longer than they '
            .'are asked for — the measured length is about %s words, and %s words is %d chapters, '
            .'not %d. Count yours the same way, after you have written it. Never fewer than %d and '
            .'never more than %d, and no chapter under %d words. Break where the ground shifts — a '
            ."new room, a new indignity, a new person — never mid-scene.\n\n"
            .'EVERY CHAPTER OPENS WITH ITS OWN RE-HOOK: the first sentence of the chapter proper '
            .'gives someone about to close the tab a reason not to. The next indignity already in '
            .'progress, a line somebody said, a number. Not a recap of the chapter before it.%s',
            (int) config('chapters.target_seconds', 133),
            number_format($chapterWords),
            number_format($chapterWords),
            number_format($natural),
            number_format($natural),
            $naturalChapters,
            max($min, $naturalChapters - 1),
            $min,
            $max,
            $minWords,
            $announce,
        );
    }

    /**
     * The story-wide number of this act's first chapter: one more than the
     * chapters already stored on the acts before it.
     *
     * Read from the rows rather than projected, so a rewritten act 4 numbers
     * from what acts 1-3 actually returned. The known limit: rewriting an
     * early act to a different chapter count shifts the spoken numbers of
     * every act after it, which `story:write --acts-only` does not re-write.
     * A story rewritten act by act should be rewritten from the changed act
     * onward, and `story:write` says so in its report.
     */
    private function firstChapterNumberFor(Story $story, ActOutline $act): int
    {
        $earlierActIds = $story->acts()->where('sequence', '<', $act->sequence)->pluck('id');

        return 1 + Chapter::query()->whereIn('act_id', $earlierActIds)->count();
    }

    /**
     * What an act is told about WHEN it is set.
     *
     * The measurement behind it is in ActTimeframe: story 28's present-day
     * betrayal lands at 20:18 because two of its three escalation acts stage
     * 2015 and 2017 in full, and story 23's at 15:01 for the same reason. The
     * outline declares each act present or prior now and Gate 1 refuses a
     * prior escalation act, so what reaches this call is an act the outline
     * says is present — and this is the instruction that keeps it there,
     * because the act system prompt's "name the dates, the rooms, the exact
     * words" is exactly the instruction that stages a year-old banquet given
     * the chance.
     *
     * Story 25 is the model and is quoted rather than described: the
     * antagonist's line is quoted in the hook and STAGED at a present-day
     * dinner, so the line still lands twice without an act going to history.
     *
     * Silent for a null timeframe. Every act outlined before the field
     * existed is null, those outlines are not regenerated, and an act being
     * re-written one at a time on one of them should get the shape its
     * outline was built to — the same reason `endingFor()` keeps its no-phase
     * branch.
     */
    private function timeframeInstruction(ActOutline $act): string
    {
        return match ($act->timeframe) {
            ActTimeframe::Present => 'THIS ACT IS SET IN THE STORY\'S PRESENT. Anything that happened '
                .'years earlier — the first time, the year the flat was bought, what was said at a '
                .'banquet back then — is CITED in one sentence with its date, and not staged: no '
                .'scene from that day, no dialogue from it, no room described. If the antagonist '
                .'said the line years ago, quote it in one sentence and stage its most recent '
                .'saying, now, in a room this act is in. Story 25 quotes "a wife who earns more" '
                .'in its first thirty seconds and stages it at a present-day dinner in act 2; that '
                .'is the shape. Two acts spent staging 2015 and 2017 is what put story 28\'s '
                .'present-day betrayal at minute twenty.',
            ActTimeframe::Prior => 'This act is marked as set BEFORE the story\'s present. That is '
                .'refused at Gate 1 and should not have reached you; write it as a present-day act '
                .'that cites the earlier incident in one sentence.',
            null => '',
        };
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
     * sentence of bait, once in the betrayal scene straight after the hook,
     * said aloud in the room — and the second landing is stronger for the
     * first. A generator told to "use it in the hook" spends it and leaves the
     * act paraphrasing itself, so the instruction says so in both directions.
     *
     * -----------------------------------------------------------------------
     * CHAPTER ONE IS THE BETRAYAL SCENE
     * -----------------------------------------------------------------------
     *
     * This block used to send the second landing to "act 2 or 3", and stories
     * 29-32 obeyed exactly: one line in the hook, the first public saying at a
     * banquet at 9-11 minutes, the betrayal itself at a kitchen table. The
     * reference stages it at 1:31, directly after its 62-second cold open. So
     * `stories.betrayal_scene` is handed to act 1 here, inside the block that
     * owns act 1's opening order, rather than as a fifth block that would be
     * one more instruction for the other three to collide with. It follows
     * the chapter announcement and the re-hook, and the answer-back is back in
     * force inside it — which is what beat 4 now says from the other side:
     * a round the narrator loses is not the reckoning.
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
            'THE OPENING. THIS ACT OPENS THE VIDEO, and its opening is the highest-leverage text '
            .'in the whole script. Three other instructions in this prompt touch it and THIS BLOCK '
            ."OUTRANKS ALL OF THEM for as long as the five beats last:\n\n"
            .'- The narrator answers back in every scene the antagonist is in. NOT HERE. Beat 4 is '
            .'the narrator\'s move in the opening and it is a cold action, not a line — the '
            .'answer-back begins at the first scene AFTER the beats.'
            ."\n"
            .'- Every chapter opens by speaking its number. NOT THIS ONE. The hook is the cold '
            .'open and comes first; "Chapter one." is spoken after beat 5, where the story proper '
            .'begins.'
            ."\n"
            .'- Every chapter opens with a re-hook. The five beats ARE this chapter\'s opening; its '
            ."re-hook line is the first sentence after the chapter number.\n\n"
            ."Five beats, in this order, before anything else happens:\n\n"
            ."1. ONE sentence of setup. One. Do not write a second, and never write a sentence "
            ."about the video itself.\n"
            .'2. The betrayal itself, inside the first %s words. Not its aftermath, not a summary '
            .'of how it turned out, not a line about what the family said afterwards — the thing '
            ."that was done, being done, in the room it happened in.\n"
            .'3. Evidence in EXACT WORDS: one line of dialogue, a message or a document, quoted. '
            .'The strongest version is the antagonist\'s own justification, said in her words. '
            .'THE BETRAYAL SCENE KEEPS IT — this genre plays that line twice, once here as bait in '
            .'a single sentence and again straight after the hook, in chapter one, said aloud in '
            .'the room with everyone watching, and the second landing is stronger for the first. '
            ."Quote it here; do not exhaust it here.\n"
            .'4. ONE small, cold action by the narrator. Not a confrontation, not a speech, not a '
            .'threat: something quiet and exact that the audience understands and the antagonist '
            .'does not. The RECKONING is the final act and spending it here spends the video. A '
            .'round the narrator loses is not the reckoning — chapter one is one, and the narrator '
            ."answers back in it.\n"
            .'5. A closing line that promises the DEPARTURE — that the narrator will be gone and '
            .'somebody will have to look for them. NOT revenge, NOT the courtroom, NOT the '
            ."exposure. Those are the payoff and this is the promise, and they are not the same.\n\n"
            .'Do not open with weather, a childhood memory, a house, a room, a date, or the '
            .'chronological beginning of events. The beginning in time is almost never the '
            ."beginning of the video.\n\n"
            .'THEN, AND ONLY THEN, THE VIDEO STARTS: %s as its own sentence, then this chapter\'s '
            .'re-hook line, then the act. From that sentence onward every other rule in this '
            .'prompt is back in force, the answer-back included.',
            number_format($words),
            ChapterAnnouncement::enabled()
                ? '"'.ChapterAnnouncement::sentenceFor(1).'"'
                : 'the act proper',
        );

        // Chapter one, when the outline wrote one. Between the beats and the
        // stored hook, so the hook keeps the closing position story 30 showed
        // it needs. Absent on every story outlined before the field existed,
        // which gets exactly the block it had.
        $betrayal = trim((string) $story->betrayal_scene);

        if ($betrayal !== '') {
            $beats .= "\n\n".'CHAPTER ONE IS THE BETRAYAL SCENE. Straight after the chapter number and '
                .'its re-hook, write this scene in full, in the room, as it happens. It is where the '
                .'antagonist\'s justification is FIRST SAID ALOUD, to the narrator\'s face, in front of '
                ."everyone in it:\n\n".$betrayal."\n\n"
                .'The person it is done with is in the room for all of it; they do not need a line. '
                .'Name the witnesses, and give one of them the question that makes her say it. She '
                .'says the justification in her own words from the spine, not a paraphrase of it. '
                .'The answer-back is back in force here: what the narrator SAYS is short, controlled '
                .'and exact, and the crude version stays in their head, as narration. The narrator '
                .'still loses the round. Do not cut to a later day and do not summarise what was '
                .'said: stage it in full, through her saying it and the narrator losing the round. '
                // "The scene is the chapter" closed this block on story 33's
                // first act-1 call, and the writer returned ONE chapter of 479
                // words that stopped before the antagonist entered the room —
                // 1,449 output tokens against 3,400-7,700 on every act of 31
                // and 32 — with a summary calling the act "the chapter". One
                // observation, and the sentence plainly allowed that reading:
                // it said the act was one chapter, beside a block saying 2-4.
                .'THE SCENE IS CHAPTER ONE, NOT THE WHOLE ACT. When it ends, the act goes on into its '
                .'further chapters, at its full length, as the chapter instructions above say.';
        }

        // -------------------------------------------------------------------
        // THE STORED HOOK GOES LAST, AND IT IS AN ORDER RATHER THAN A NOTE
        // -------------------------------------------------------------------
        //
        // `stories.hook` is the paragraph the outline wrote to answer the five
        // beats, and story 29's act 1 opened on it verbatim. Story 30's did
        // not: it opened mid-scene on the betrayal and invented its own third
        // and fourth beats. The text was in the prompt both times, in the same
        // place — trailing the beats as "the hook this outline asks for",
        // which reads as a reference rather than as the thing to write.
        //
        // So it closes the block, in the position the answer-back and the
        // chapter announcement used to occupy, and it says what to do with it.
        return $hook === ''
            ? $beats
            : $beats."\n\nTHIS IS THE OPENING THE OUTLINE WROTE FOR THIS STORY. WRITE IT — expand "
                ."it into the five beats above, keeping its facts, its quoted line and its closing "
                ."promise. Do not replace it with an opening of your own:\n\n".$hook;
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
                .'does not come out here and the narrator does not leave here. Nothing is recovered: '
                .'no cost comes back, no real apology arrives, no ally fixes anything, and the act '
                .'ends worse off for the narrator than it started. AND THE NARRATOR ANSWERS BACK. In '
                .'every scene the antagonist is in, the narrator says one short, exact, funny line '
                .'that lands — not a speech, not "all right", not "Yes, Mother". The line changes '
                .'nothing about what the act costs; that is the point. The exchange is won and the '
                .'round is lost.'
                // The one place this rule does not reach, said here as well as
                // in the block that owns it. Story 30's act 1 spent beat 4 —
                // the cold action — on an answer-back, because this sentence
                // said "every scene" and arrived after the beats did.
                .($act->sequence === 1
                    ? ' THE EXCEPTION IS THE OPENING OF THIS ACT: inside the five beats of the hook '
                        .'the narrator does not answer back, because beat 4 is a cold action and a '
                        .'confrontation there spends the video. The answer-back starts at the first '
                        .'scene after the hook and runs to the end of the act.'
                    : ''),

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
                .'costs her more than the last attempt did. PUT THEM IN THE SAME SCENE AT LEAST ONCE '
                .'IN THIS ACT: she runs into them, follows them, sits down across from them, turns up '
                .'where they are — on her initiative or by chance, in front of people — and the '
                .'meeting costs her, in public. The narrator answers in one short, exact, funny line '
                .'and leaves. They do not go back, do not explain, do not search for her, do not send '
                .'a message. The narrator\'s own life is ON SCREEN in this act, not a paragraph: what '
                .'they are doing, who they are with, what the days look like — because a narrator the '
                .'audience cannot see is a narrator the antagonist is not losing to. She may learn '
                .'where they are; if she does, the finding costs her and the scene is still the '
                .'narrator\'s. End the act with her worse off than she started it.',

            ActPhase::Refusal => 'THIS IS THE FINAL ACT. Both payoffs land here, in this '
                ."order.\n\nFIRST, the exposure — the public one:\n\n"
                .$story->exposure_moment."\n\n"
                .'The withheld information comes out here and nowhere earlier. Put the witnesses in '
                .'the room and name them. The antagonist repeats their justification in front of '
                ."people who now know it is false.\n\n"
                .'THE NARRATOR IS IN THE ROOM FOR IT, by their own choice, and the audience is there '
                ."with them — this is a scene the narrator lives, not a report they receive later:\n\n"
                .(trim((string) $story->narrator_at_exposure) !== ''
                    ? $story->narrator_at_exposure
                    : 'They arrive unexpected and uninvited, having chosen this moment, and they '
                        .'produce the withheld information themselves.')
                ."\n\n"
                .'Whether they chose the moment or she came to where they are, the narrator is in '
                .'the room and puts the thing only they can produce on the table in person. Do not '
                .'have a document, a lawyer or a friend do it while the narrator is eight hundred '
                .'kilometers away hearing about it afterwards; story 25\'s narrator raises his hand '
                ."at the back of the room in a work jacket, and that is the shape.\n\n"
                ."THEN the refusal — the private one, and the thing the audience has waited the "
                ."whole video for:\n\n"
                .$story->refusal."\n\n"
                .'She reaches them afterwards. She asks them to come back. The narrator decides where '
                .'and how long they talk, says no, and each refusal answers ONE specific earlier '
                .'humiliation in the words it was done in — hand her own sentence back to her, and the '
                .'sentence she said aloud in the betrayal scene is the strongest one to hand back. The '
                .'strongest refusal names a loss she can no longer repair. The narrator does not '
                .'retaliate, gloat, or explain the moral. The last refusal lands and the narrator walks '
                .'away.'
                // "No epilogue" was here, and it was derived from a reference
                // TITLE. The transcript closes on one: after the last refusal
                // and the walk away, "chapter 14 — One year later", the
                // narrator's own wedding, and "Sophia was the only one who
                // didn't show up." CLAUDE.md 3d recorded the correction and
                // this sentence was never changed. The two point-of-view
                // extras after it are NOT asked for: they are a second and
                // third narrator, and the genre's first person is one voice.
                ."\n\nTHEN, IF IT EARNS ITS PLACE, A SHORT EPILOGUE — a few paragraphs, not a chapter "
                .'of reflection. A time jump in the narrator\'s own voice, a year or so later: what '
                .'the narrator\'s life is now, and one concrete fact that shows her loss from the '
                .'outside. The reference\'s whole epilogue turns on one sentence — a year later, at the '
                .'narrator\'s wedding, "Among all our mutual friends Sophia was the only one who didn\'t '
                .'show up." That is its job. No moral, no account of what everyone learned, no '
                .'ambiguity, no "I still think about it sometimes", and no chapter told from anybody '
                .'else\'s point of view.',

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
                'search' => 'the antagonist is looking for them and reaching them, and every meeting is costing her',
                'refusal' => 'the narrator is in the room for the exposure, and says no',
                default => 'unstated',
            });
        }

        // When the act is set. A present-day act's one-sentence citation of
        // an earlier incident is a cutaway at most, never a staged flashback
        // scene — and the scene writer is the stage that would draw one.
        if ($act->timeframe !== null) {
            $lines[] = 'SET IN: '.match ($act->timeframe) {
                ActTimeframe::Present => 'the story\'s present. A sentence citing an earlier '
                    .'incident is at most one cutaway still; it is not a scene from that year.',
                ActTimeframe::Prior => 'the past, before the story\'s present.',
            };
        }

        foreach ([
            'COSTS' => $act->escalation_beat,
            'THE GRIEVANCE' => $story->narrator_grievance,
            'THE ANTAGONIST BELIEVES' => $story->antagonist_justification,
            // Act 1 only: it is the act the scene is in. This is the call that
            // decides who is IN the frame, and a betrayal scene drawn as two
            // people at a table is the discovery again, in pictures — the
            // person it is done with and the witnesses have to be drawn.
            'THE BETRAYAL SCENE (who is in the room)' => $act->sequence === 1 ? $story->betrayal_scene : '',
        ] as $label => $value) {
            if (trim((string) $value) !== '') {
                $lines[] = $label.': '.trim((string) $value);
            }
        }

        // The chapter boundaries, read off the act the way the phase and the
        // beat are — the consumer question asked in the change that added the
        // field. A chapter starts at a sentence, a scene is a sentence range,
        // and a scene straddling the boundary puts the previous chapter's
        // still under the new chapter's opening line.
        $boundaries = $act->chapters
            ->filter(fn (Chapter $chapter): bool => $chapter->first_sentence > 1)
            ->map(fn (Chapter $chapter): string => sprintf(
                'sentence %d ("%s")',
                $chapter->first_sentence,
                $chapter->title,
            ))
            ->all();

        if ($boundaries !== []) {
            $lines[] = 'CHAPTER BOUNDARIES: a new chapter begins at '.implode(', ', $boundaries).'. '
                .'A scene NEVER straddles one — end a scene on the sentence before, start the next '
                .'scene on that sentence.';
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

        // The same number DraftScenes keeps per act. Asking for two and keeping
        // two is one decision; asking for two and keeping six story-wide was
        // the pool that never reached the refusal phase.
        $thumbnailsPerAct = (int) config('scenes.thumbnail_candidates.per_act');

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
            .'- frame: the composed shot. What is in the picture, not what the line says. '
            ."25-45 words, at most %d characters.\n"
            ."- characters_present: names from the cast who are VISIBLE. Empty if nobody is.\n"
            ."- motion_preset: zoom_in, zoom_out, pan_left, pan_right or static.\n"
            .'- expression: what the faces are DOING, named plainly. Empty ONLY if nobody is '
            ."in the frame. At most %d characters.\n"
            .'- thumbnail_candidate: true for at most %d scene(s) in this act — the ones that '
            .'would stop someone scrolling. A face mid-reaction, close enough to read at '
            .'thumbnail size. Never a frame with nobody in it — cutaways belong in the video '
            ."and never on the thumbnail — and never a wide establishing shot.\n\n"
            ."THE SCRIPT (%d sentences):\n\n%s",
            $act->sequence,
            $act->title,
            $this->sceneContext($story, $act),
            $targetScenes,
            $total,
            $total,
            $wordsPerScene,
            // The bounds Gate 2's editor validates the two authored sections
            // against, and DraftScenes enforces after the call. Not schema
            // constraints — structured outputs do not honour maxLength.
            Scene::FRAME_MAX_CHARS,
            Scene::EXPRESSION_MAX_CHARS,
            $thumbnailsPerAct,
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

                // The betrayal as a scene. Required because it is awkward in
                // the way a premise makes it awkward: most premises have the
                // betrayal FOUND — photos, a booking, a message — and the path
                // of least resistance is to stage the finding. Seven stories
                // took it. See the migration.
                'betrayal_scene' => ['type' => 'string'],

                'withheld_information' => ['type' => 'string'],
                'exposure_moment' => ['type' => 'string'],

                // How the narrator comes to be in the room for the exposure.
                // Required because it is the awkward one in the most
                // expensive way: the departure says the narrator is gone, so
                // the path of least resistance is an exposure they hear about
                // later. Two of three phase stories took it, and their public
                // payoff is a report — see the migration.
                'narrator_at_exposure' => ['type' => 'string'],

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
                            // Declared per act, because a model asked in
                            // prose to keep every act in the present would
                            // keep most of them; asked to SAY where each act
                            // sits, it says "prior" for the one that is, and
                            // Gate 1 can refuse before the act is bought.
                            'timeframe' => ['type' => 'string', 'enum' => ['present', 'prior']],
                        ],
                        'required' => ['title', 'summary', 'escalation_beat', 'timeframe'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => [
                'title',
                'hook',
                'narrator_grievance',
                'antagonist_justification',
                'betrayal_scene',
                'withheld_information',
                'exposure_moment',
                'narrator_at_exposure',
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
                // The act AS chapters. Required as an array of objects rather
                // than asked for as headings in one prose string, for the
                // reason `expression` is a scene field: a shape the model has
                // to fill is honoured where a prose instruction is honoured
                // most of the time. No minItems/maxItems — structured outputs
                // reject any minItems other than 0 or 1 — so the count is
                // enforced against the decoded response in GenerateActScripts,
                // after the cost row, beside the summary bound.
                'chapters' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'rehook_line' => ['type' => 'string'],
                            'text' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'rehook_line', 'text'],
                        'additionalProperties' => false,
                    ],
                ],
                // No maxLength, deliberately: structured outputs do not honour
                // string constraints, so a bound written here would be either
                // rejected or ignored — and ignored is the documented-guard
                // shape, a limit that reads as enforced and is not. The bound
                // (Act::SUMMARY_MAX_CHARS) is stated in the prompt and checked
                // against the decoded response in GenerateActScripts, after the
                // cost row, the way the outline's act count is. A test asserts
                // this schema carries no maxLength so nobody "fixes" it in.
                'summary' => ['type' => 'string'],
            ],
            'required' => ['chapters', 'summary'],
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
