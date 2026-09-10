<?php

namespace Tests\Feature;

use App\Actions\DraftScenes;
use App\Contracts\ScriptWriter;
use App\Enums\RenderStage;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\Character;
use App\Models\RenderJob;
use App\Models\Story;
use App\Services\Fake\FakeScriptWriter;
use App\Support\ImagePromptBuilder;
use App\Support\NameMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Matching a name the generator used against the stored cast.
 *
 * ---------------------------------------------------------------------------
 * WHAT WAS WRONG, MEASURED ON BOTH LIVE en-CN CASTS
 * ---------------------------------------------------------------------------
 *
 * The matcher was documented for Western name order — "the cast is stored as
 * 'Kyle Bennett' and a frame will reasonably say 'Kyle'" — and fell back to the
 * FIRST token of the name being looked up. On `en-CN`, where the locale profile
 * asks for family name first, that is inverted:
 *
 *     Song Yiran -> Song Yiran      Yiran  -> NULL
 *     Wang Suhua -> Wang Suhua      Suhua  -> NULL
 *     Song       -> Song Anan   (the cast holds FIVE Songs)
 *     Lu         -> Lu Jianguo  (not Lu Wenbin, who carries 105 scenes)
 *
 * Every given name missed, and every family name returned whichever holder came
 * first. The second is the worse half: not a miss but a confident wrong answer,
 * pasting one character's description into another's frame on a still about to
 * be paid for. `DraftScenes::resolvePresent()` then dropped the miss silently,
 * and could not tell a real character it failed to parse from a person the
 * generator invented.
 *
 * ---------------------------------------------------------------------------
 * THE CASES BELOW ARE PAIRS, AND THE ORDER-SYMMETRY ONES ARE THE POINT
 * ---------------------------------------------------------------------------
 *
 * A matcher that returned AMBIGUOUS for everything would satisfy every "does
 * not guess" case here. The greens that keep it honest are the ones where a
 * single character genuinely is identified — and in particular the pair that
 * runs the identical assertion in both name orders, because the defect was
 * exactly an order assumption that nothing stated out loud.
 */
class CharacterNameResolutionTest extends TestCase
{
    use RefreshDatabase;

    private ImagePromptBuilder $prompts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prompts = app(ImagePromptBuilder::class);
    }

    // ---------------------------------------------------------------------
    // The exact path, which is what actually runs today
    // ---------------------------------------------------------------------

    /**
     * GREEN, and deliberately first: the behaviour that must not change.
     *
     * Both finished Chinese stories name characters in full in every frame —
     * the locale profile asks for one consistent form — so the exact match is
     * the path that carries all 540 cast-block lines in the database. The rest
     * of this file is about a fallback that was never reached on those stories
     * and was wrong when it was.
     */
    public function test_a_full_name_matches_exactly_and_that_path_is_unchanged(): void
    {
        $cast = $this->cast(['Song Yiran', 'Song Anan', 'Lu Wenbin']);

        $match = $this->prompts->explain('Song Yiran', $cast);

        $this->assertSame(NameMatch::EXACT, $match->reason);
        $this->assertSame('Song Yiran', $match->character?->name);
    }

    /**
     * And exactness wins over any token overlap, which is what keeps a cast
     * containing both "Sun Yaqin" and "Sun Yaqin's grandmother" resolvable at
     * all — every token of the first is inside the second.
     */
    public function test_an_exact_match_beats_a_character_that_contains_it(): void
    {
        $cast = $this->cast(['Sun Yaqin', "Sun Yaqin's grandmother"]);

        $this->assertSame('Sun Yaqin', $this->prompts->explain('Sun Yaqin', $cast)->character?->name);
        $this->assertSame(
            "Sun Yaqin's grandmother",
            $this->prompts->explain("Sun Yaqin's grandmother", $cast)->character?->name,
        );
    }

    // ---------------------------------------------------------------------
    // Order symmetry — the defect itself
    // ---------------------------------------------------------------------

    /**
     * RED before the fix, in the family-name-first order.
     *
     * "Yiran" is the given name of a character stored as "Song Yiran". The old
     * first-token rule asked whether any stored name STARTED with "yiran", so
     * it answered null and the description vanished from the frame.
     */
    public function test_a_given_name_resolves_when_the_family_name_comes_first(): void
    {
        $cast = $this->cast(['Song Yiran', 'Lu Wenbin', 'Wei Hongmei']);

        $match = $this->prompts->explain('Yiran', $cast);

        $this->assertTrue($match->resolved(), 'A given name in family-name-first order did not resolve.');
        $this->assertSame('Song Yiran', $match->character?->name);
    }

    /**
     * The SAME assertion in the other name order, which is the one that always
     * worked.
     *
     * Written as an explicit pair rather than trusted, because the defect was
     * an order assumption nobody had stated — and the fix is only a fix if it
     * is symmetric rather than the old rule flipped.
     */
    public function test_a_given_name_resolves_when_it_comes_first(): void
    {
        $cast = $this->cast(['Kyle Bennett', 'Dana Reyes']);

        $match = $this->prompts->explain('Kyle', $cast);

        $this->assertTrue($match->resolved());
        $this->assertSame('Kyle Bennett', $match->character?->name);
    }

    /**
     * And the family name in Western order, which the OLD rule could not do
     * either — "Bennett" is not the first token of "Kyle Bennett".
     *
     * Worth its own case because it shows the change is not a swap of one
     * order for another. Both halves of both orders resolve now.
     */
    public function test_a_family_name_resolves_in_western_order_too(): void
    {
        $cast = $this->cast(['Kyle Bennett', 'Dana Reyes']);

        $this->assertSame('Kyle Bennett', $this->prompts->explain('Bennett', $cast)->character?->name);
    }

    // ---------------------------------------------------------------------
    // Ambiguity — refuse, never guess
    // ---------------------------------------------------------------------

    /**
     * RED, and the sharper half of the defect: not a miss, a wrong answer.
     *
     * Story 21's cast holds five Songs. The old rule returned Song Anan for a
     * bare "Song" — silently, with no signal anywhere — so a frame naming the
     * family got one specific person's description pasted into it.
     */
    public function test_a_family_name_shared_by_several_is_refused_not_guessed(): void
    {
        $cast = $this->cast(['Song Anan', 'Song Baoqin', 'Song Peiyuan', 'Song Peizhen', 'Song Yiran']);

        $match = $this->prompts->explain('Song', $cast);

        $this->assertFalse($match->resolved(), 'An ambiguous family name was resolved to somebody.');
        $this->assertSame(NameMatch::AMBIGUOUS, $match->reason);
        $this->assertCount(5, $match->candidates);
    }

    /**
     * …and it NAMES them, because "Song is ambiguous" is not something an
     * operator can act on and a list of five is.
     */
    public function test_the_ambiguous_report_names_who_it_could_not_choose_between(): void
    {
        $cast = $this->cast(['Lu Jianguo', 'Lu Wenbin']);

        $problem = $this->prompts->explain('Lu', $cast)->problem();

        $this->assertNotNull($problem);
        $this->assertStringContainsString('Lu Jianguo', $problem);
        $this->assertStringContainsString('Lu Wenbin', $problem);
        $this->assertStringContainsString('NOT guessed', $problem);
    }

    /**
     * GREEN, and the case that stops "refuse on any collision" from being the
     * whole rule: the same family name, only one holder.
     *
     * This is most of a real cast. Story 23 has one Lin, one Xu, one Zhou and
     * one Wang, and every one of them must resolve from the family name alone.
     */
    public function test_a_family_name_with_one_holder_resolves(): void
    {
        $cast = $this->cast(['Lin Zhaoyang', 'Sun Yaqin', 'Wang Suhua']);

        $this->assertSame('Wang Suhua', $this->prompts->explain('Wang', $cast)->character?->name);
        $this->assertSame('Lin Zhaoyang', $this->prompts->explain('Lin', $cast)->character?->name);
    }

    /**
     * GREEN, and the reason the rule is MOST tokens rather than ANY token.
     *
     * "Yiran Song" — the same person written the other way round — shares two
     * tokens with Song Yiran and one with Song Anan. Any-token matching would
     * call that ambiguous and refuse a name that plainly identifies somebody,
     * which would make the guard fire on ordinary input and teach an operator
     * to ignore it.
     */
    public function test_a_reversed_full_name_resolves_rather_than_tying(): void
    {
        $cast = $this->cast(['Song Yiran', 'Song Anan', 'Song Baoqin']);

        $match = $this->prompts->explain('Yiran Song', $cast);

        $this->assertSame('Song Yiran', $match->character?->name, 'A reversed full name should not be ambiguous.');
    }

    // ---------------------------------------------------------------------
    // Unknown, which is a different problem from ambiguous
    // ---------------------------------------------------------------------

    /**
     * The case the original silent drop was written for, and it still behaves
     * that way — it simply says so now.
     */
    public function test_a_name_matching_nobody_is_reported_as_an_invention(): void
    {
        $cast = $this->cast(['Song Yiran', 'Lu Wenbin']);

        $match = $this->prompts->explain('Detective Morrow', $cast);

        $this->assertFalse($match->resolved());
        $this->assertSame(NameMatch::UNKNOWN, $match->reason);
        $this->assertStringContainsString('matches nobody', (string) $match->problem());
    }

    /**
     * UNKNOWN and AMBIGUOUS are separate states and must read differently.
     *
     * Both resolve to no character, which is exactly why a single null could
     * not carry them — and they want opposite reactions: one is a person who is
     * not in the story, the other is a person who is, and a description missing
     * from a frame that needed it.
     */
    public function test_unknown_and_ambiguous_do_not_read_the_same(): void
    {
        $cast = $this->cast(['Song Anan', 'Song Yiran']);

        $unknown = $this->prompts->explain('Detective Morrow', $cast);
        $ambiguous = $this->prompts->explain('Song', $cast);

        $this->assertFalse($unknown->resolved());
        $this->assertFalse($ambiguous->resolved());
        $this->assertNotSame($unknown->reason, $ambiguous->reason);
        $this->assertNotSame($unknown->problem(), $ambiguous->problem());
    }

    /**
     * A resolved name reports NO problem, so the log cannot fill with findings
     * about frames that were fine.
     */
    public function test_a_resolved_name_reports_nothing(): void
    {
        $cast = $this->cast(['Song Yiran']);

        $this->assertNull($this->prompts->explain('Song Yiran', $cast)->problem());
        $this->assertNull($this->prompts->explain('Yiran', $cast)->problem());
    }

    // ---------------------------------------------------------------------
    // The live casts, as a regression on real data
    // ---------------------------------------------------------------------

    /**
     * Every part of every name in both shipped Chinese casts either resolves to
     * the right person or is refused as ambiguous. Nothing resolves to the
     * WRONG person, which is what the old rule did.
     *
     * The casts are written out rather than read from the database so the case
     * still means something on a fresh machine — and because the two stories
     * are the measurement this change was made against.
     */
    public function test_neither_live_chinese_cast_resolves_a_name_to_the_wrong_person(): void
    {
        $casts = [
            'story 21' => [
                'Fang Zheng', 'Lu Jianguo', 'Lu Wenbin', 'Ma Lifen', 'Song Anan',
                'Song Baoqin', 'Song Peiyuan', 'Song Peizhen', 'Song Yiran', 'Wei Hongmei',
            ],
            'story 23' => [
                'Guo Peng', 'Lin Zhaoyang', 'Ma Jianhui', 'Master Fang', 'Sun Yaqin',
                "Sun Yaqin's grandmother", 'Wang Suhua', 'Xu Jiajia', 'Zhou Kaiming',
            ],
        ];

        foreach ($casts as $label => $names) {
            $cast = $this->cast($names);

            foreach ($names as $full) {
                $this->assertSame(
                    $full,
                    $this->prompts->explain($full, $cast)->character?->name,
                    $label.': the full name "'.$full.'" did not resolve to itself.',
                );

                foreach (explode(' ', $full) as $part) {
                    $match = $this->prompts->explain($part, $cast);

                    if (! $match->resolved()) {
                        // Refusing is a legitimate answer for a shared token.
                        $this->assertSame(NameMatch::AMBIGUOUS, $match->reason);

                        continue;
                    }

                    // Resolved — then it must be to somebody whose name
                    // actually contains that token. The old rule failed this:
                    // "Lu" returned Lu Jianguo, which passes here, but "Song"
                    // returning Song Anan for a frame meaning any other Song
                    // did not.
                    $this->assertStringContainsString(
                        mb_strtolower($part),
                        mb_strtolower((string) $match->character?->name),
                        $label.': "'.$part.'" resolved to somebody who is not called that.',
                    );
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // The refusal reaching an operator, which is the point of all of it
    // ---------------------------------------------------------------------

    /**
     * RED, end to end: an ambiguous name is written to the draft's own record.
     *
     * "A refusal I can see beats a drop I can't." Refusing inside the matcher
     * is only half a fix — before this, an unresolvable name was discarded by
     * `resolvePresent()` with no trace anywhere, so the operator's evidence
     * that a frame lost its description was the absence of a description in a
     * prompt they would have to read to notice.
     *
     * The `draft_scenes` row is the right surface: it already carries the
     * draft's log, `/renders/{slug}` already shows it, and it survives the page
     * reload that `$this->problem` on a component does not.
     */
    public function test_an_unresolvable_name_is_written_to_the_draft_log(): void
    {
        // Both characters are Vasquez, so a frame naming the family names two
        // people and identifies neither.
        $story = $this->draftWithPresentNames(['Vasquez']);

        $log = (string) RenderJob::query()
            ->where('story_id', $story->id)
            ->where('stage', RenderStage::DraftScenes)
            ->value('log');

        $this->assertStringContainsString('NOT guessed', $log);
        $this->assertStringContainsString('Erin Vasquez', $log);
        $this->assertStringContainsString('Kyle Vasquez', $log);

        // Located by act, never by the parked sequence a partial re-draft uses.
        $this->assertStringContainsString('act 1, scene 1 of that act', $log);

        // And nobody was linked, which is the behaviour change: the old matcher
        // attached whichever character came first.
        $this->assertSame(0, $story->scenes()->first()?->characters()->count());
    }

    /**
     * GREEN, as close to it as possible: the same fixture, a name that
     * identifies somebody.
     *
     * Without this, a `resolvePresent()` that reported every frame would pass
     * the case above — and a log that names every scene is a log nobody reads,
     * which is this project's own argument about an alarm that fires for
     * something the reader cannot act on.
     */
    public function test_a_resolvable_name_writes_nothing_to_the_draft_log(): void
    {
        $story = $this->draftWithPresentNames(['Erin']);

        $log = (string) RenderJob::query()
            ->where('story_id', $story->id)
            ->where('stage', RenderStage::DraftScenes)
            ->value('log');

        $this->assertStringNotContainsString('NOT guessed', $log);
        $this->assertStringNotContainsString('could not answer for', $log);

        $this->assertSame(
            'Erin Vasquez',
            $story->scenes()->first()?->characters()->first()?->name,
            'A given name in Western order should still link the pivot.',
        );
    }

    /**
     * Draft a small story where every frame names exactly `$present`.
     *
     * Two characters sharing a family name, which is the shape both live
     * Chinese casts have several times over — five Songs in story 21, two Suns
     * in story 23 — expressed in the Western order the rest of the suite uses.
     *
     * @param  array<int, string>  $present
     */
    private function draftWithPresentNames(array $present): Story
    {
        $writer = app(ScriptWriter::class);

        if (! $writer instanceof FakeScriptWriter) {
            $this->markTestSkipped('This case drives the fake writer.');
        }

        $writer->charactersPresentOverride = $present;

        $story = Story::factory()->status(StoryStatus::Scripted)->create(['slug' => 'name-resolution']);

        Act::factory()->for($story)->atSequence(1)->create([
            'script' => 'Erin sat at the table. Kyle would not look at her. '
                .'The spreadsheet lay between them. She said the number out loud.',
        ]);

        Character::factory()->for($story)->create(['name' => 'Erin Vasquez']);
        Character::factory()->for($story)->create(['name' => 'Kyle Vasquez']);

        app(DraftScenes::class)->handle($story);

        return $story->refresh();
    }

    /**
     * @param  array<int, string>  $names
     * @return Collection<int, Character>
     */
    private function cast(array $names): Collection
    {
        $story = Story::factory()->create();

        return collect($names)->map(fn (string $name): Character => Character::factory()->for($story)->create([
            'name' => $name,
        ]));
    }
}
