<?php

namespace Tests\Feature\Providers;

use App\Actions\GenerateOutline;
use App\Contracts\ScriptWriter;
use App\Enums\OperatorAction;
use App\Enums\PartnerEndState;
use App\Enums\StoryEnding;
use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use App\Jobs\WriteStoryJob;
use App\Livewire\Gates\OutlineGate;
use App\Models\Story;
use App\Services\Fake\FakeScriptWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * THE REPAIR FOR A BAD OUTLINE HAS A BUTTON.
 *
 * Built 2026-09-20. A story with no act scripts and an outline that came back
 * wrong is a single $0.28 call to fix, and until now there was no press for
 * it: `story:write` said "Outline already exists — keeping it", `WriteStoryJob`
 * wrote the outline only when the act count was zero, and Gate 1's button went
 * through both. The repair existed and could be reached only by calling the
 * Action from a bootstrap script.
 *
 * The operator's framing, and it is the standing question rather than a note
 * about this one press: **does the repair a Gate 1 finding names have a
 * button?** This is the second time the answer was no — the first was a denied
 * locale term in an act script, whose repair is `story:write --acts-only=N`.
 *
 * -------------------------------------------------------------------------
 * THE PRECONDITION IS THE PART WORTH TESTING HARDEST
 * -------------------------------------------------------------------------
 *
 * `outlined` is BOTH the state this press is for (acts exist, no scripts, the
 * outline is still a plan) and the ordinary state of a story whose every
 * script is written and waiting for approval — CLAUDE.md says so in as many
 * words. So the capability cannot answer on its own, and a position-only
 * guard would have offered a press that deletes the scripts underneath it.
 * That is the axis question asked before the press existed rather than after
 * it cost something.
 */
class ReOutlineTest extends TestCase
{
    use RefreshDatabase;

    private FakeScriptWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = new FakeScriptWriter;
        $this->app->instance(ScriptWriter::class, $this->writer);
    }

    public function test_the_capability_is_outlined_only(): void
    {
        foreach (StoryStatus::cases() as $status) {
            $this->assertSame(
                $status === StoryStatus::Outlined,
                OperatorAction::ReOutline->permittedAt($status),
                "ReOutline at {$status->value}",
            );
        }
    }

    /** Never swallowed: every status that refuses says why. */
    public function test_every_refused_status_gives_a_reason(): void
    {
        foreach (StoryStatus::cases() as $status) {
            if ($status === StoryStatus::Outlined) {
                $this->assertNull(OperatorAction::ReOutline->refusalReason($status));

                continue;
            }

            $this->assertNotNull(
                OperatorAction::ReOutline->refusalReason($status),
                "ReOutline at {$status->value} refuses with no reason.",
            );
        }
    }

    /**
     * The one the status cannot see. A story at `outlined` with written
     * scripts is the ordinary pre-approval state, and deleting its acts is
     * what this refuses.
     */
    public function test_the_action_refuses_a_reoutline_that_would_orphan_scripts(): void
    {
        $story = $this->outlinedStory();
        $story->acts()->first()->update(['script' => 'Written. '.str_repeat('Words. ', 60)]);

        $this->expectExceptionMessage(GenerateOutline::WRITTEN_ACTS);

        app(GenerateOutline::class)->handle($story->fresh());
    }

    public function test_the_button_is_offered_on_a_plan_and_withdrawn_once_a_script_exists(): void
    {
        $story = $this->outlinedStory();

        $page = Livewire::test(OutlineGate::class, ['story' => $story->fresh()]);
        $this->assertTrue($page->instance()->canReOutline());
        $this->assertNull($page->instance()->reOutlineRefusal());

        // The panel says what it DESTROYS, which is the whole difference
        // between this money panel and the one above it, and it says the cast
        // is held — the sentence story 39 did not get.
        $page->assertSee('Write the outline again')
            ->assertSee('Keep the cast on this story')
            // Short, contiguous needles: the blade wraps, and a needle that
            // straddles a line break matches nothing while looking like a
            // passing assertion. Entry 11 in the self-defeating-checks table.
            ->assertSee('with their summaries')
            ->assertSee('cast row(s)')
            ->assertSee('same names in the')
            ->assertSee('if it drops a person');

        // And the released wording is the other half, not a quieter version
        // of the same sentence.
        $page->set('keepCast', false)
            ->assertSee('The cast is released.')
            ->assertSee('may not come back under the same name');

        $story->acts()->first()->update(['script' => 'Written. '.str_repeat('Words. ', 60)]);

        $page = Livewire::test(OutlineGate::class, ['story' => $story->fresh()]);
        $this->assertFalse($page->instance()->canReOutline());

        // Withdrawn AND explained. A panel that simply vanishes is a page
        // saying nothing where it should say why.
        $this->assertSame(GenerateOutline::WRITTEN_ACTS, $page->instance()->reOutlineRefusal());
        $page->assertSee('This outline cannot be replaced from here.');
    }

    /** A story with no outline at all is the first press, not this one. */
    public function test_a_story_with_no_acts_is_not_offered_a_reoutline(): void
    {
        $story = Story::factory()->status(StoryStatus::Draft)->create($this->attributes());

        $page = Livewire::test(OutlineGate::class, ['story' => $story]);

        $this->assertFalse($page->instance()->canReOutline());
        $this->assertNull($page->instance()->reOutlineRefusal(), 'Nothing to say about an outline that does not exist yet.');
    }

    public function test_the_press_queues_one_outline_call_and_keeps_the_cast_by_default(): void
    {
        Queue::fake();

        $story = $this->outlinedStory();

        Livewire::test(OutlineGate::class, ['story' => $story->fresh()])
            ->call('askToReOutline')
            ->assertSet('confirmingReOutline', true)
            ->assertSet('keepCast', true)
            ->call('reOutline')
            ->assertSet('confirmingReOutline', false);

        Queue::assertPushed(WriteStoryJob::class, function (WriteStoryJob $job) use ($story): bool {
            return $job->storyId === $story->id
                && $job->reOutline === true
                && $job->keepCast === true
                // The outline alone, exactly as a first run stops after it:
                // the new cast and spine are read before an act is bought.
                && $job->outlineOnly === true
                && $job->actsOnly === [];
        });
    }

    public function test_the_press_carries_a_released_cast_through_to_the_job(): void
    {
        Queue::fake();

        $story = $this->outlinedStory();

        Livewire::test(OutlineGate::class, ['story' => $story->fresh()])
            ->set('keepCast', false)
            ->call('askToReOutline')
            ->call('reOutline');

        Queue::assertPushed(WriteStoryJob::class, fn (WriteStoryJob $job): bool => $job->keepCast === false);
    }

    /**
     * The flag has to ARRIVE, not merely be passed. The fake records it for
     * the reason it records the phase and the beat: a request the prompt never
     * receives is a request that does not arrive, and nothing would fail.
     */
    public function test_the_job_rewrites_the_outline_and_hands_the_flag_to_the_writer(): void
    {
        $story = $this->outlinedStory();
        $before = $story->acts()->pluck('id')->all();

        (new WriteStoryJob($story->id, null, [], true, true, false))
            ->handle(app(GenerateOutline::class), app(\App\Actions\GenerateActScripts::class));

        $call = collect($this->writer->calls)->firstWhere('method', 'outline');

        $this->assertNotNull($call, 'The job did not re-outline at all.');
        $this->assertFalse($call['keep_cast'], 'keepCast never reached the writer.');

        // Replaced, not appended to.
        $after = $story->fresh()->acts()->pluck('id')->all();
        $this->assertCount(count($before), $after);
        $this->assertSame([], array_intersect($before, $after));
    }

    /**
     * Without the flag the job still keeps an existing outline, which is what
     * makes a resume a resume. Pressing "write the acts" must never rewrite
     * the outline underneath them.
     */
    public function test_an_ordinary_press_still_keeps_the_outline(): void
    {
        $story = $this->outlinedStory();
        $before = $story->acts()->pluck('id')->all();

        (new WriteStoryJob($story->id, null, [], true))
            ->handle(app(GenerateOutline::class), app(\App\Actions\GenerateActScripts::class));

        $this->assertNull(collect($this->writer->calls)->firstWhere('method', 'outline'));
        $this->assertSame($before, $story->fresh()->acts()->pluck('id')->all());
    }

    /**
     * THE GUARD THAT READ THE ACT COUNT. The dispatcher asked "would this
     * press write the outline?" and answered it with "does this story have
     * acts?", which was the same answer right up until a press existed that
     * rewrites an outline on a story that has them. Both readers of that
     * question moved together; this is the one that would otherwise bill a
     * call for an outline that has to be written again.
     */
    public function test_a_reoutline_is_refused_without_an_ending_even_though_acts_exist(): void
    {
        Queue::fake();

        $story = $this->outlinedStory();
        $story->forceFill(['ending' => null])->save();

        $page = Livewire::test(OutlineGate::class, ['story' => $story->fresh()])
            ->call('askToReOutline')
            ->assertSet('confirmingReOutline', false);

        $this->assertSame(GenerateOutline::NO_ENDING, $page->instance()->problem);

        Queue::assertNotPushed(WriteStoryJob::class);
    }

    /**
     * The same guard one layer down, where the act-count reading actually
     * lived. The component's check above would keep this test green on its
     * own, so the dispatcher gets its own case — a fix asserted only at the
     * surface that happens to call it first is a fix nothing holds.
     */
    public function test_the_dispatcher_refuses_a_reoutline_with_no_ending(): void
    {
        Queue::fake();

        $story = $this->outlinedStory();
        $story->forceFill(['ending' => null])->save();

        try {
            app(\App\Actions\DispatchTextStage::class)->writeScript(
                story: $story->fresh(),
                outlineOnly: true,
                checkWorkers: false,
                reOutline: true,
            );
            $this->fail('A re-outline with no ending was queued.');
        } catch (\App\Exceptions\DispatchRefusedException $e) {
            $this->assertSame(GenerateOutline::NO_ENDING, $e->getMessage());
        }

        Queue::assertNotPushed(WriteStoryJob::class);
    }

    /** And the ordinary acts press on the same story is still let through. */
    public function test_the_dispatcher_still_lets_an_acts_press_past_a_null_ending(): void
    {
        Queue::fake();

        $story = $this->outlinedStory();
        $story->forceFill(['ending' => null])->save();

        app(\App\Actions\DispatchTextStage::class)->writeScript(
            story: $story->fresh(),
            actsOnly: [1],
            checkWorkers: false,
        );

        Queue::assertPushed(WriteStoryJob::class);
    }

    private function outlinedStory(): Story
    {
        $story = Story::factory()->status(StoryStatus::Draft)->create($this->attributes());

        app(GenerateOutline::class)->handle($story);

        $story->refresh();
        $this->assertTrue($story->acts()->exists(), 'The fixture must have acts for any of this to mean anything.');
        $this->assertFalse($story->hasWrittenActs(), 'And no scripts, or the press under test is refused.');

        // The fixture has to WRITE an outline to produce a story that has one,
        // so its own call sits in the log ahead of the one under test — and
        // `firstWhere('outline')` found the setup's, which carries keep_cast
        // true whatever the press does. A fixture that answers the question it
        // is setting up is the vacuous-assertion shape; clearing the log is
        // what makes "the writer was handed X" a claim about the press.
        $this->writer->calls = [];

        return $story;
    }

    /** @return array<string, mixed> */
    private function attributes(): array
    {
        return [
            'format' => StoryFormat::Single,
            'locale_profile' => 'en-US',
            'ending' => StoryEnding::NewLife,
            'partner_end_state' => PartnerEndState::Together,
            'premise' => 'My wife held her intern\'s hand at her thirtieth birthday and told the table I had been gone for years.',
        ];
    }
}
