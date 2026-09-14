<?php

namespace App\Services\Fake;

use App\Contracts\ScriptWriter;
use App\Enums\ActPhase;
use App\Enums\ActTimeframe;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Enums\StoryFormat;
use App\Models\Act;
use App\Models\Chapter;
use App\Models\Story;
use App\Support\ChapterAnnouncement;
use App\Support\Providers\ActOutline;
use App\Support\Providers\ActScriptDraft;
use App\Support\Providers\ChapterDraft;
use App\Support\Providers\CharacterCast;
use App\Support\Providers\CharacterProfile;
use App\Support\Providers\OutlineDraft;
use App\Support\Providers\ProviderUsage;
use App\Support\Providers\SceneDraft;
use App\Support\Providers\SceneDraftSet;
use App\Support\Providers\ScriptWriterException;

/**
 * A script writer that never touches the network.
 *
 * Tests never hit the network, so this is what every test in the suite runs
 * against. Three things make it useful rather than merely present:
 *
 *  1. **It produces the right SHAPE and the right SIZE.** Acts come back at
 *     roughly the requested word count, in US English, with a summary and a
 *     rehook line. A fake that returned "lorem ipsum" would let a bug through
 *     in anything downstream that reasons about length — and in this format
 *     almost everything does, because runtime is the product.
 *
 *  2. **It is in the right GENRE.** First person, a named antagonist with a
 *     self-justifying excuse, a distinct escalation beat per act, and an
 *     exposure with witnesses in it. The genre check at Gate 1 runs over
 *     whatever this returns, so a fake written as literary fiction would let a
 *     broken validator pass — and letting a broken validator pass is how the
 *     first version of this pipeline produced thirty-five minutes of nothing.
 *
 *  3. **It records what it was asked.** `$calls` captures every request in
 *     order, which is how the sequential-generation rule is tested: act 4 must
 *     have been given the summaries of acts 1-3, and a fan-out implementation
 *     would fail that assertion rather than silently producing drift nobody
 *     notices until a video is watched.
 *
 * Cost is recorded as zero but still recorded, so a fixture run exercises the
 * same cost path as a real one. "This cost nothing" and "nobody wrote a cost
 * row" must not look the same.
 */
class FakeScriptWriter implements ScriptWriter
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    /** Text the next act will contain, for testing the locale denylist. */
    public ?string $injectIntoScript = null;

    /**
     * Act sequence that should throw instead of returning a script.
     *
     * The partial failure this stage actually has. Acts are written in order
     * and cannot fan out, so the real shape of a bad run is "acts 1-3 written
     * and billed, act 4 dead" — and everything that reports on that run has to
     * be able to say which act, which needs a way to produce one.
     */
    public ?int $failOnAct = null;

    /** Text the next outline will contain, same reason. */
    public ?string $injectIntoOutline = null;

    /** Text the next cast will contain, same reason. */
    public ?string $injectIntoCharacters = null;

    /** How many further extraction attempts should come back with a prop in style_notes. */
    public int $dirtyStyleNotesForAttempts = 0;

    /** Text the next batch of frames will contain, same reason. */
    public ?string $injectIntoScenes = null;

    /**
     * Force a specific set of sentence ranges for the next act.
     *
     * fn (int $total): array<int, array{0: int, 1: int}>
     *
     * Exists to produce the splits a real generator occasionally produces and
     * that must never reach the database: a gap that silently drops narration
     * out of the video, or a run that stops short of the end of the act. Both
     * are invisible downstream — every scene still has text and the render
     * still succeeds — so DraftScenes has to reject them, and a test needs a
     * way to hand it one.
     */
    public ?\Closure $sceneRangeOverride = null;

    /** Force an unusable motion preset, to exercise the fallback rotation. */
    public ?string $motionOverride = null;

    /** Flag nothing as a thumbnail, so the hook has to become the default. */
    public bool $suppressThumbnails = false;

    /**
     * Nominate this many scenes per act (the 2nd, 3rd, ... of each act),
     * instead of the default one.
     *
     * Exists so a test can hand DraftScenes MORE nominations than the per-act
     * cap in EVERY act. The story-wide cap this replaced was green through
     * every test in the suite because the fake nominated one scene per act and
     * the fixture has three acts — three nominations against a cap of six can
     * never overflow, so the front-loading was inexpressible.
     */
    public ?int $thumbnailsPerAct = null;

    /**
     * One frame for every scene, replacing the rotation.
     *
     * Exists so a test can put the drafter in front of a KNOWN shot scale. The
     * expression is suppressed on a wide establishing shot with no face in it,
     * and that branch is unreachable through the fixed rotation — which is the
     * "a fixture that cannot express the failing state" problem, so the fixture
     * is given a way to express it rather than the branch left undrilled.
     */
    public ?string $frameOverride = null;

    /**
     * Names to put in every scene's `charactersPresent`, instead of the cast.
     *
     * The fake names characters by their exact stored name, so every frame it
     * produces resolves on the exact path and the matcher's fallback is never
     * reached. That is right for the ordinary fixture and it means the suite
     * could not express a frame naming somebody ambiguously — which is the
     * state that made "Lu" return Lu Jianguo on a story whose Lu Wenbin carries
     * 105 scenes.
     *
     * A fixture that cannot describe the failing state makes every assertion
     * about it vacuous however carefully it is written, so this exists to
     * describe it.
     *
     * @var array<int, string>|null
     */
    public ?array $charactersPresentOverride = null;

    /** One expression for every scene, replacing the rotation. */
    public ?string $expressionOverride = null;

    /**
     * How many chapters the next act comes back as, instead of the default
     * two. Exists so a test can hand GenerateActScripts a count outside the
     * bound and a chapter under the word floor — the shapes the prompt says
     * are not allowed and the Action refuses after the cost row.
     */
    public ?int $chaptersForNextAct = null;

    /** Leave the re-hook off every chapter of the next act. */
    public bool $suppressChapterRehooks = false;

    /**
     * Leave the SPOKEN NUMBER off every chapter of the next act.
     *
     * The state story 30's acts 1 and 4 came back in — chapters present,
     * titled and sequenced, with no announcement anywhere in the prose. It
     * is a toggle rather than the default because the default has to be what
     * the contract asks for: a fake whose chapters never announced would
     * make `ValidateOutlineSpine::checkChapterAnnouncements()` report every
     * fixture story in the suite, which is how a detector stops being read.
     */
    public bool $suppressChapterNumbers = false;

    public function outline(Story $story, int $actCount): OutlineDraft
    {
        $this->calls[] = ['method' => 'outline', 'story_id' => $story->id, 'act_count' => $actCount];

        // The same arithmetic the real writer uses, so a test that asserts the
        // act structure is asserting the structure production gets.
        $plan = $story->format === StoryFormat::Anthology ? [] : ActPhase::planFor($actCount);

        $acts = [];

        for ($i = 1; $i <= $actCount; $i++) {
            $phase = $plan[$i] ?? null;

            $acts[] = new ActOutline(
                sequence: $i,
                title: self::TITLES[($i - 1) % count(self::TITLES)],
                summary: sprintf(
                    'My sister Dana told the family I had agreed to cover the %s, which I had not. '
                    .'When I said so at dinner in %s, my mother asked me to keep the peace. I paid '
                    .'it and said nothing. %s',
                    $i % 2 === 0 ? 'catering deposit' : 'venue balance',
                    self::TOWNS[($i - 1) % count(self::TOWNS)],
                    $this->injectIntoOutline ?? ''
                ),
                // Distinct per act, and escalating. ValidateOutlineSpine flags
                // two acts that claim the same beat, so a fake that repeated
                // one would fail the genre check it exists to exercise.
                //
                // It also has to change DIRECTION at the departure: after the
                // narrator leaves, the beat names what the attempt costs the
                // antagonist. A fake that escalated against the narrator to the
                // last act would model the exact video this phase was added to
                // stop the pipeline producing.
                escalationBeat: $this->beatFor($i, $phase),
                phase: $phase,
                // Present on every act, because that is the outline Gate 1
                // accepts and the one the real writer is told to produce. A
                // test that wants the refused shape sets `prior` on the row.
                // Null on an anthology act, which has no present to be set in.
                timeframe: $phase === null ? null : ActTimeframe::Present,
            );
        }

        $this->injectIntoOutline = null;

        return new OutlineDraft(
            // The ending stated in the title. This genre does not withhold —
            // the promise of the payoff is the hook.
            title: 'My Sister Billed Me For Her Wedding - So At The Reception I Read Out The Receipts',
            acts: $acts,
            usage: ProviderUsage::free('fake', 'generate_outline', CostCategory::Text),
            // The five beats, in order, because a fake that merely filled the
            // column would let a healthy outline pass the hook check without
            // the check ever having something hook-shaped to look at.
            //
            // The last sentence is the one Gate 1 measures, and it is written
            // to promise the DEPARTURE in the departure's own words — moved,
            // apartment, address, reception. A closing line about the
            // reception's receipts would promise the exposure instead, which is
            // the mismatch the check exists for, and is what the RED half of
            // GuardsGoRedTest's pair is built from.
            hook: 'My older sister Dana got married in June and I paid for all of it. '
                .'The first invoice arrived eleven days after she asked me to stand up with her, '
                .'and there were nine more behind it, and I had never once said that I would pay. '
                .'When I said as much on the phone she told me, "You have no kids and no mortgage, '
                .'and family helps family." I opened a spreadsheet that night and named it DANA '
                .'WEDDING, and I kept it for eleven months without telling a single person. '
                .'Two weeks after the reception I moved out of the apartment and left no address, '
                .'and Dana did not find out that I was gone for three weeks.',
            narratorGrievance: 'My older sister Dana told our whole family I had promised to pay for '
                .'her wedding, then billed me for it piece by piece over eleven months, and every time '
                .'I said I had not agreed she told me I was embarrassing her.',
            // Self-justified, not cartoonish. Contains no confession marker, so
            // it passes the check the real generator has to pass.
            antagonistJustification: 'Dana says I am the one with no kids and no mortgage, that she '
                .'gave up her twenties looking after our mother while I was away at school, and that '
                .'family helps family. She believes every word of it, and so does our mother.',
            // The betrayal DONE, in front of people, and every part Gate 1
            // reads for: an audience (both families, twenty relatives), the
            // justification said aloud in its own words (no kids, no
            // mortgage, family helps family), the person it is done for in the
            // room and silent, a witness's question, and a line back that loses.
            // No discovery marker, so a healthy fixture raises nothing; the
            // RED halves in GuardsGoRedTest add one.
            betrayalScene: 'At Dana\'s engagement dinner, in front of both families and twenty '
                .'relatives, Dana stood up with her fiancé Mark beside her and announced that I had '
                .'agreed to pay the venue balance. Aunt Ruth asked when I had offered. Dana said it to '
                .'my face, to the whole room: I have no kids and no mortgage, and family helps family. '
                .'Mark looked at his plate and said nothing. I said I would be delighted to pay for a '
                .'wedding I had first heard about over the soup, our mother told me to keep the peace, '
                .'and the table laughed at me instead of at her.',
            withheldInformation: 'I had been paying our mother care home fees since March out of the '
                .'same account, and Dana had never once asked where that money was coming from.',
            // Witnesses named, because an exposure without them is a private
            // conversation and a much worse video.
            exposureMoment: 'At the reception, in front of eighty guests and both families, when Dana '
                .'stood up to thank everyone who had helped and named everyone except me.',
            // In the room, by choice, unexpected — and producing the thing
            // only the narrator can. Shares the withheld information's own
            // words (care home, fees, account), because that overlap is what
            // Gate 1 measures. This fixture's narrator comes uninvited; a
            // found narrator is the other legal shape and has its own test.
            narratorAtExposure: 'I come to the reception uninvited, having chosen the moment, and '
                .'when Dana names everyone but me I stand up with the care home fees printed out '
                .'and put eleven months of them on the table from the same account. She did not '
                .'find me. I came.',
            // Unannounced, because an announced departure cannot be searched
            // for and ValidateOutlineSpine flags one. The fake has to be in
            // the genre it is used to test, not merely the right shape.
            departure: 'I moved out of the apartment two weeks after the reception and did not say '
                .'where I was going. Nobody was given an address, no note was left on the table, and '
                .'my number changed the same afternoon. Dana found out that I was gone three weeks '
                .'later, from our mother, who had known and had said nothing.',
            // Two attempts, each costing her something named, escalating.
            reversalBeats: 'First she called every relative we have in common, and two of them '
                .'stopped taking her calls by the third week. Then she paid a man to look for me, '
                .'which was money she had spent a year telling everyone she did not have. Then she '
                .'went to our mother and begged for the address, and our mother asked her what she '
                .'thought the eleven months of payments had been.',
            // Answers the grievance in the grievance's own words, which is what
            // the refusal check looks for: shared, specific language.
            refusal: 'When Dana reached me in the parking lot after the reception, she asked me to come '
                .'back and help, because family helps family. I said her own sentence back to her and then '
                .'I said no. She had called me embarrassing at every dinner for eleven months; I told '
                .'her she was welcome to say it again, to anyone she liked, and I went back inside.',
            requestedActCount: $actCount,
        );
    }

    /**
     * One act's beat, in the direction its phase runs.
     *
     * @param  ActPhase|null  $phase  Null on an anthology act, which runs the
     *                                whole arc itself and escalates throughout.
     */
    private function beatFor(int $sequence, ?ActPhase $phase): string
    {
        return match ($phase) {
            ActPhase::Search => sprintf(
                'Act %d costs Dana %s, and leaves her with fewer people to ask than she started it with.',
                $sequence,
                self::SEARCH_COSTS[($sequence - 1) % count(self::SEARCH_COSTS)],
            ),
            ActPhase::Refusal => sprintf(
                'Act %d costs Dana the last relatives who believed her, in the room where she asked.',
                $sequence,
            ),
            default => sprintf(
                'Act %d costs me %s, in front of %d more people than the last time.',
                $sequence,
                self::COSTS[($sequence - 1) % count(self::COSTS)],
                $sequence * 4,
            ),
        };
    }

    public function actScript(
        Story $story,
        ActOutline $act,
        array $fullOutline,
        array $priorSummaries,
        int $targetWords,
    ): ActScriptDraft {
        // Recorded in order. The test that this stage stayed sequential reads
        // exactly this: act N must have arrived carrying N-1 prior summaries.
        $this->calls[] = [
            'method' => 'actScript',
            'story_id' => $story->id,
            'sequence' => $act->sequence,
            // Both recorded so a test can assert they ARRIVED. The beat was
            // required at outline, checked at Gate 1 and dropped on the way to
            // this call for two phases, and nothing could see it because the
            // fake never looked at what it was handed.
            'phase' => $act->phase?->value,
            'escalation_beat' => $act->escalationBeat,
            // Recorded in the same change that added the field, so the third
            // dropped-on-the-way field fails a test instead of shipping.
            'timeframe' => $act->timeframe?->value,
            'narrator_at_exposure' => $story->narrator_at_exposure,
            // Recorded at every act, because every act's prompt carries it:
            // act 1 stages it, and the later acts must not re-stage its first
            // saying or must hand it back.
            'betrayal_scene' => $story->betrayal_scene,
            'prior_summaries' => count($priorSummaries),
            'target_words' => $targetWords,
            'outline_size' => count($fullOutline),
        ];

        if ($this->failOnAct === $act->sequence) {
            throw new ScriptWriterException(
                "Act {$act->sequence} failed (fake). The acts before it are written and billed."
            );
        }

        $script = $this->prose($targetWords, $act->sequence);

        if ($this->injectIntoScript !== null) {
            $script .= ' '.$this->injectIntoScript;
            $this->injectIntoScript = null;
        }

        // The act AS chapters, the way the real writer returns it: the prose
        // cut into whole-sentence runs, each with a title and a re-hook, and
        // the script is their join. Two by default, which is the floor and
        // what a real act at the fake's target divides into.
        $chapters = $this->chaptersFor($script, $act->sequence, $this->firstChapterNumberFor($story, $act));
        $script = implode("\n\n", array_map(fn (ChapterDraft $c): string => $c->text, $chapters));

        return new ActScriptDraft(
            sequence: $act->sequence,
            script: $script,
            summary: sprintf(
                'Act %d: Dana sent another invoice and my mother backed her up in front of everyone. '
                .'I paid it. Nobody knows yet that I have been covering the care home since March.',
                $act->sequence
            ),
            rehookLine: $chapters[0]->rehookLine,
            chapters: $chapters,
            // ZERO, THROUGH simulated(), AND BOTH HALVES OF THAT MATTER.
            //
            // The quantity was `$targetWords` under a token unit — a word count
            // filed as tokens, so a ledger summing tokens by operation added a
            // number that is not one. Nothing was spent, so nothing is counted;
            // the target the stand-in was asked for stays in `detail`, where it
            // is a note about the call rather than a measurement of it.
            //
            // And `new ProviderUsage(provider: 'fake', ...)` leaves `simulated`
            // at its false default, so this would have written a fake row the
            // ledger could not tell from a real one at the flag. It cost
            // nothing yet — all 372 fake rows in the ledger came from providers
            // that use this factory — but "$8.12 of image spend that nobody was
            // billed for" is the entry this project keeps at the top of its
            // false-success table, and that row was flagged the same way.
            usage: ProviderUsage::simulated(
                operation: 'generate_act_script',
                category: CostCategory::Text,
                quantity: 0.0,
                unit: CostUnit::TotalTokens,
                detail: ['target_words' => $targetWords],
            ),
        );
    }

    /**
     * The act's prose as chapters: whole sentences, evenly divided.
     *
     * Sentences rather than words, because a chapter's boundary is a
     * sentence index and a chapter cut mid-sentence would fail the join
     * check in GenerateActScripts — correctly, and about the fake.
     *
     * @return array<int, ChapterDraft>
     */
    private function chaptersFor(string $script, int $actSequence, int $firstNumber): array
    {
        $count = max(1, $this->chaptersForNextAct ?? 2);
        $this->chaptersForNextAct = null;

        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($script)) ?: [$script];
        $perChapter = max(1, (int) ceil(count($sentences) / $count));

        // The spoken number opens the chapter and the re-hook is the sentence
        // AFTER it — which is the correction the real prompt took in the same
        // change. Story 30 stored "Chapter three." as four chapters'
        // `rehook_line`, so `acts.is_rehook_written` said yes on acts whose
        // recorded opening line was a chapter marker.
        $announce = ! $this->suppressChapterNumbers && ChapterAnnouncement::enabled();

        $chapters = [];

        foreach (array_chunk($sentences, $perChapter) as $index => $run) {
            $text = implode(' ', $run);

            $chapters[] = new ChapterDraft(
                title: self::CHAPTER_TITLES[($actSequence + $index) % count(self::CHAPTER_TITLES)],
                rehookLine: $this->suppressChapterRehooks ? '' : $run[0],
                text: $announce
                    ? ChapterAnnouncement::sentenceFor($firstNumber + $index).' '.$text
                    : $text,
            );
        }

        $this->suppressChapterRehooks = false;
        $this->suppressChapterNumbers = false;

        return $chapters;
    }

    /**
     * The story-wide number of this act's first chapter, read from the rows
     * the earlier acts stored — the same way `ClaudeScriptWriter` derives it,
     * because a fake that numbered from the act sequence would agree with
     * itself and never with a story whose acts came back at three chapters.
     */
    private function firstChapterNumberFor(Story $story, ActOutline $act): int
    {
        $earlier = $story->acts()->where('sequence', '<', $act->sequence)->pluck('id');

        return 1 + Chapter::query()->whereIn('act_id', $earlier)->count();
    }

    public function characters(Story $story, array $scripts, array $rejectionNotes = []): CharacterCast
    {
        $this->calls[] = [
            'method' => 'characters',
            'story_id' => $story->id,
            'scripts' => count($scripts),
            // Recorded so a test can assert the retry actually carried the
            // reason back rather than blindly re-rolling.
            'rejection_notes' => $rejectionNotes,
        ];

        $profiles = [];

        foreach (self::CAST as [$name, $description, $wardrobe, $importance]) {
            $profiles[] = new CharacterProfile(
                name: $name,
                // Physical and fixed. No mood, no posture, no action - a fake
                // whose descriptions drifted per scene would let a broken
                // consistency mechanism pass its own test.
                description: $description.($this->injectIntoCharacters ?? ''),
                // A prop can be injected for exactly N attempts, so the
                // repair loop in ExtractCharacters is testable: the interesting
                // case is a first answer that is dirty and a retry that is
                // clean, which is what the real run actually did.
                styleNotes: $this->dirtyStyleNotesForAttempts > 0
                    ? $wardrobe.'; often holding a handheld microphone'
                    : $wardrobe,
                importance: $importance,
            );
        }

        $this->injectIntoCharacters = null;
        $this->dirtyStyleNotesForAttempts = max(0, $this->dirtyStyleNotesForAttempts - 1);

        return new CharacterCast($profiles, ProviderUsage::free('fake', 'extract_characters', CostCategory::Text));
    }

    public function scenes(
        Story $story,
        Act $act,
        array $sentences,
        array $cast,
        int $targetScenes,
    ): SceneDraftSet {
        // RECORDED SO A TEST CAN ASSERT THEY ARRIVED, exactly as actScript()
        // does — and for the same reason, one call site later.
        //
        // `escalation_beat` was required at outline, checked at Gate 1, shown on
        // the page, and silently absent from the act generator for two phases.
        // That was found and fixed there. It was still absent HERE, in the call
        // that decides what 150-250 pictures contain, and nothing could see it
        // because this fake never looked at what it was handed.
        //
        // The general defect is that NOTHING ASKS WHICH OTHER CALLERS READ A
        // FIELD. It has now cost twice: this, and `CostUnit::TotalTokens` added
        // in code while eleven migrations built their columns from the enum. A
        // fake that records its inputs is the cheapest thing that turns the
        // third instance into a red test instead of a shipped video.
        $this->calls[] = [
            'method' => 'scenes',
            'story_id' => $story->id,
            'act' => $act->sequence,
            'sentences' => count($sentences),
            'cast' => count($cast),
            'target' => $targetScenes,
            'phase' => $act->phase?->value,
            'escalation_beat' => $act->escalation_beat,
            'timeframe' => $act->timeframe?->value,
            'narrator_grievance' => $story->narrator_grievance,
            'antagonist_justification' => $story->antagonist_justification,
            // What the real prompt hands act 1's scene call: who is in the room.
            'betrayal_scene' => $act->sequence === 1 ? $story->betrayal_scene : null,
            // The chapter boundaries this call could see, recorded in the
            // change that added them. A scene straddling one puts the last
            // still of one chapter under the opening line of the next.
            'chapter_boundaries' => $act->chapters()->pluck('first_sentence')->all(),
        ];

        $total = count($sentences);
        $perScene = max(1, (int) round($total / max(1, $targetScenes)));

        // Contiguous and total by construction, unless a test asks for a
        // broken split on purpose. DraftScenes rejects gaps and short runs, so
        // a fake that produced one by accident would fail the check it exists
        // to exercise rather than the code under test.
        $ranges = $this->sceneRangeOverride !== null
            ? ($this->sceneRangeOverride)($total)
            : $this->contiguousRanges($total, $perScene);

        $this->sceneRangeOverride = null;

        $scenes = [];
        $index = 0;

        foreach ($ranges as [$cursor, $last]) {
            $names = $cast === [] ? [] : [$cast[$index % count($cast)]->name];

            $scenes[] = new SceneDraft(
                firstSentence: $cursor,
                lastSentence: $last,
                // A composed frame, not a restatement of the narration. The
                // overlap check at Gate 2 runs over whatever this returns.
                frame: trim(sprintf(
                    '%s %s',
                    $this->frameOverride ?? self::FRAMES[$index % count(self::FRAMES)],
                    $this->injectIntoScenes ?? ''
                )),
                charactersPresent: $this->charactersPresentOverride ?? ($index % 3 === 2 ? [] : $names),
                motionPreset: $this->motionOverride ?? self::MOTIONS[$index % count(self::MOTIONS)],
                isThumbnailCandidate: ! $this->suppressThumbnails && ($this->thumbnailsPerAct === null
                    ? $index === 1
                    : ($index >= 1 && $index <= $this->thumbnailsPerAct)),
                // Empty on the cutaways this fake produces every third scene,
                // so a fixture run exercises both branches of the builder's
                // expression handling rather than only the peopled one.
                expression: $index % 3 === 2
                    ? ''
                    : ($this->expressionOverride ?? self::EXPRESSIONS[$index % count(self::EXPRESSIONS)]),
            );

            $index++;
        }

        $this->injectIntoScenes = null;

        return new SceneDraftSet(
            $scenes,
            // Same again: this counted SCENES under a token unit. The scene
            // count is a fact about the draft and belongs in detail, not in a
            // column whose whole job is "how much did the vendor meter".
            ProviderUsage::simulated(
                operation: 'draft_scenes',
                category: CostCategory::Text,
                quantity: 0.0,
                unit: CostUnit::TotalTokens,
                detail: ['act' => $act->sequence, 'scenes' => count($scenes)],
            ),
        );
    }

    /**
     * Whole-sentence ranges covering 1..$total exactly once, in order.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private function contiguousRanges(int $total, int $perScene): array
    {
        $ranges = [];
        $cursor = 1;

        while ($cursor <= $total) {
            $last = min($total, $cursor + $perScene - 1);
            $ranges[] = [$cursor, $last];
            $cursor = $last + 1;
        }

        return $ranges;
    }

    /**
     * Filler that is the right length, first person, and reads as American.
     *
     * Not lorem ipsum: word count is load-bearing everywhere downstream, and
     * the locale denylist runs over whatever this returns, so a fake producing
     * Latin would let a broken guard pass. First person because the genre is —
     * a fake written in the wrong voice would let a prompt regression through.
     */
    private function prose(int $targetWords, int $sequence): string
    {
        $sentences = [
            'The first invoice came by text at eleven at night, and it was for four thousand dollars.',
            'I said I had never agreed to it, and my mother told me this was not the time.',
            'Dana forwarded the florist my email address without asking me first.',
            'I paid it on a Tuesday and did not tell anyone, because I knew how that went.',
            'She said I was the one with no kids and no mortgage, like that settled it.',
            'My mother said family helps family, and everyone at the table agreed with her.',
            'I had been paying the care home since March out of that same account.',
            'Nobody asked me where the money was coming from, not once in eleven months.',
        ];

        $words = [];
        $index = $sequence;

        while (count($words) < $targetWords) {
            $words = array_merge($words, explode(' ', $sentences[$index % count($sentences)]));
            $index++;
        }

        return implode(' ', array_slice($words, 0, $targetWords));
    }

    /** Chapter titles, distinct from the act titles so a page can tell them apart. */
    private const CHAPTER_TITLES = [
        'Eleven at Night',
        'The Florist',
        'A Tuesday',
        'No Kids, No Mortgage',
        'Since March',
        'Not Once',
    ];

    private const TITLES = [
        'The First Invoice',
        'Keep The Peace',
        'The Group Chat',
        'What Mom Said',
        'The Seating Chart',
        'Eleven Months Later',
        'The Toast',
        'What I Read Out',
    ];

    /**
     * One per act title, so a six- or eight-act outline never wraps and
     * repeats. The genre check flags two acts that escalate identically, and a
     * fake that tripped its own validator would be testing the wrong thing.
     */
    private const COSTS = [
        'four thousand dollars I did not have',
        'my standing with my own mother',
        'the only relative who still believed me',
        'a week of work and the last of my savings',
        'my seat at the head table',
        'the deposit I had put on my own apartment',
        'the last afternoon I could have spent at the care home',
        'any version of this where I am still the reasonable one',
    ];

    /**
     * The reversal's costs, which run against the antagonist rather than the
     * narrator. Kept separate from COSTS rather than sharing it, because the
     * two lists are not interchangeable: "my seat at the head table" is a thing
     * the narrator loses and reads as nonsense the other way round.
     */
    private const SEARCH_COSTS = [
        'the two cousins who had backed her the loudest',
        'a month of savings she had told everyone she did not have',
        'the story she had been telling about why I stopped calling',
        'her standing with the aunt who arranged the seating',
    ];

    private const TOWNS = ['Bellefonte', 'Marion', 'Cold Spring', 'Delaware', 'Warrensburg'];

    /**
     * name, fixed physical description, wardrobe, importance.
     *
     * **This is the reference example of what a good extraction looks like**,
     * and it is held to the same rules the real one is: CharacterTextGuard runs
     * over it in every test that extracts a cast, so a fixture that drifts out
     * of contract fails loudly rather than modelling the wrong thing. The first
     * version of this list carried "usually pushed behind one ear", which is
     * the exact hedge the guard exists to catch.
     *
     * Written silhouette-first for the anime style: each of the four has a hair
     * SHAPE nobody else in the cast has — a blunt bob, a clipped receding cut,
     * long centre-parted, a swept-back mane — because at a wide shot the
     * outline is all a viewer gets, and three women distinguished only by hair
     * COLOUR collapse into one another at that distance.
     */
    private const CAST = [
        ['Erin Vasquez', 'Woman in her early forties, tall and square-shouldered, dark brown hair in '
            .'a blunt jaw-length bob with a hard side part, long oval face, heavy straight brows, '
            .'deep-set brown eyes, a small white scar through the left eyebrow.',
            'Plain crew-neck sweaters and jeans, a steel watch.', 'lead'],
        ['Kyle Vasquez', 'Man in his mid thirties, broad and thick through the chest, sandy hair '
            .'clipped short above a high receding hairline, round heavy-jawed face, small pale blue '
            .'eyes under low brows, permanent flush across the cheekbones.',
            'Work shirts untucked over a t-shirt, a ball cap.', 'lead'],
        ['Danielle Vasquez', 'Woman in her early thirties, small and fine-boned with narrow '
            .'shoulders, straight pale blonde hair past the shoulder blades from a centre part, '
            .'heart-shaped face, wide-set grey eyes, thin arched brows.',
            'Soft cardigans in cream and dusty pink.', 'supporting'],
        ['Paul Ostrander', 'Man in his late sixties, tall and stooped with narrow shoulders, a '
            .'swept-back mane of white hair above a deeply receded temple line, long gaunt face, '
            .'heavy grey brows, rectangular wire-rimmed glasses.', 'Grey suit, no tie.', 'minor'],
    ];

    /**
     * Composed frames, deliberately not restatements of any line of narration.
     * The Gate 2 overlap check runs over these, so a fake that transcribed its
     * own narration would let a broken check through.
     */
    /**
     * Plain, unhedged, and naming what the face is doing.
     *
     * Written to satisfy the rules the real prompt states, so that a fixture run
     * cannot pass a story whose expressions a live check would refuse. Note
     * there is no "slightly" or "faintly" here: those measure as no expression
     * at all against the real generator, and a fake that produced them would be
     * modelling the defect rather than the behaviour.
     */
    private const EXPRESSIONS = [
        'Jaw set, eyes down, mouth closed hard.',
        'Brows drawn together, mouth open mid-word.',
        'Openly crying, tears on both cheeks.',
        'Eyes wide and fixed, lips parted.',
    ];

    private const FRAMES = [
        'A kitchen table at night lit by one overhead lamp, a laptop open on a banking screen, '
            .'a coffee mug gone cold beside it, the rest of the house dark behind.',
        'Close on a woman at a screen door, jaw set, keys still in her hand, porch light '
            .'above her throwing hard shadow down her face.',
        'A gravel driveway at dusk seen from the road, a boat on a trailer hitched to a pickup, '
            .'the house behind it with two windows lit.',
        'Over the shoulder of a man at a microphone in a backyard, folding chairs in rows, '
            .'late afternoon sun flat across the grass, faces turned toward him.',
        'A stack of stapled documents on a card table beside a paper plate, one corner lifting '
            .'in the wind, a hand resting flat on top.',
        'Wide interior of a funeral home reception room, mismatched chairs against the walls, '
            .'a coffee urn on a folding table, nobody looking at anybody.',
    ];

    private const MOTIONS = ['zoom_in', 'pan_right', 'zoom_out', 'pan_left', 'static', 'zoom_in'];
}
