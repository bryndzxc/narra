<?php

namespace Tests\Feature\Providers;

use App\Actions\ExtractCharacters;
use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\Character;
use App\Models\RenderJob;
use App\Models\Story;
use App\Services\Fake\FakeScriptWriter;
use App\Support\CharacterTextGuard;
use App\Support\Providers\CharacterProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Story 21, and the three things it cost to find out about.
 *
 * A cast extraction was refused for the word `weathered` in one character's
 * description. It was dispatched three times, each dispatch ran the two-attempt
 * repair loop, and all six billed calls produced the same word. $0.35 spent,
 * three terminal failures, and none of it visible anywhere an operator looks.
 *
 * The refusal itself was correct — `weathered` on a jaw is skin, and skin is
 * what rendered a sixty-eight-year-old at eighty-five. Three things around it
 * were not.
 */
class ExtractCharactersFailureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The retry was corrected with a note that never named the word it was
     * rejected for.
     *
     * The guard banned `weathered`; the hand-written summary in the rejection
     * prompt listed "no wrinkles, no deeply lined, no sagging, no liver spots"
     * and stopped there. Two copies of one rule, agreeing only on the day they
     * were written. The summary is generated from the guard's own lists now, so
     * this asserts the property rather than the instance.
     */
    public function test_the_retry_summary_cannot_disagree_with_what_the_guard_refuses(): void
    {
        $summary = app(CharacterTextGuard::class)->ruleSummary();

        // The exhaustive category, listed in full because it is the one with a
        // demonstrated escape.
        foreach (['weathered', 'deeply lined', 'sagging', 'liver spots', 'crepey'] as $term) {
            $this->assertStringContainsString($term, $summary, "The retry is not told about: {$term}");
        }

        // And every other category is represented, so a violation in any of
        // them arrives with its rule attached.
        foreach (['a hedge', 'carried object', 'posture', 'expression', 'ageing texture'] as $category) {
            $this->assertStringContainsString($category, $summary);
        }

        // Face shape is wanted, and saying so is part of the same message —
        // otherwise "no ageing texture" reads as "say nothing about the face".
        $this->assertStringContainsString('Face SHAPE is not texture', $summary);
    }

    /**
     * The refusal an operator reads must carry the text it fired on.
     *
     * It named the term and withheld the sentence, while textProblems() — the
     * internal note nobody sees — carried both. Answering "on what text" cost a
     * billed call that should have been a grep.
     */
    public function test_the_refusal_quotes_the_text_it_fired_on(): void
    {
        $description = 'Man in his late sixties, white hair receding sharply from a high forehead, '
            .'a long gaunt face, thick straight brows, deep-set steady eyes, weathered square jaw.';

        try {
            app(CharacterTextGuard::class)->assert(
                [new CharacterProfile('Lu Jianguo', $description, 'A grey wool coat.')],
                'character extraction',
            );

            $this->fail('Expected the cast to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('weathered', $e->getMessage());
            $this->assertStringContainsString($description, $e->getMessage(), 'The message withheld the text.');
            $this->assertStringContainsString('Lu Jianguo', $e->getMessage());
        }
    }

    /**
     * A failed extraction is visible on the page the operator watches.
     *
     * This is the one with money attached. `DraftSceneListJob::failed()` looks
     * for an existing `draft_scenes` row to mark failed, and nothing created
     * one: DraftScenes opens its own row and never got that far, because the
     * job dies in extraction, which runs first and recorded nothing at all. So
     * story 21's render page showed `outline` succeeded, `act_scripts`
     * succeeded, and then nothing — for three failures and $0.35.
     */
    public function test_a_refused_extraction_leaves_a_failed_row(): void
    {
        $story = $this->scriptedStory();

        /** @var FakeScriptWriter $writer */
        $writer = app(FakeScriptWriter::class);
        $writer->dirtyStyleNotesForAttempts = 99;

        try {
            app(ExtractCharacters::class)->handle($story);
            $this->fail('Expected the extraction to be refused.');
        } catch (RuntimeException) {
            // The refusal is the point of the other tests; this one is about
            // what it leaves behind.
        }

        $job = RenderJob::query()
            ->where('story_id', $story->id)
            ->where('stage', RenderStage::ExtractCast)
            ->first();

        $this->assertNotNull($job, 'A refused extraction recorded nothing at all.');
        $this->assertSame(RenderJobStatus::Failed, $job->status);
        $this->assertNotNull($job->error);
    }

    public function test_the_row_says_how_many_billed_attempts_it_bought(): void
    {
        // Each dispatch runs the repair loop twice, so a failure costs two
        // calls and not one — which is why three dispatches cost six. The row
        // is where that becomes visible without reading the ledger.
        $story = $this->scriptedStory();

        /** @var FakeScriptWriter $writer */
        $writer = app(FakeScriptWriter::class);
        $writer->dirtyStyleNotesForAttempts = 99;

        try {
            app(ExtractCharacters::class)->handle($story);
        } catch (RuntimeException) {
        }

        $log = (string) RenderJob::query()
            ->where('story_id', $story->id)
            ->where('stage', RenderStage::ExtractCast)
            ->value('log');

        $this->assertStringContainsString('Attempt 1', $log);
        $this->assertStringContainsString('Attempt 2', $log);
        $this->assertStringContainsString('problem(s) to repair', $log);
    }

    public function test_a_clean_extraction_succeeds_the_row(): void
    {
        $story = $this->scriptedStory();

        app(ExtractCharacters::class)->handle($story);

        $job = RenderJob::query()
            ->where('story_id', $story->id)
            ->where('stage', RenderStage::ExtractCast)
            ->firstOrFail();

        $this->assertSame(RenderJobStatus::Succeeded, $job->status);
        $this->assertStringContainsString('Attempt 1', (string) $job->log);
    }

    public function test_a_kept_cast_says_it_was_kept_rather_than_extracted(): void
    {
        // A succeeded row for a stage that did nothing reads exactly like a
        // fresh extraction, and the difference is whether anything was billed.
        $story = $this->scriptedStory();
        Character::factory()->for($story)->create();

        $result = app(ExtractCharacters::class)->handle($story);

        $this->assertTrue($result['kept']);
        $this->assertStringContainsString(
            'Nothing was billed',
            (string) RenderJob::query()
                ->where('story_id', $story->id)
                ->where('stage', RenderStage::ExtractCast)
                ->value('log'),
        );
    }

    private function scriptedStory(): Story
    {
        $story = Story::factory()->status(StoryStatus::Scripted)->create();

        Act::factory()->for($story)->atSequence(1)->create([
            'script' => 'Erin stood in the kitchen. Kyle would not look at her. '
                .'The house had been their mothers and now it was not.',
        ]);

        return $story;
    }
}
