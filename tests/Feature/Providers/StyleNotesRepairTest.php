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

    public function test_the_retry_is_told_what_was_wrong_rather_than_just_re_rolled(): void
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

        // The whole point. Asking again without saying what failed re-rolls the
        // same mistake at the same price.
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
