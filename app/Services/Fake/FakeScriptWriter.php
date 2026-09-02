<?php

namespace App\Services\Fake;

use App\Contracts\ScriptWriter;
use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Models\Act;
use App\Models\Story;
use App\Support\Providers\ActOutline;
use App\Support\Providers\ActScriptDraft;
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

    public function outline(Story $story, int $actCount): OutlineDraft
    {
        $this->calls[] = ['method' => 'outline', 'story_id' => $story->id, 'act_count' => $actCount];

        $acts = [];

        for ($i = 1; $i <= $actCount; $i++) {
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
                escalationBeat: sprintf(
                    'Act %d costs me %s, in front of %d more people than the last time.',
                    $i,
                    self::COSTS[($i - 1) % count(self::COSTS)],
                    $i * 4
                ),
            );
        }

        $this->injectIntoOutline = null;

        return new OutlineDraft(
            // The ending stated in the title. This genre does not withhold —
            // the promise of the payoff is the hook.
            title: 'My Sister Billed Me For Her Wedding - So At The Reception I Read Out The Receipts',
            acts: $acts,
            usage: ProviderUsage::free('fake', 'generate_outline', CostCategory::Text),
            narratorGrievance: 'My older sister Dana told our whole family I had promised to pay for '
                .'her wedding, then billed me for it piece by piece over eleven months, and every time '
                .'I said I had not agreed she told me I was embarrassing her.',
            // Self-justified, not cartoonish. Contains no confession marker, so
            // it passes the check the real generator has to pass.
            antagonistJustification: 'Dana says I am the one with no kids and no mortgage, that she '
                .'gave up her twenties looking after our mother while I was away at school, and that '
                .'family helps family. She believes every word of it, and so does our mother.',
            withheldInformation: 'I had been paying our mother care home fees since March out of the '
                .'same account, and Dana had never once asked where that money was coming from.',
            // Witnesses named, because an exposure without them is a private
            // conversation and a much worse video.
            exposureMoment: 'At the reception, in front of eighty guests and both families, when Dana '
                .'stood up to thank everyone who had helped and named everyone except me.',
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
        // Recorded in order. The test that this stage stayed sequential reads
        // exactly this: act N must have arrived carrying N-1 prior summaries.
        $this->calls[] = [
            'method' => 'actScript',
            'story_id' => $story->id,
            'sequence' => $act->sequence,
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

        return new ActScriptDraft(
            sequence: $act->sequence,
            script: $script,
            summary: sprintf(
                'Act %d: Dana sent another invoice and my mother backed her up in front of everyone. '
                .'I paid it. Nobody knows yet that I have been covering the care home since March.',
                $act->sequence
            ),
            rehookLine: 'The second invoice came the morning after I told her I could not do this again.',
            usage: new ProviderUsage(
                provider: 'fake',
                operation: 'generate_act_script',
                category: CostCategory::Text,
                quantity: (float) $targetWords,
                unit: CostUnit::OutputTokens,
                usdCost: 0.0,
                detail: ['target_words' => $targetWords],
            ),
        );
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
        $this->calls[] = [
            'method' => 'scenes',
            'story_id' => $story->id,
            'act' => $act->sequence,
            'sentences' => count($sentences),
            'cast' => count($cast),
            'target' => $targetScenes,
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
                    self::FRAMES[$index % count(self::FRAMES)],
                    $this->injectIntoScenes ?? ''
                )),
                charactersPresent: $index % 3 === 2 ? [] : $names,
                motionPreset: $this->motionOverride ?? self::MOTIONS[$index % count(self::MOTIONS)],
                isThumbnailCandidate: ! $this->suppressThumbnails && $index === 1,
            );

            $index++;
        }

        $this->injectIntoScenes = null;

        return new SceneDraftSet(
            $scenes,
            new ProviderUsage(
                provider: 'fake',
                operation: 'draft_scenes',
                category: CostCategory::Text,
                quantity: (float) count($scenes),
                unit: CostUnit::OutputTokens,
                usdCost: 0.0,
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

    private const TOWNS = ['Bellefonte', 'Marion', 'Cold Spring', 'Delaware', 'Warrensburg'];

    /** name, fixed physical description, wardrobe, importance. */
    private const CAST = [
        ['Erin Vasquez', 'Woman in her early forties, tall and square-shouldered, dark brown hair cut '
            .'to the jaw and usually pushed behind one ear, olive skin, heavy brows, a small white '
            .'scar through the left eyebrow.', 'Plain crew-neck sweaters and jeans, a steel watch.', 'lead'],
        ['Kyle Vasquez', 'Man in his mid thirties, broad and running to heavy, close-cropped sandy '
            .'hair receding at the temples, round face, pale blue eyes, permanent flush across the '
            .'cheekbones.', 'Work shirts untucked over a t-shirt, a ball cap.', 'lead'],
        ['Danielle Vasquez', 'Woman in her early thirties, small and fine-boned, straight blonde hair '
            .'to the shoulders with a middle part, fair skin, wide-set grey eyes.', 'Soft cardigans '
            .'in cream and dusty pink.', 'supporting'],
        ['Paul Ostrander', 'Man in his late sixties, thin and slightly stooped, full head of white '
            .'hair combed back, long lined face, wire-rimmed glasses.', 'Grey suit, no tie.', 'minor'],
    ];

    /**
     * Composed frames, deliberately not restatements of any line of narration.
     * The Gate 2 overlap check runs over these, so a fake that transcribed its
     * own narration would let a broken check through.
     */
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
