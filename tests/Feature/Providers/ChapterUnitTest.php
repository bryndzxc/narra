<?php

namespace Tests\Feature\Providers;

use Anthropic\Client;
use App\Actions\DraftScenes;
use App\Actions\GenerateActScripts;
use App\Actions\GenerateOutline;
use App\Contracts\ScriptWriter;
use App\Enums\Gate;
use App\Enums\RenderStage;
use App\Enums\StoryStatus;
use App\Models\Chapter;
use App\Models\Character;
use App\Models\RenderJob;
use App\Models\Story;
use App\Services\Claude\ClaudeScriptWriter;
use App\Services\Fake\FakeScriptWriter;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use App\Support\Providers\ActOutline;
use App\Support\Providers\ScriptWriterException;
use App\Support\ScriptSizing;
use App\Support\SentenceSplitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The chapter: a unit UNDER the act.
 *
 * Measured against a working video in this niche: fourteen chapters in
 * 34:46, about 2:29 each, a re-hook at every one. Ours ran six acts of 5:54
 * to 9:44 with the first re-hook after the opening at 6:45-7:47, on a format
 * whose measured failure is a retention drop inside the first three minutes.
 *
 * The act stays the unit the script is WRITTEN in, because the writer
 * returns ~1,100 words of one whatever it is asked and fourteen acts of that
 * is a 75-minute video. The act is returned AS two or three chapters, its
 * script is their join, and each chapter is a sentence index into that
 * script — the unit DraftScenes cuts in — with a title and its own re-hook.
 *
 * What is asserted here is the plumbing, at every consumer, in the change
 * that added the field: the act call persists them, the bounds refuse after
 * the cost row, the scene call is handed the boundaries and files each scene
 * under its chapter, the render times them, and the metadata reads them.
 */
class ChapterUnitTest extends TestCase
{
    use RefreshDatabase;

    private FakeScriptWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(ScriptWriter::class);
    }

    // -- The act call persists chapters --------------------------------------

    public function test_every_act_is_stored_as_chapters_whose_boundaries_are_sentence_offsets(): void
    {
        $story = $this->outlinedStory();

        app(GenerateActScripts::class)->handle($story);

        $splitter = app(SentenceSplitter::class);
        $min = (int) config('chapters.min_per_act');

        foreach ($story->acts()->get() as $act) {
            $chapters = $act->chapters()->get();

            $this->assertGreaterThanOrEqual($min, $chapters->count(), "Act {$act->sequence} has too few chapters.");
            $this->assertSame(1, $chapters->first()->first_sentence, 'Chapter 1 of every act starts at sentence 1.');

            // The boundary is an offset into the act's own script, in the
            // splitter's unit — so the text from that sentence onward is
            // exactly the chapter the writer returned, and nothing stores a
            // second copy of it.
            $sentences = $splitter->split((string) $act->script);

            foreach ($chapters as $chapter) {
                $this->assertGreaterThanOrEqual(1, $chapter->first_sentence);
                $this->assertLessThanOrEqual(count($sentences), $chapter->first_sentence);
                $this->assertNotSame('', $chapter->title);
                $this->assertTrue($chapter->hasRehook(), "Chapter {$chapter->sequence} of act {$act->sequence} has no re-hook.");
            }

            $starts = $chapters->pluck('first_sentence')->all();
            $this->assertSame($starts, array_values(array_unique($starts)), 'Two chapters start on the same sentence.');
            $sorted = $starts;
            sort($sorted);
            $this->assertSame($sorted, $starts, 'Chapters are out of order.');
        }

        $this->assertSame(
            $story->acts()->count() * 2,
            $story->chapters()->count(),
            'The fake returns two chapters per act; every one of them must be stored.',
        );
    }

    /**
     * The story-wide count is what the measurement is about: six acts of the
     * writer's natural length should read as about fifteen chapters, and
     * `story:write` prints this figure so a run that came back with one
     * chapter per act is visible as one.
     */
    public function test_the_story_lists_its_chapters_in_act_order_then_chapter_order(): void
    {
        $story = $this->outlinedStory();

        app(GenerateActScripts::class)->handle($story);

        $order = $story->chapters()->get()->map(
            fn (Chapter $c): string => $c->act->sequence.'.'.$c->sequence,
        )->all();

        $sorted = $order;
        usort($sorted, fn (string $a, string $b): int => version_compare($a, $b));

        $this->assertSame($sorted, $order);
    }

    // -- The bounds refuse, after the cost row -------------------------------

    public function test_an_act_returned_as_one_chapter_is_refused_and_not_stored(): void
    {
        $story = $this->outlinedStory();
        $this->writer->chaptersForNextAct = 1;

        try {
            app(GenerateActScripts::class)->handle($story);
            $this->fail('One chapter per act is the shape being replaced and must be refused.');
        } catch (ScriptWriterException $e) {
            $this->assertStringContainsString('1 chapter(s) against a bound of', $e->getMessage());
            $this->assertStringContainsString('NOT stored', $e->getMessage());
        }

        $this->assertNull($story->acts()->where('sequence', 1)->value('script'));
        $this->assertSame(0, Chapter::query()->count());

        // Billed before it was refused: the ledger says what it cost.
        $this->assertSame(1, $story->costEntries()->where('operation', 'generate_act_script')->count());
    }

    public function test_a_chapter_under_the_word_floor_is_refused(): void
    {
        $story = $this->outlinedStory();

        // Many chapters out of a short fake act: each lands well under the
        // floor. The count is inside the bound, so the floor is what fires.
        config(['chapters.max_per_act' => 40, 'chapters.min_words' => 150]);
        $this->writer->chaptersForNextAct = 30;

        try {
            app(GenerateActScripts::class)->handle($story);
            $this->fail('A chapter under the word floor must be refused.');
        } catch (ScriptWriterException $e) {
            $this->assertStringContainsString('against a floor of 150', $e->getMessage());
        }

        $this->assertSame(0, Chapter::query()->count());
    }

    public function test_a_chapter_with_no_rehook_is_stored_and_named_on_the_job_row(): void
    {
        $story = $this->outlinedStory();
        $this->writer->suppressChapterRehooks = true;

        app(GenerateActScripts::class)->handle($story);

        $act = $story->acts()->where('sequence', 1)->first();

        $this->assertNotNull($act->script, 'A missing re-hook is a warning, not a refusal.');
        $this->assertNull($act->chapters()->first()->rehook_line);

        $log = (string) RenderJob::query()
            ->where('story_id', $story->id)
            ->where('stage', RenderStage::ActScripts)
            ->value('log');
        $this->assertStringContainsString('NO RE-HOOK on chapter 1, 2', $log);
    }

    /**
     * Rewriting one act replaces that act's chapters and no other's, and
     * needs no renumbering: the sequence is within the act.
     */
    public function test_rewriting_one_act_replaces_only_its_chapters(): void
    {
        $story = $this->outlinedStory();

        app(GenerateActScripts::class)->handle($story);

        $before = $story->chapters()->pluck('chapters.id', 'chapters.id')->all();
        $actOneBefore = $story->acts()->where('sequence', 1)->first()->chapters()->pluck('id')->all();

        app(GenerateActScripts::class)->handle($story->refresh(), only: [1]);

        $after = $story->chapters()->pluck('chapters.id', 'chapters.id')->all();
        $actOneAfter = $story->acts()->where('sequence', 1)->first()->chapters()->pluck('id')->all();

        $this->assertSame(count($before), count($after));
        $this->assertEmpty(array_intersect($actOneBefore, $actOneAfter), 'Act 1 should carry NEW chapter rows.');
        $this->assertSame(
            array_diff_key($before, array_flip($actOneBefore)),
            array_diff_key($after, array_flip($actOneAfter)),
            'Every other act must keep the chapter rows it had.',
        );
    }

    // -- The scene call ------------------------------------------------------

    public function test_the_scene_writer_is_handed_the_chapter_boundaries(): void
    {
        $story = $this->scriptedStoryWithCast();

        $this->writer->calls = [];

        app(DraftScenes::class)->handle($story);

        $calls = collect($this->writer->calls)->where('method', 'scenes');

        $this->assertNotEmpty($calls);
        $this->assertTrue(
            $calls->every(fn (array $call): bool => count($call['chapter_boundaries']) >= 2),
            'The scene call was not handed the chapter boundaries. A scene straddling one puts the '
            .'previous chapter\'s still under the next chapter\'s opening line.',
        );
    }

    public function test_every_scene_is_filed_under_the_chapter_its_first_sentence_is_in(): void
    {
        $story = $this->scriptedStoryWithCast();

        app(DraftScenes::class)->handle($story);

        $this->assertGreaterThan(0, $story->scenes()->count());
        $this->assertSame(0, $story->scenes()->whereNull('chapter_id')->count(), 'A scene was drafted with no chapter.');

        // Each chapter has at least one scene, and its first scene is the
        // earliest scene at or after its boundary — which is what the render
        // will time the chapter from.
        foreach ($story->chapters()->get() as $chapter) {
            $this->assertGreaterThan(0, $chapter->scenes()->count(), "Chapter {$chapter->title} has no scene.");
        }
    }

    public function test_a_scene_straddling_a_boundary_is_filed_and_said_rather_than_refused(): void
    {
        $story = $this->scriptedStoryWithCast();

        // One scene for the whole act: it necessarily crosses the boundary
        // between chapter 1 and chapter 2.
        $this->writer->sceneRangeOverride = fn (int $total): array => [[1, $total]];

        app(DraftScenes::class)->handle($story);

        $log = (string) RenderJob::query()->where('story_id', $story->id)->latest('id')->value('log');

        $this->assertStringContainsString('straddle a chapter boundary', $log);
        $this->assertStringContainsString('crosses into', $log);
    }

    /**
     * The render's numbers, written once, one level down from the act. The
     * chapter's start is its first scene's offset and its duration is its
     * scenes' frames summed — the same arithmetic the act uses.
     */
    public function test_the_render_times_each_chapter_from_its_scenes(): void
    {
        $story = $this->scriptedStoryWithCast();
        app(DraftScenes::class)->handle($story);

        $fps = (int) config('render.video.fps');
        $plan = [];
        $offset = 0;

        foreach ($story->scenes()->get() as $scene) {
            $frames = 300; // 10 s at 30 fps
            $plan[] = [
                'scene_id' => $scene->id,
                'scene_audio_id' => 0,
                'act_id' => $scene->act_id,
                'chapter_id' => $scene->chapter_id,
                'frames' => $frames,
                'padded_duration_ms' => (int) round($frames / $fps * 1000),
                'offset_frames' => $offset,
                'offset_samples' => 0,
                'offset_ms' => (int) round($offset / $fps * 1000),
            ];
            $offset += $frames;
        }

        $job = (new \ReflectionClass(\App\Jobs\ConcatRenderJob::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($job, 'persistTimeline');
        $method->invoke($job, $story, $plan);

        $chapters = $story->chapters()->get();
        $expectedStart = 0;

        foreach ($chapters as $chapter) {
            $this->assertSame($expectedStart, $chapter->fresh()->start_ms, "Chapter {$chapter->title} starts at the wrong offset.");
            $this->assertSame($chapter->scenes()->count() * 10_000, $chapter->fresh()->duration_ms);
            $expectedStart += $chapter->fresh()->duration_ms;
        }

        // And the acts still carry their own, unchanged in shape.
        $this->assertSame(0, $story->acts()->where('sequence', 1)->value('start_ms'));
    }

    // -- The prompts ---------------------------------------------------------

    public function test_the_act_prompt_asks_for_chapters_with_the_bounds_from_config(): void
    {
        $story = Story::factory()->single()->create();
        $writer = $this->realWriterWithNoClient();

        $act = new ActOutline(sequence: 2, title: 'Two', summary: 'Two.');
        $outline = [new ActOutline(sequence: 1, title: 'One', summary: 'One.'), $act];

        $prompt = $this->invoke($writer, 'actPrompt', $story, $act, $outline, ['Act 1.'], 985);

        $this->assertStringContainsString('WRITE THIS ACT AS CHAPTERS', $prompt);
        $this->assertStringContainsString(
            sprintf('Never fewer than %d and never more than %d', config('chapters.min_per_act'), config('chapters.max_per_act')),
            $prompt,
        );
        $this->assertStringContainsString(sprintf('no chapter under %d words', config('chapters.min_words')), $prompt);
        $this->assertStringContainsString('EVERY CHAPTER OPENS WITH ITS OWN RE-HOOK', $prompt);
        $this->assertStringContainsString(number_format(ScriptSizing::chapterTargetWords($story)).' words', $prompt);
        $this->assertStringContainsString('- chapters:', $prompt);

        // ON, from the transcript: the reference speaks "chapter 1" at 1:02,
        // "chapter 2" at 4:29 and so on, as a bare number with no title, and
        // its cold open comes before "chapter 1".
        $this->assertTrue((bool) config('chapters.announce'));
        $this->assertStringContainsString('EVERY CHAPTER OPENS BY SPEAKING ITS NUMBER', $prompt);
        $this->assertStringContainsString('The title is never spoken', $prompt);
        $this->assertStringNotContainsString('SPEAKS its title', $prompt);

        config(['chapters.announce' => false]);
        $silent = $this->invoke($writer, 'actPrompt', $story, $act, $outline, ['Act 1.'], 985);
        $this->assertStringContainsString('The title is not spoken', $silent);
        $this->assertStringNotContainsString('SPEAKING ITS NUMBER', $silent);
    }

    /**
     * The spoken number is story-wide, read from the chapters the earlier
     * acts actually stored — and act 1 is told the announcement follows the
     * hook, because the reference's cold open (0:00-1:02) precedes "chapter 1".
     */
    public function test_the_spoken_chapter_number_continues_from_the_acts_before(): void
    {
        $story = $this->outlinedStory();
        app(GenerateActScripts::class)->handle($story, only: [1, 2]);

        $writer = $this->realWriterWithNoClient();
        $acts = $story->acts()->orderBy('sequence')->get();
        $outline = $acts->map(fn ($a) => new ActOutline(sequence: $a->sequence, title: $a->title, summary: (string) $a->summary))->all();

        $first = $this->invoke($writer, 'actPrompt', $story, $outline[0], $outline, [], 985);
        $this->assertStringContainsString('this act\'s first chapter is chapter 1', $first);
        $this->assertStringContainsString('"Chapter one." comes after it', $first);

        // Acts 1 and 2 stored two chapters each, so act 3 opens on chapter 5.
        $third = $this->invoke($writer, 'actPrompt', $story, $outline[2], $outline, ['Act 1.', 'Act 2.'], 985);
        $this->assertStringContainsString('this act\'s first chapter is chapter 5', $third);
        $this->assertStringContainsString('"Chapter five."', $third);
        $this->assertStringNotContainsString('THIS ACT IS THE EXCEPTION', $third);
    }

    /**
     * THE COUNT IS DERIVED BY THE WRITER, NOT STATED TO IT.
     *
     * Story 30 came back as two chapters per act every time, including the
     * act that ran to 1,195 words where the honest answer is three, because
     * the prompt said "at this act's length that is 2 chapters" from the word
     * TARGET and the writer obeyed the stated number rather than the length
     * it had written.
     *
     * That is the word-target finding with the sign flipped and the pair is
     * the rule: a stated FIGURE steers weakly (+0.30 words per word asked); a
     * stated COUNT steers absolutely, 6 times out of 6. So the prompt states
     * the divisor and the bounds and asks for the division to be done
     * afterwards, against the text that exists.
     */
    public function test_the_act_prompt_states_the_divisor_and_never_a_count_for_this_act(): void
    {
        $story = Story::factory()->single()->create();
        $writer = $this->realWriterWithNoClient();

        $act = new ActOutline(sequence: 2, title: 'Two', summary: 'Two.');
        $outline = [new ActOutline(sequence: 1, title: 'One', summary: 'One.'), $act];

        $prompt = $this->invoke($writer, 'actPrompt', $story, $act, $outline, ['Act 1.'], 985);

        $this->assertStringContainsString('HOW MANY CHAPTERS IS DECIDED BY THE LENGTH YOU ACTUALLY WRITE', $prompt);
        $this->assertStringContainsString(
            sprintf('divide the words you wrote by %s and round', number_format(ScriptSizing::chapterTargetWords($story))),
            $prompt,
        );

        // The stated figure that remains is the MEASURED act length, not the
        // target — an example built from the target would be the stated count
        // again wearing a different hat.
        $this->assertStringContainsString(
            sprintf('the measured length is about %s words', number_format(ScriptSizing::naturalActWords())),
            $prompt,
        );
        $this->assertStringNotContainsString('At this act\'s length that is', $prompt);
        $this->assertStringNotContainsString('985 words is', $prompt);

        // And the two changes together are what move the cadence: at the
        // transcript's measured 133-second chapter the writer's own act
        // length divides into three rather than two. The derivation alone,
        // at the old 150, returned the number it replaced.
        $this->assertSame(
            3,
            ScriptSizing::chaptersPerAct($story, ScriptSizing::naturalActWords()),
            'A measured act should divide into three chapters at the configured budget.',
        );
    }

    public function test_the_act_schema_returns_chapters_and_carries_no_length_constraint(): void
    {
        $schema = $this->invoke($this->realWriterWithNoClient(), 'actSchema');

        $this->assertContains('chapters', $schema['required']);
        $this->assertSame(['title', 'rehook_line', 'text'], $schema['properties']['chapters']['items']['required']);
        $this->assertStringNotContainsString('minItems', json_encode($schema));
        $this->assertStringNotContainsString('maxLength', json_encode($schema));
    }

    public function test_the_scene_prompt_states_the_chapter_boundaries(): void
    {
        $story = $this->scriptedStoryWithCast();
        $act = $story->acts()->where('sequence', 1)->first();
        $boundary = $act->chapters()->where('sequence', 2)->first();

        $prompt = $this->invoke(
            $this->realWriterWithNoClient(),
            'scenePrompt',
            $story,
            $act,
            app(SentenceSplitter::class)->split((string) $act->script),
            [],
            10,
        );

        $this->assertStringContainsString('CHAPTER BOUNDARIES', $prompt);
        $this->assertStringContainsString(
            sprintf('sentence %d ("%s")', $boundary->first_sentence, $boundary->title),
            $prompt,
        );
        $this->assertStringContainsString('A scene NEVER straddles one', $prompt);
    }

    /**
     * The register, measured: four rendered stories carried sixteen "I want
     * to be honest/exact/fair" lines, zero jokes, zero mild profanity, and
     * one said "Yes, Mother" nine times. The reference narrator is crude and
     * funny and holds 35 minutes. This is the prompt's half; the guard is
     * YouTube's ad-suitability rule, stated in the same block.
     */
    public function test_the_genre_contract_asks_for_a_funny_narrator_with_mild_language_only(): void
    {
        $story = Story::factory()->single()->create();
        $guidance = $this->invoke($this->realWriterWithNoClient(), 'genreGuidance', $story);

        $this->assertStringContainsString('PLAIN-SPOKEN AND FUNNY', $guidance);
        $this->assertStringContainsString('MILD LANGUAGE ONLY', $guidance);
        $this->assertStringContainsString('none in the first thirty seconds', $guidance);
        $this->assertStringContainsString('NEVER NARRATE THE NARRATION', $guidance);
        $this->assertStringNotContainsString('reasonable, restrained', $guidance);
    }

    /**
     * THE BAN IS ON THE MOVE, NOT ON A LIST OF PHRASES — because a list of
     * phrases is always one rewrite behind.
     *
     * Measured across two stories on the same premise. Story 29 carried four
     * "I want to be honest / exact / fair"; those four phrasings were then
     * banned by name, and story 30 carried zero of them and FIVE of "I want
     * you to understand", which story 29 had none of. The forbidden thing did
     * not stop, it changed coat — the same lesson as `CharacterTextGuard`
     * matching `weathered` as a literal while the model reached for a
     * synonym, one field over.
     *
     * So what is asserted here is that the rule NAMES A FORM and says out
     * loud that the list is not the rule. The examples stay, as examples.
     */
    public function test_the_narrating_ban_names_the_move_rather_than_a_list_of_phrases(): void
    {
        $story = Story::factory()->single()->create();

        // Whitespace-normalised, because the guidance is a wrapped heredoc and
        // a fragment that happens to straddle a line break is invisible to
        // `str_contains` — which is exactly how `claimsNotEntitledTo` missed
        // the one claim it most needed to find. The needle is not shortened to
        // fit the wrapping; the haystack is flattened.
        $guidance = (string) preg_replace(
            '/\s+/u',
            ' ',
            $this->invoke($this->realWriterWithNoClient(), 'genreGuidance', $story),
        );

        $this->assertStringContainsString('BAN ON A MOVE RATHER THAN', $guidance);
        $this->assertStringContainsString('THERE IS NO LIST', $guidance);
        $this->assertStringContainsString(
            'any sentence whose subject is the telling instead of the events',
            $guidance,
        );

        // The form is described by what it DOES, so a rewording is covered by
        // construction rather than by somebody adding it to a list.
        foreach (['understand', 'know', 'notice', 'remember', 'hold on to'] as $verb) {
            $this->assertStringContainsString($verb, $guidance, "the move's verbs name {$verb}");
        }

        // The story 30 form is named explicitly, because it is the one that
        // got through, and the old four survive as examples rather than as
        // the rule.
        $this->assertStringContainsString('I want you to understand', $guidance);
        $this->assertStringContainsString('I want to be honest about this', $guidance);
        $this->assertStringContainsString('the same move in a coat the list did not cover', $guidance);
    }

    // -- Fixtures ------------------------------------------------------------

    private function outlinedStory(): Story
    {
        $story = Story::factory()->status(StoryStatus::Draft)->single()->create();

        app(GenerateOutline::class)->handle($story, 6);
        $story->refresh()->approveGate(Gate::Outline);

        return $story->refresh();
    }

    private function scriptedStoryWithCast(): Story
    {
        $story = $this->outlinedStory();

        app(GenerateActScripts::class)->handle($story);

        Character::factory()->for($story)->create(['name' => 'Dana Whitfield']);
        Character::factory()->for($story)->create(['name' => 'Erin Whitfield']);

        return $story->refresh();
    }

    private function realWriterWithNoClient(): ClaudeScriptWriter
    {
        return new ClaudeScriptWriter(
            client: app(Client::class),
            locale: app(LocaleGuard::class),
            text: app(CharacterTextGuard::class),
        );
    }

    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionMethod($target, $method);

        return $reflection->invoke($target, ...$args);
    }
}
