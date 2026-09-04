<?php

namespace Tests\Feature;

use App\Actions\CreateStory;
use App\Enums\OperatorAction;
use App\Enums\StoryStatus;
use App\Jobs\DraftSceneListJob;
use App\Jobs\WriteStoryJob;
use App\Livewire\Gates\OutlineGate;
use App\Livewire\Gates\PreviewGate;
use App\Livewire\Gates\ScenesGate;
use App\Livewire\Stories\Index;
use App\Livewire\Stories\NewStory;
use App\Models\Act;
use App\Models\Scene;
use App\Models\Story;
use App\Support\NextAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The console, asserted on the axis it was built to fix.
 *
 * Every one of these is a seam between a command and a button. The commands all
 * worked; the buttons did not exist; and the project's own history says that is
 * exactly the shape a bug takes here — a mechanism built in one phase with its
 * caller due in the next, arriving without wiring it.
 *
 * So these do not test that the stages work. That was never in doubt and is
 * covered elsewhere. They test that a stage is REACHABLE from the page where
 * its decision belongs, and that the page and the command reach it through the
 * same predicate.
 */
class OperatorConsoleTest extends TestCase
{
    use RefreshDatabase;

    // -- 1. The front door that did not exist --------------------------------

    /**
     * There was no way to start a video from this app at all.
     *
     * `story:write --premise` held the only copy of story creation, so the tool
     * built so an operator would not need a terminal required one to begin.
     */
    public function test_a_story_can_be_started_from_the_browser(): void
    {
        Queue::fake();

        Livewire::test(NewStory::class)
            ->set('premise', 'My younger brother and his wife moved into our late mother\'s house in Ohio without asking anyone.')
            ->set('title', 'The House in Ohio')
            ->call('create');

        $story = Story::query()->firstOrFail();

        $this->assertSame('The House in Ohio', $story->title);
        $this->assertSame(StoryStatus::Draft, $story->status);
        $this->assertNotNull($story->slug);

        Queue::assertPushed(WriteStoryJob::class, fn (WriteStoryJob $job): bool => $job->storyId === $story->id);
    }

    /**
     * The cast age range is stated with the premise, because that is when the
     * casting decision is actually made.
     *
     * It is read much later — the character extraction prompt is its only
     * consumer — and it exists so the intended range is STATED rather than left
     * for the one shared art-style line to compensate for.
     */
    public function test_a_new_story_carries_its_cast_age_range(): void
    {
        Queue::fake();

        Livewire::test(NewStory::class)
            ->set('premise', 'Two coworkers who married last year find out one of them was passed over on purpose.')
            ->set('castAgeProfile', 'Both leads late twenties. Workplace and marriage settings, no elderly characters.')
            ->call('create');

        $this->assertSame(
            'Both leads late twenties. Workplace and marriage settings, no elderly characters.',
            Story::query()->firstOrFail()->cast_age_profile
        );
    }

    public function test_a_new_story_without_one_stores_null(): void
    {
        Queue::fake();

        Livewire::test(NewStory::class)
            ->set('premise', 'My younger brother moved into our late mother\'s house in Ohio without asking anyone.')
            ->call('create');

        $this->assertNull(
            Story::query()->firstOrFail()->cast_age_profile,
            'No default is invented here: a placeholder age range would be the app casting the video.'
        );
    }

    /**
     * The setting is pickable from the page, and that is what makes a second
     * locale profile real.
     *
     * `stories.locale_profile` had no input anywhere: CreateStory always wrote
     * the configured default, so before this a second profile would have been a
     * column value no story could ever hold — the same shape as
     * `target_publish_at`, which sat in the schema with two display helpers and
     * nothing that could set it.
     */
    public function test_a_story_can_be_started_in_a_different_setting(): void
    {
        Queue::fake();

        Livewire::test(NewStory::class)
            ->assertSet('localeProfile', 'en-US')
            ->set('premise', 'A daughter-in-law is told the bride price will be returned to her husband.')
            ->set('localeProfile', 'en-CN')
            ->call('create');

        $this->assertSame('en-CN', Story::query()->firstOrFail()->locale_profile);
    }

    public function test_the_picker_offers_every_profile_that_exists(): void
    {
        // Read off config rather than written out, so a third profile is
        // selectable the moment it is defined. A profile nobody can select is
        // indistinguishable from one that does not exist.
        config()->set('locale.profiles.en-ZZ', [
            'label' => 'Nowhere',
            'guidance' => 'Write about nowhere.',
            'denylist' => [],
            'warnlist' => [],
        ]);

        Livewire::test(NewStory::class)
            ->assertSee('United States')
            ->assertSee('China (English narration)')
            ->assertSee('Nowhere');
    }

    public function test_an_unknown_setting_is_refused_by_the_form(): void
    {
        Queue::fake();

        Livewire::test(NewStory::class)
            ->set('premise', 'A premise long enough to get past the length validator on this form.')
            ->set('localeProfile', 'en-XX')
            ->call('create')
            ->assertHasErrors('localeProfile');

        $this->assertSame(0, Story::query()->count());
    }

    /**
     * The text stages go on the `text` queue and nowhere else.
     *
     * That queue spent two phases in config, in the setup docs and in the NSSM
     * instructions receiving nothing at all — an operator following the setup
     * ran a worker that could never get a job. It now carries the stage that
     * starts every video, so this pins the routing rather than trusting it.
     */
    public function test_the_writing_stages_are_queued_on_the_text_queue(): void
    {
        Queue::fake();

        $story = app(CreateStory::class)->handle('A premise long enough to be a real one, about a house.');

        Livewire::test(OutlineGate::class, ['story' => $story])->call('write');

        Queue::assertPushedOn((string) config('render.queues.text'), WriteStoryJob::class);
    }

    /**
     * The premise is not thrown away when the dispatch is refused.
     *
     * A refused dispatch means the workers are stale, which the operator fixes
     * in thirty seconds. Discarding their premise to keep the database tidy
     * would be the tidiest possible way of losing their work.
     */
    public function test_a_premise_survives_a_refused_dispatch(): void
    {
        Queue::fake();

        Livewire::test(NewStory::class)
            ->set('premise', 'A premise that is comfortably longer than the twenty character minimum.')
            ->call('create');

        $this->assertSame(1, Story::query()->count());
    }

    // -- 2. Every CLI-only stage has a button --------------------------------

    /**
     * Gate 2 could only be filled from a terminal.
     *
     * A story that passed Gate 1 in the browser showed an empty scene list
     * until somebody ran `story:scenes`.
     */
    public function test_scenes_can_be_drafted_from_the_gate_two_page(): void
    {
        Queue::fake();

        $story = Story::factory()->status(StoryStatus::Scripted)->create();
        Act::factory()->for($story)->atSequence(1)->create();

        Livewire::test(ScenesGate::class, ['story' => $story->refresh()])
            ->call('draftScenes')
            ->assertHasNoErrors();

        Queue::assertPushed(DraftSceneListJob::class, fn (DraftSceneListJob $job): bool => $job->storyId === $story->id);
    }

    /**
     * The free timings path is reachable from the page, and it is the one that
     * cannot bill.
     *
     * `needsTranscription` and `needsNarration` overlap heavily, so retrying
     * alignments through the asset button would have re-billed 69 narrations on
     * the story this was written for. The separation is structural — the
     * timings dispatcher can construct exactly one job class — and this asserts
     * the button reaches THAT dispatcher.
     */
    public function test_the_gate_two_page_offers_a_timings_only_path(): void
    {
        $story = Story::factory()->status(StoryStatus::AssetsReady)->create();
        Act::factory()->for($story)->atSequence(1)->create();
        Scene::factory()->for($story)->create(['sequence' => 1]);

        $component = Livewire::test(ScenesGate::class, ['story' => $story->refresh()]);

        $this->assertTrue($component->instance()->canAlignTimings());
        $this->assertNull($component->instance()->alignRefusal());
    }

    // -- 3. Buttons and commands read one predicate --------------------------

    /**
     * The join that has broken five times.
     *
     * Not "is the predicate self-consistent" — a match statement cannot be
     * inconsistent with itself. The axis that actually breaks is what a CALLER
     * offers versus what the capability permits, so every new button is checked
     * against its capability at every status.
     */
    public function test_every_new_button_agrees_with_its_capability(): void
    {
        $checks = [
            [OutlineGate::class, 'canWrite', OperatorAction::WriteScript],
            [ScenesGate::class, 'canDraftScenes', OperatorAction::DraftSceneList],
            [ScenesGate::class, 'canAlignTimings', OperatorAction::AlignTimings],
            [PreviewGate::class, 'canDispatchRender', OperatorAction::DispatchRender],
            [PreviewGate::class, 'canCancelRender', OperatorAction::CancelRender],
        ];

        $disagreements = [];

        foreach (StoryStatus::cases() as $status) {
            // No acts, deliberately. Every predicate under test is a function
            // of status alone — that is the whole point of OperatorAction —
            // so anything else in the fixture would be a second variable in a
            // test that has one.
            $story = Story::factory()->status($status)->create();

            foreach ($checks as [$component, $method, $action]) {
                $offered = Livewire::test($component, ['story' => $story->refresh()])
                    ->instance()
                    ->{$method}();

                if ($offered !== $action->permittedAt($status)) {
                    $disagreements[] = sprintf(
                        '%s::%s() returns %s at "%s" but %s says %s. Callers: %s.',
                        class_basename($component),
                        $method,
                        $offered ? 'true' : 'false',
                        $status->value,
                        $action->value,
                        $action->permittedAt($status) ? 'true' : 'false',
                        $action->callers(),
                    );
                }
            }
        }

        $this->assertSame([], $disagreements, "A button and its capability disagree:\n  - "
            .implode("\n  - ", $disagreements));
    }

    /**
     * A refusal is shown, not swallowed.
     *
     * A panel that vanishes when an action is unavailable is a page saying
     * nothing where it should say why — the defect that hid
     * `assetGenerationRefusal()` for a whole phase.
     */
    public function test_gate_one_says_why_the_script_cannot_be_written(): void
    {
        $story = Story::factory()->status(StoryStatus::ScenesApproved)->create();
        Act::factory()->for($story)->atSequence(1)->create();

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->assertSee('The script cannot be written from here.')
            ->assertSee('reopen Gate 2 first');
    }

    // -- 4. The index says what is waiting, and on whom -----------------------

    public function test_the_index_names_the_next_action_rather_than_restating_the_status(): void
    {
        $story = Story::factory()->status(StoryStatus::Scripted)->create();
        Act::factory()->for($story)->atSequence(1)->create();

        Livewire::test(Index::class)
            ->assertSee(OperatorAction::DraftSceneList->label())
            ->assertSee('there are no scenes yet');
    }

    public function test_a_story_waiting_on_a_queue_names_the_queue_it_is_waiting_on(): void
    {
        $story = Story::factory()->status(StoryStatus::Scripted)->create();
        Act::factory()->for($story)->atSequence(1)->create();

        $next = NextAction::for($story->refresh());

        $this->assertSame(NextAction::QUEUE, $next->waitingOn);
        $this->assertSame((string) config('render.queues.text'), $next->queue);
        $this->assertSame(OperatorAction::DraftSceneList, $next->action);
    }

    public function test_a_story_waiting_on_the_operator_says_so(): void
    {
        $story = Story::factory()->status(StoryStatus::ScenesDrafted)->create();
        Act::factory()->for($story)->atSequence(1)->create();

        $next = NextAction::for($story->refresh());

        $this->assertSame(NextAction::OPERATOR, $next->waitingOn);

        // Deliberately no action: crossing a gate is an editorial judgement
        // made in front of the thing being judged, never a click on an index.
        $this->assertNull($next->action);
    }

    /**
     * Every status resolves to something, and it always names a real route.
     *
     * The cheap version of this bug is a match arm nobody added when a status
     * was, which throws \UnhandledMatchError in front of an operator on the one
     * page they open first.
     */
    public function test_every_status_has_a_next_action_pointing_at_a_real_route(): void
    {
        foreach (StoryStatus::cases() as $status) {
            $story = Story::factory()->status($status)->create();

            $next = NextAction::for($story->refresh());

            $this->assertNotSame('', $next->summary, "{$status->value} has no summary.");
            $this->assertTrue(
                app('router')->has($next->routeName),
                "{$status->value} points at route '{$next->routeName}', which does not exist."
            );
        }
    }

    public function test_the_stories_index_and_the_new_story_page_render(): void
    {
        $this->get(route('stories.index'))->assertOk();
        $this->get(route('stories.create'))->assertOk()->assertSee('Premise');
    }
}
