<?php

namespace Tests\Feature\Providers;

use App\Actions\ExtractCharacters;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\Character;
use App\Models\CostEntry;
use App\Models\Story;
use App\Services\Fake\FakeScriptWriter;
use App\Support\CharacterTextGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The repair loop around character extraction.
 *
 * It exists because fixing the prompt was not enough, twice over. The first
 * attempt corrected the system prompt and left the user message still asking
 * for "recurring props", so the model followed the more specific instruction.
 * After both prompts were corrected, the first real extraction was STILL dirty
 * and only the feedback retry cleared it.
 *
 * So the guarantee is not "the prompt asks nicely". It is that a prop cannot
 * reach the database, and that trying again says what was wrong.
 */
class StyleNotesRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_dirty_first_answer_is_retried_and_the_clean_one_is_kept(): void
    {
        $story = $this->story();

        /** @var FakeScriptWriter $writer */
        $writer = app(FakeScriptWriter::class);
        $writer->dirtyStyleNotesForAttempts = 1;

        app(ExtractCharacters::class)->handle($story);

        $guard = app(CharacterTextGuard::class);

        foreach (Character::where('story_id', $story->id)->get() as $character) {
            $this->assertTrue(
                $guard->isClean($character->style_notes),
                "Stored a prop: {$character->style_notes}"
            );
        }
    }

    /**
     * THE RETRY IS A CLEAN RE-SAMPLE, AND THAT REVERSES THIS FILE'S ORIGINAL
     * ASSERTION ON MEASURED GROUNDS.
     *
     * This test used to be `test_the_retry_is_told_what_was_wrong_rather_than
     * _just_re_rolled` and it asserted the opposite, on the reasoning that
     * "asking again without saying what failed re-rolls the same mistake at
     * the same price". Sound a priori, and wrong: across four real attempts
     * on story 32 the note never repaired the cast and always introduced a
     * NEW violation in a DIFFERENT character, spelled out of the note's own
     * vocabulary -- "round softly sagging-free face read instead as softly
     * rounded", "a square weathered-shaped face". The model acknowledges the
     * instruction inside the field text, where an image generator reads it as
     * description.
     *
     * The flag keeps the old behaviour reachable, because this is an
     * experiment rather than the fix -- the fix is a repair scoped to the
     * offending field, and it is not built. See config/characters.php.
     */
    public function test_the_retry_is_a_clean_resample_by_default(): void
    {
        $story = $this->story();

        /** @var FakeScriptWriter $writer */
        $writer = app(FakeScriptWriter::class);
        $writer->dirtyStyleNotesForAttempts = 1;

        app(ExtractCharacters::class)->handle($story);

        $attempts = array_values(array_filter(
            $writer->calls,
            fn (array $call): bool => $call['method'] === 'characters'
        ));

        $this->assertCount(2, $attempts);
        $this->assertSame([], $attempts[0]['rejection_notes'], 'The first attempt cannot have feedback.');
        $this->assertSame(
            [],
            $attempts[1]['rejection_notes'],
            'The retry must be a clean re-sample: the note supplies the forbidden word.',
        );
    }

    /** And the old behaviour is still reachable, so the contamination can be reproduced. */
    public function test_the_note_can_be_turned_back_on(): void
    {
        config(['characters.repair_with_notes' => true]);

        $story = $this->story();

        /** @var FakeScriptWriter $writer */
        $writer = app(FakeScriptWriter::class);
        $writer->dirtyStyleNotesForAttempts = 1;

        app(ExtractCharacters::class)->handle($story);

        $attempts = array_values(array_filter(
            $writer->calls,
            fn (array $call): bool => $call['method'] === 'characters'
        ));

        $this->assertCount(2, $attempts);
        $this->assertNotEmpty($attempts[1]['rejection_notes']);
        $this->assertStringContainsString('microphone', implode(' ', $attempts[1]['rejection_notes']));
    }

    public function test_both_attempts_are_billed(): void
    {
        $story = $this->story();

        /** @var FakeScriptWriter $writer */
        $writer = app(FakeScriptWriter::class);
        $writer->dirtyStyleNotesForAttempts = 1;

        app(ExtractCharacters::class)->handle($story);

        // The discarded call happened. Hiding it because its output was thrown
        // away would understate the story by the cost of the attempt — the same
        // rule the scene-drafting fallback follows.
        $this->assertSame(2, CostEntry::where('story_id', $story->id)
            ->where('operation', 'extract_characters')->count());
    }

    public function test_it_gives_up_after_one_retry_rather_than_looping(): void
    {
        $story = $this->story();

        /** @var FakeScriptWriter $writer */
        $writer = app(FakeScriptWriter::class);
        $writer->dirtyStyleNotesForAttempts = 5;

        // A prompt that is not landing does not start landing on the fourth
        // ask, and re-rolling it is how a $0.04 call becomes a loop.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/style_notes/');

        try {
            app(ExtractCharacters::class)->handle($story);
        } finally {
            $attempts = array_filter(
                $writer->calls,
                fn (array $call): bool => $call['method'] === 'characters'
            );

            $this->assertCount(2, $attempts);

            // And nothing dirty was written on the way out.
            $this->assertSame(0, Character::where('story_id', $story->id)->count());
        }
    }

    public function test_a_clean_first_answer_costs_one_call(): void
    {
        $story = $this->story();

        app(ExtractCharacters::class)->handle($story);

        $this->assertSame(1, CostEntry::where('story_id', $story->id)
            ->where('operation', 'extract_characters')->count());
        $this->assertGreaterThan(0, Character::where('story_id', $story->id)->count());
    }

    private function story(): Story
    {
        $story = Story::factory()->status(StoryStatus::Scripted)->create(['slug' => 'repair-test']);

        Act::factory()->for($story)->atSequence(1)->create([
            'script' => 'Erin sat at the kitchen table. Kyle would not look at her. '
                .'The spreadsheet lay between them, one row highlighted.',
        ]);

        return $story;
    }
}
