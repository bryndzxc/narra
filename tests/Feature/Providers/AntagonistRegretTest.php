<?php

namespace Tests\Feature\Providers;

use Anthropic\Client;
use App\Actions\DraftScenes;
use App\Actions\GenerateActScripts;
use App\Actions\GenerateOutline;
use App\Actions\ValidateOutlineSpine;
use App\Contracts\ScriptWriter;
use App\Enums\ActPhase;
use App\Enums\Gate;
use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use App\Livewire\Gates\OutlineGate;
use App\Models\Act;
use App\Models\Character;
use App\Models\Story;
use App\Services\Claude\ClaudeScriptWriter;
use App\Services\Fake\FakeScriptWriter;
use App\Support\AntagonistPointOfView;
use App\Support\ChapterAnnouncement;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use App\Support\Providers\ActOutline;
use App\Support\Providers\ScriptWriterException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * THE ANTAGONIST'S REGRET, IN THE ANTAGONIST'S OWN CLOSING CHAPTER — every
 * consumer of `stories.antagonist_regret` and `chapters.point_of_view`, asked
 * in the change.
 *
 *   outline schema + prompt   produces it, after the refusal, "about a year"
 *   GenerateOutline           stores it (empty clears), clears the age flag
 *   genre contract            "I", never "she" — with the one exception named
 *   refusal act prompt        her chapter, last, announced, one year, the regret
 *   every other act prompt    nothing: an escalation act would stage the offer
 *   chapter instruction       her chapter speaks no number and is not counted
 *   act schema                point_of_view on every chapter
 *   GenerateActScripts        stores it; refuses two, misplaced, misnamed, or in
 *                             the wrong act; does NOT count it toward the bound
 *   refusal scene call        who the "I" is, and the year drawn in objects
 *   Gate 1                    editable, badge naming the day it was offered,
 *                             the chapter badged, warnings for the missing
 *                             chapter and a missing announcement
 *   story:fork                copies the field and its age
 *   ExtractCharacters         nothing: it reads names, and "I" is not one
 *
 * Red/green pairs for the two Gate 1 spine checks live in GuardsGoRedTest.
 */
class AntagonistRegretTest extends TestCase
{
    use RefreshDatabase;

    private FakeScriptWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(ScriptWriter::class);
    }

    // -- Produced and stored -------------------------------------------------

    public function test_the_outline_produces_and_stores_it_and_clears_the_age_flag(): void
    {
        $story = $this->draftStory();
        $story->forceFill(['outlined_before_antagonist_regret' => true])->save();

        app(GenerateOutline::class)->handle($story);

        $story->refresh();
        $this->assertStringContainsString('parking lot', (string) $story->antagonist_regret);
        $this->assertFalse($story->outlined_before_antagonist_regret);
        $this->assertNotNull(AntagonistPointOfView::nameFor($story));
    }

    public function test_a_regenerated_outline_with_no_regret_clears_the_old_one(): void
    {
        $story = $this->outlinedStory();
        $this->assertNotSame('', trim((string) $story->antagonist_regret));

        $story->acts()->delete();
        $story->forceFill(['status' => StoryStatus::Draft])->save();
        $this->writer->accompliceOverride = ['antagonist_regret' => ''];

        app(GenerateOutline::class)->handle($story->refresh());

        $this->assertNull($story->refresh()->antagonist_regret);
    }

    public function test_the_schema_requires_it_after_the_refusal(): void
    {
        $schema = $this->invoke($this->claude(), 'outlineSchema');
        $keys = array_keys($schema['properties']);

        $this->assertContains('antagonist_regret', $schema['required']);
        $this->assertGreaterThan(array_search('refusal', $keys, true), array_search('antagonist_regret', $keys, true));
    }

    public function test_the_outline_prompt_asks_for_the_chance_and_about_a_year(): void
    {
        $prompt = $this->flat($this->invoke($this->claude(), 'outlinePrompt', $this->draftStory(), 5));

        $this->assertStringContainsString('- antagonist_regret: THE ANTAGONIST\'S LAST CHANCE', $prompt);
        $this->assertStringContainsString('ABOUT A YEAR, NOT TEN OR TWENTY', $prompt);
        $this->assertStringContainsString('tie it to an event above, by name', $prompt);
    }

    // -- The prompt ------------------------------------------------------------

    public function test_the_genre_contract_names_the_one_exception_to_i_never_she(): void
    {
        $guidance = $this->flat($this->invoke($this->claude(), 'genreGuidance', $this->draftStory()));

        $this->assertStringContainsString('"I", never "she". ONE EXCEPTION, and only where the final act\'s instructions name it', $guidance);
        $this->assertStringContainsString('inside it "I" is the antagonist', $guidance);
    }

    public function test_the_refusal_act_ends_on_her_chapter_and_no_other_act_is_told(): void
    {
        $story = $this->outlinedStory();
        $name = AntagonistPointOfView::nameFor($story);

        foreach ($story->acts()->orderBy('sequence')->get() as $act) {
            $prompt = $this->flat($this->actPromptFor($story, $act->sequence));

            if ($act->phase === ActPhase::Refusal) {
                $this->assertStringContainsString("THEN, LAST, THE ANTAGONIST'S CHAPTER", $prompt);
                $this->assertStringContainsString("inside it, \"I\" is {$name}, not the narrator", $prompt);
                $this->assertStringContainsString('ABOUT A YEAR after the refusal — not ten or twenty years', $prompt);
                $this->assertStringContainsString(ChapterAnnouncement::pointOfViewSentence($name), $prompt);
                $this->assertStringContainsString($this->flat((string) $story->antagonist_regret), $prompt);
                $this->assertStringContainsString("its point_of_view is exactly \"{$name}\"", $prompt);
                $this->assertStringContainsString('THE LAST CHAPTER OF THIS ACT IS THE EXCEPTION', $prompt);
                $this->assertStringNotContainsString('No chapter is told from anybody else\'s point of view', $prompt);
            } else {
                $this->assertStringNotContainsString($this->flat((string) $story->antagonist_regret), $prompt, "Act {$act->sequence} is handed the regret.");
                $this->assertStringNotContainsString('THE LAST CHAPTER OF THIS ACT IS THE EXCEPTION', $prompt);
            }
        }
    }

    public function test_a_story_without_a_regret_gets_the_refusal_act_it_had(): void
    {
        $story = $this->outlinedStory();
        $story->forceFill(['antagonist_regret' => null])->save();
        $refusal = $story->acts()->where('phase', ActPhase::Refusal)->firstOrFail();

        $prompt = $this->flat($this->actPromptFor($story->refresh(), $refusal->sequence));

        $this->assertStringContainsString('No chapter is told from anybody else\'s point of view', $prompt);
        $this->assertStringNotContainsString('THE ANTAGONIST\'S CHAPTER', $prompt);
        $this->assertStringNotContainsString('IS THE EXCEPTION, and the final block below sets', $prompt);
    }

    /**
     * THE PATH THAT SHIPS decodes both, not only the fake.
     *
     * Found by a drill passing: removing `antagonist_regret` from the real
     * writer's decode left every test green, because every test runs on the
     * fake writer, which builds its own draft. So the real decode is exercised
     * here, with a decoded response and no stream — and it covers every spine
     * field, since none of them had a test on this path either.
     */
    public function test_the_real_writer_decodes_the_regret_the_spine_and_whose_chapter_it_is(): void
    {
        $story = $this->draftStory();
        $usage = \App\Support\Providers\ProviderUsage::simulated(
            operation: 'generate_outline',
            category: \App\Enums\CostCategory::Text,
            quantity: 0.0,
            unit: \App\Enums\CostUnit::TotalTokens,
        );

        $spine = [
            'hook' => 'H', 'narrator_grievance' => 'G', 'antagonist_justification' => 'J',
            'accomplice_motive' => 'AM', 'accomplice_performance' => 'AP', 'betrayal_scene' => 'B',
            'withheld_information' => 'W', 'exposure_moment' => 'E', 'narrator_at_exposure' => 'N',
            'departure' => 'D', 'reversal_beats' => 'R', 'accomplice_fall' => 'AF',
            'running_thought' => 'T', 'refusal' => 'X', 'antagonist_regret' => 'The chance, a year on.',
        ];

        $draft = $this->invoke($this->claude(), 'outlineDraftFrom', $story, 5, $spine + [
            'title' => 'T',
            'cast' => [],
            'acts' => [['title' => 'A', 'summary' => 'S', 'escalation_beat' => 'C', 'timeframe' => 'present']],
        ], $usage);

        $this->assertSame($spine, $draft->spine());

        $act = $this->invoke($this->claude(), 'actDraftFrom', new ActOutline(sequence: 5, title: 'A', summary: 'S'), [
            'summary' => 'S',
            'chapters' => [
                ['title' => 'One', 'rehook_line' => 'R.', 'text' => 'Chapter one. R.', 'point_of_view' => ''],
                ['title' => 'A Year On', 'rehook_line' => 'W.', 'text' => 'Extra — Dana\'s point of view. W.', 'point_of_view' => 'Dana'],
            ],
        ], $usage);

        $this->assertSame(['', 'Dana'], array_map(fn ($c) => $c->pointOfView, $act->chapters));
    }

    public function test_the_act_schema_asks_every_chapter_whose_it_is(): void
    {
        $schema = $this->invoke($this->claude(), 'actSchema');

        $this->assertContains('point_of_view', $schema['properties']['chapters']['items']['required']);
    }

    // -- The act call ----------------------------------------------------------

    public function test_the_refusal_act_stores_her_chapter_last_and_it_is_not_counted_toward_the_bound(): void
    {
        config()->set('chapters.max_per_act', 2);
        $story = $this->writtenStory();

        $refusal = $story->acts()->where('phase', ActPhase::Refusal)->firstOrFail();
        $chapters = $refusal->chapters()->orderBy('sequence')->get();

        // Two narrated chapters at a maximum of two, plus hers: stored, not refused.
        $this->assertCount(3, $chapters);
        $this->assertSame(AntagonistPointOfView::nameFor($story), $chapters->last()->point_of_view);
        $this->assertSame(1, $story->chapters()->whereNotNull('point_of_view')->count());
    }

    public function test_every_act_writer_call_is_handed_the_regret(): void
    {
        $story = $this->writtenStory();

        $calls = collect($this->writer->calls)->where('method', 'actScript');
        $this->assertNotEmpty($calls);
        $this->assertTrue($calls->every(fn (array $call): bool => $call['antagonist_regret'] === $story->antagonist_regret));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function malformedChapters(): array
    {
        return [
            'placed first, not last' => ['pointOfViewFirst', 'position 1 of 3'],
            'told under the wrong name' => ['pointOfViewName', 'where "'],
            'in an act not asked for one' => ['pointOfViewInAnyAct', 'not asked for one'],
        ];
    }

    /**
     * RED, three shapes; GREEN is the case above, which differs only in the switch.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedChapters')]
    public function test_a_malformed_point_of_view_chapter_is_refused_after_the_cost_row(string $switch, string $says): void
    {
        $story = $this->outlinedStory();
        $story->approveGate(Gate::Outline);
        $target = $switch === 'pointOfViewInAnyAct'
            ? $story->acts()->where('phase', ActPhase::Escalation)->orderBy('sequence')->firstOrFail()
            : $story->acts()->where('phase', ActPhase::Refusal)->firstOrFail();

        // Every earlier act written cleanly, then the switch on the target act.
        foreach ($story->acts()->where('sequence', '<', $target->sequence)->orderBy('sequence')->pluck('sequence') as $sequence) {
            app(GenerateActScripts::class)->handle($story->refresh(), [$sequence]);
        }

        if ($switch === 'pointOfViewName') {
            $this->writer->pointOfViewName = 'Somebody Else';
        } else {
            $this->writer->{$switch} = true;
        }

        try {
            app(GenerateActScripts::class)->handle($story->refresh(), [$target->sequence]);
            $this->fail("The {$switch} shape should have been refused.");
        } catch (ScriptWriterException $e) {
            $this->assertStringContainsString($says, $e->getMessage());
        }

        $this->assertNull($target->refresh()->script);
    }

    public function test_a_refusal_act_without_her_chapter_is_stored_and_reported_at_gate_one(): void
    {
        $story = $this->outlinedStory();
        $story->approveGate(Gate::Outline);
        $refusal = $story->acts()->where('phase', ActPhase::Refusal)->firstOrFail();

        foreach ($story->acts()->orderBy('sequence')->pluck('sequence') as $sequence) {
            $this->writer->omitPointOfView = $sequence === $refusal->sequence;
            app(GenerateActScripts::class)->handle($story->refresh(), [$sequence]);
        }

        $this->assertNotNull($refusal->refresh()->script);

        $warnings = app(ValidateOutlineSpine::class)->handle($story->refresh())['warnings'];
        $this->assertNotEmpty(array_filter($warnings, fn (string $w): bool => str_contains($w, "Act {$refusal->sequence} ends without")));
    }

    public function test_her_chapter_without_its_announcement_is_reported_and_takes_no_number(): void
    {
        $story = $this->writtenStory();
        $refusal = $story->acts()->where('phase', ActPhase::Refusal)->firstOrFail();
        $hers = $refusal->chapters()->whereNotNull('point_of_view')->firstOrFail();

        // GREEN: as written, no announcement warning names her chapter, and
        // no numbered chapter is reported out of step by it.
        $warnings = app(ValidateOutlineSpine::class)->handle($story)['warnings'];
        $this->assertSame([], array_values(array_filter($warnings, fn (string $w): bool => str_contains($w, 'does not open by saying so') || str_contains($w, 'never says its number'))));

        // RED: the announcement removed from her chapter's prose.
        $refusal->update(['script' => str_replace(
            ChapterAnnouncement::pointOfViewSentence((string) $hers->point_of_view),
            'I kept the watch.',
            (string) $refusal->script,
        )]);

        $warnings = app(ValidateOutlineSpine::class)->handle($story->refresh())['warnings'];
        $this->assertNotEmpty(array_filter($warnings, fn (string $w): bool => str_contains($w, 'does not open by saying so')));
    }

    // -- The scene call ----------------------------------------------------------

    public function test_the_refusal_scene_call_is_told_whose_i_it_is_and_no_other_is(): void
    {
        $story = $this->writtenStory();
        $name = AntagonistPointOfView::nameFor($story);

        foreach (['Erin Vasquez', $name, 'Paul Ostrander'] as $character) {
            Character::factory()->for($story)->create(['name' => $character]);
        }

        $this->writer->calls = [];
        app(DraftScenes::class)->handle($story->refresh());

        $calls = collect($this->writer->calls)->where('method', 'scenes')->keyBy('act');

        foreach ($story->acts()->orderBy('sequence')->get() as $act) {
            $context = $this->flat($this->invoke($this->claude(), 'sceneContext', $story->refresh(), $act));

            if ($act->phase === ActPhase::Refusal) {
                $this->assertSame($name, $calls[$act->sequence]['point_of_view']);
                $this->assertStringContainsString("THE ANTAGONIST'S CHAPTER: from sentence", $context);
                $this->assertStringContainsString("\"I\" there is {$name}", $context);
                $this->assertStringContainsString('never aged', $context);
            } else {
                $this->assertNull($calls[$act->sequence]['point_of_view']);
                $this->assertStringNotContainsString('THE ANTAGONIST\'S CHAPTER', $context);
            }
        }
    }

    // -- Gate 1 and the fork -----------------------------------------------------

    public function test_gate_one_edits_it_and_names_the_day_it_was_offered(): void
    {
        $story = $this->outlinedStory();

        $review = app(ValidateOutlineSpine::class)->handle($story);
        $this->assertSame('ok', $review['spine']['antagonist_regret']['state']);
        $this->assertSame('the refusal', $review['spine']['antagonist_regret']['anchored_in']);

        Livewire::test(OutlineGate::class, ['story' => $story])
            ->assertSee('The antagonist\'s last chance, and a year on')
            ->assertSee('offered on the refusal')
            ->set('spine.antagonist_regret', 'At the reception in the parking lot, Dana told him to apologize. A year on, the watch is in its box.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertStringStartsWith('At the reception', (string) $story->refresh()->antagonist_regret);
    }

    public function test_gate_one_badges_her_chapter(): void
    {
        $story = $this->writtenStory();
        $story->forceFill(['status' => StoryStatus::Outlined])->save();

        Livewire::test(OutlineGate::class, ['story' => $story->refresh()])
            ->assertSee('told by '.AntagonistPointOfView::nameFor($story));
    }

    public function test_an_outline_written_before_it_was_asked_says_so_once(): void
    {
        $story = $this->outlinedStory();
        $story->forceFill(['antagonist_regret' => null, 'outlined_before_antagonist_regret' => true])->save();

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame('absent', $review['spine']['antagonist_regret']['state']);
        $this->assertSame([], array_values(array_filter($review['problems'], fn (string $p): bool => str_contains($p, 'last chance'))));
        $this->assertCount(1, array_filter($review['warnings'], fn (string $w): bool => str_contains($w, 'before it was asked for the antagonist\'s regret')));
    }

    public function test_a_missing_regret_is_a_problem_on_an_outline_that_was_asked(): void
    {
        $story = $this->outlinedStory();
        $story->forceFill(['antagonist_regret' => null, 'outlined_before_antagonist_regret' => false])->save();

        $review = app(ValidateOutlineSpine::class)->handle($story->refresh());

        $this->assertSame('missing', $review['spine']['antagonist_regret']['state']);
    }

    public function test_a_fork_carries_it_and_the_age_of_its_outline(): void
    {
        $source = $this->outlinedStory();
        $source->forceFill(['outlined_before_antagonist_regret' => true])->save();

        Artisan::call('story:fork', ['story' => $source->slug]);

        $fork = Story::query()->whereKeyNot($source->id)->latest('id')->firstOrFail();

        $this->assertSame($source->antagonist_regret, $fork->antagonist_regret);
        $this->assertTrue($fork->outlined_before_antagonist_regret);
    }

    // ---------------------------------------------------------------------------

    /**
     * Ends in the antagonist's voice: every case in this file is about that
     * ending, which since 2026-09-19 is chosen rather than implied by the
     * regret being written. EndingChoiceTest holds the other ending.
     */
    private function draftStory(): Story
    {
        return Story::factory()->endsInTheAntagonistsVoice()->status(StoryStatus::Draft)->create([
            'format' => StoryFormat::Single,
            'locale_profile' => 'en-US',
            'target_duration_min' => 30,
            'target_duration_max' => 40,
            'premise' => 'My sister billed me for her entire wedding over eleven months and told the '
                .'family I had offered, with the family adviser smoothing it over.',
        ]);
    }

    private function outlinedStory(): Story
    {
        $story = $this->draftStory();

        app(GenerateOutline::class)->handle($story);

        $this->writer->calls = [];
        $this->writer->accompliceOverride = [];

        return $story->refresh();
    }

    private function writtenStory(): Story
    {
        $story = $this->outlinedStory();
        $story->approveGate(Gate::Outline);

        app(GenerateActScripts::class)->handle($story->refresh());

        return $story->refresh();
    }

    private function actPromptFor(Story $story, int $sequence): string
    {
        $outline = $story->acts()->orderBy('sequence')->get()
            ->map(fn (Act $act): ActOutline => new ActOutline(
                sequence: $act->sequence,
                title: (string) $act->title,
                summary: (string) $act->summary,
                escalationBeat: (string) $act->escalation_beat,
                phase: $act->phase,
                timeframe: $act->timeframe,
            ))
            ->all();

        return $this->invoke(
            $this->claude(),
            'actPrompt',
            $story,
            $outline[$sequence - 1],
            $outline,
            $sequence === 1 ? [] : ['Act 1 happened.'],
            985,
        );
    }

    /** Whitespace collapsed: the prompts are wrapped, and a needle must not straddle a line. */
    private function flat(string $text): string
    {
        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    private function claude(): ClaudeScriptWriter
    {
        return new ClaudeScriptWriter(
            client: app(Client::class),
            locale: app(LocaleGuard::class),
            text: app(CharacterTextGuard::class),
        );
    }

    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target, ...$args);
    }
}
