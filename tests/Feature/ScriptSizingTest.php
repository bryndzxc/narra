<?php

namespace Tests\Feature;

use App\Actions\GenerateActScripts;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\Story;
use App\Support\NarrationPace;
use App\Support\ScriptSizing;
use Faker\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The word budget, after it stopped being derived from a number nobody measured.
 *
 * ---------------------------------------------------------------------------
 * WHAT WENT WRONG
 * ---------------------------------------------------------------------------
 *
 * `targetWordsPerAct()` read the FALLBACK constant, 160, so a 35-minute midpoint
 * asked for 5,600 words — and 5,600 words read by the only narrator this channel
 * has takes 28.4 minutes. Every script was under the 30-minute floor before a
 * word of it existed. Story 9 hit its target and still landed 21 seconds short.
 *
 * Three more places derived something from the same constant. A constant
 * corrected in one of four places is the shape that gave one narration three
 * different prices, so the assertions below are as much about there being ONE
 * reader as about the number being right.
 */
class ScriptSizingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A new story is sized against the measured rate, not the fallback.
     *
     * RED: 5,600 words, which is what 160 produces and what every script in this
     * database was written to.
     */
    public function test_a_new_story_is_sized_against_the_measured_rate(): void
    {
        $story = $this->storyWithActs();

        $this->assertSame(
            197,
            ScriptSizing::wpmFor($story),
            'A story with no frozen figure sizes against what narration has actually been measured '
            .'at, not the fallback the measurement replaced.',
        );

        $this->assertNotSame(
            5600,
            ScriptSizing::targetWords($story),
            '5,600 words is the 160-wpm budget. It runs 28.4 minutes at the rate this narrator '
            .'reads, which is under the floor before a word is written.',
        );

        $this->assertSame(6895, ScriptSizing::targetWords($story));
    }

    /**
     * And the budget it produces lands inside the story's own window.
     *
     * THE ASSERTION THE OLD TARGET COULD NOT PASS. This is the whole defect
     * stated as a property rather than as a number: whatever the rate is, a
     * script written to its budget must run inside the window it was sized for.
     * At 160 against a 197-wpm narrator it ran 28.4 minutes against a 30-40
     * window and no test anywhere said so.
     */
    public function test_the_budget_implies_a_runtime_inside_the_window(): void
    {
        $story = $this->storyWithActs();

        $minutes = ScriptSizing::minutesFor($story, ScriptSizing::targetWords($story));

        $this->assertTrue(
            ScriptSizing::withinWindow($story, $minutes),
            sprintf(
                'A script written exactly to its own word budget runs %.1f minutes, outside the '
                .'%d-%d window it was sized for.',
                $minutes,
                $story->target_duration_min,
                $story->target_duration_max,
            ),
        );
    }

    /**
     * A story that was already sized keeps its own figure.
     *
     * NOTHING IN THIS CHANGE REGENERATES OR RE-SIZES AN EXISTING STORY. Story 9
     * was written to 160 and stays written to 160; the corrected rate applies to
     * scripts written from here on. A frozen figure that the new rate could
     * override would make the column decorative and every earlier script
     * unreconstructable, which is the exact thing it was added to prevent.
     */
    public function test_a_story_already_sized_keeps_its_frozen_rate(): void
    {
        $story = $this->storyWithActs();
        $story->forceFill(['sized_against_wpm' => 160])->save();

        $this->assertSame(160, ScriptSizing::wpmFor($story->refresh()));
        $this->assertSame(5600, ScriptSizing::targetWords($story));
    }

    /**
     * A story with no voice yet is still sized against a measurement.
     *
     * THE FIX THAT WOULD NOT HAVE FIXED. `providers.default_voice_id` is
     * deliberately null until a channel's narrator is locked, so a story created
     * through the console carries no `voice_id` at act-script time — and
     * `expectedWpm(null, ...)` returns the fallback. Pointing the word target at
     * the per-voice figure alone would have left every new story sized at 160
     * while the code read as corrected: absence reading as agreement, inside the
     * change written to stop exactly that.
     *
     * `bestKnownWpm()` asks the locale when it cannot ask the voice, and uses only
     * real measurements to answer.
     */
    public function test_a_story_with_no_voice_is_sized_from_its_locale(): void
    {
        $story = $this->storyWithActs('sizing-voiceless');
        $story->forceFill(['voice_id' => null])->save();

        $this->assertSame(
            160,
            NarrationPace::expectedWpm(null, 'en-US'),
            'The premise of this test: the per-voice figure alone still answers the fallback here.',
        );

        $this->assertSame(
            197,
            ScriptSizing::wpmFor($story->refresh()),
            'A story sized before its narrator is assigned must still be sized against a rate '
            .'somebody measured.',
        );
    }

    /** en-CN is measured separately, and the sizing follows the locale. */
    public function test_the_locale_carries_its_own_measurement(): void
    {
        $story = $this->storyWithActs('sizing-cn');
        $story->forceFill(['voice_id' => null, 'locale_profile' => 'en-CN'])->save();

        $this->assertSame(199, ScriptSizing::wpmFor($story->refresh()));
    }

    /**
     * With nothing measured at all, the fallback is still the honest answer.
     *
     * The GREEN counterpart to the two above. `bestKnownWpm()` must not invent a
     * figure — an unmeasured locale gets the constant, the story records it, and
     * the run establishes the number. Same reasoning as `isEnforceable()`: what
     * varies is what the disagreement PROVES, not whether to have one.
     */
    public function test_an_unmeasured_locale_falls_back_to_the_constant(): void
    {
        $this->assertSame(
            (int) config('render.narration.words_per_minute'),
            NarrationPace::bestKnownWpm(null, 'en-XX'),
        );
    }

    /**
     * The generator freezes the figure it sized against, and reads it back.
     *
     * End to end, because the freeze and the budget are two halves of one fact
     * and this is the seam they meet at.
     */
    public function test_writing_act_scripts_freezes_the_measured_rate(): void
    {
        $story = $this->storyWithActs('sizing-e2e');

        app(GenerateActScripts::class)->handle($story);

        $this->assertSame(197, (int) $story->refresh()->sized_against_wpm);
    }

    /**
     * Sizing and the runtime estimate agree about the same story.
     *
     * THE GREP GUARD BELOW CANNOT ASK THIS, and this defect got past it. Every
     * caller had stopped reading the raw constant and the file list was exactly
     * right — while sizing asked one function and the runtime estimate asked
     * another, so a story carrying an unmeasured voice id was sized at 197 and
     * estimated at 160. Two beliefs about one narration, which is the drift the
     * whole change exists to end, reintroduced inside it.
     *
     * `narrator-us-01` is not a hypothetical: it is the id the fake invented,
     * every story from 3 to 12 carries it, and it is measured nowhere.
     *
     * One reader is a necessary condition and not a sufficient one. This asks
     * the sufficient version — that the two numbers are the same number.
     */
    public function test_the_sizing_rate_and_the_runtime_estimate_agree(): void
    {
        foreach (['nPczCjzI2devNBz1zQrb', 'narrator-us-01', null] as $voice) {
            $story = $this->storyWithActs('agree-'.($voice ?? 'null'));
            $story->forceFill(['voice_id' => $voice, 'sized_against_wpm' => null])->save();
            $story->refresh();

            $words = ScriptSizing::targetWords($story);
            $minutes = ScriptSizing::minutesFor($story, $words);

            $this->assertTrue(
                ScriptSizing::withinWindow($story, $minutes),
                sprintf(
                    'With voice %s a script written to its own budget runs %.1f minutes, outside '
                    .'its %d-%d window — so the rate it was sized at and the rate it is estimated '
                    .'at are not the same number.',
                    var_export($voice, true),
                    $minutes,
                    $story->target_duration_min,
                    $story->target_duration_max,
                ),
            );
        }
    }

    /**
     * Exactly one place derives a word budget, and three read the raw constant
     * for reasons that are not sizing.
     *
     * THE ANTI-DRIFT GUARD, and the reason it is worth a test rather than a
     * convention. Four call sites each read `render.narration.words_per_minute`
     * and derived something from it: the word target, the dispatch estimate, two
     * prompt figures, and `story:write`'s reported runtime. Correcting one of
     * four is how one narration came to carry three different prices — $2.12 in
     * the ledger, $4.24 on the rate card, 42,017 units in the estimate — from a
     * single multiplier applied by code that never compared notes.
     *
     * The three that remain are named individually, so adding a fourth is a
     * decision somebody makes here rather than a line that slips in:
     *
     *   NarrationPace          owns the fallback. This is its home.
     *   FakeSpeechSynthesizer  has no real voice to be measured, so the
     *                          fallback is the correct rate for it — and the
     *                          fake deriving its duration from the same
     *                          constant is deliberate.
     *   RunFingerprint         records the fallback AS the fallback, for
     *                          provenance. It is not sizing anything.
     */
    public function test_nothing_outside_narration_pace_sizes_from_the_raw_constant(): void
    {
        $allowed = [
            'app/Support/NarrationPace.php',
            'app/Services/Fake/FakeSpeechSynthesizer.php',
            'app/Support/RunFingerprint.php',
        ];

        $found = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            $source = (string) file_get_contents($file);

            // EITHER QUOTE, and that is not pedantry — it was found by drilling.
            // The first version required a single quote, so reintroducing the
            // defect as config("render...") walked straight past a guard written
            // to catch exactly it. A detector one quote character defeats is the
            // line-break defect in `claimsNotEntitledTo` wearing different clothes,
            // and it is the second time in this codebase that a drill has passed
            // because the drill was narrower than the thing it was drilling.
            //
            // Only real reads: the key also appears in prose explaining why these
            // callers no longer read it, and a guard counting its own
            // documentation would fire on its own fix.
            $reads = '/config\(\s*[\'"]render\.narration\.words_per_minute[\'"]/';

            if (! preg_match($reads, $source)) {
                continue;
            }

            $found[] = str_replace('\\', '/', substr($file, strlen(base_path()) + 1));
        }

        sort($found);
        sort($allowed);

        $this->assertSame(
            $allowed,
            $found,
            'Something new reads the raw wpm constant. If it is sizing a script it must ask '
            .'ScriptSizing; if it is estimating a runtime it must ask NarrationPace::expectedWpm. '
            .'Four separate readers of one constant is how the same narration got three prices.',
        );
    }

    // -- Fixtures ------------------------------------------------------------

    /** @return array<int, string> */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function storyWithActs(string $slug = 'sizing-story'): Story
    {
        $story = Story::factory()->status(StoryStatus::Outlined)->create([
            'slug' => $slug,
            'voice_id' => 'nPczCjzI2devNBz1zQrb',
            'locale_profile' => 'en-US',
            'target_duration_min' => 30,
            'target_duration_max' => 40,
        ]);

        app(Generator::class)->unique(reset: true);

        for ($i = 1; $i <= 7; $i++) {
            Act::factory()->for($story)->atSequence($i)->create(['script' => null]);
        }

        return $story->refresh();
    }
}
