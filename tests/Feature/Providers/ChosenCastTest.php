<?php

namespace Tests\Feature\Providers;

use Anthropic\Client;
use App\Actions\GenerateOutline;
use App\Actions\GeneratePremises;
use App\Contracts\ScriptWriter;
use App\Enums\CastRole;
use App\Enums\FailureKind;
use App\Enums\PartnerEndState;
use App\Enums\RenderStage;
use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use App\Livewire\Gates\OutlineGate;
use App\Models\RenderJob;
use App\Models\Story;
use App\Services\Claude\ClaudeScriptWriter;
use App\Services\Fake\FakeScriptWriter;
use App\Support\CharacterTextGuard;
use App\Support\LocaleGuard;
use App\Support\OutlineCast;
use App\Support\Providers\CastMember;
use App\Support\Providers\PremiseCandidate;
use App\Support\Providers\ScriptWriterException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * THE CAST PICKED WITH A PREMISE REACHES THE OUTLINE, AND THE OUTLINE KEEPS IT.
 *
 * Story 38, 2026-09-19. The premise roll declared Chloe Rong the future
 * partner in all three candidates. "Use this premise" kept only the prose,
 * which calls her the friend who did not laugh; the outline made her a friend
 * and invented Vera Qin for the partner row; every stage after that carried
 * Vera faithfully to the last chapter. The narrator's name (Jason Kong) went at
 * the same click. See CLAUDE.md 3f, second reading.
 *
 * Every consumer of the kept cast: the pick stores it (and the page's state),
 * the outline prompt hands it over as chosen, GenerateOutline refuses an
 * outline that drops or re-roles anyone in it, and the act writer reads
 * `outline_cast` exactly as before (OutlineCastTest holds that arrival).
 *
 * ---------------------------------------------------------------------------
 * AND THE THIRD THING THAT DIED AT THAT CLICK: THE SEVEN SPINE ANSWERS.
 * ---------------------------------------------------------------------------
 *
 * Story 39, 2026-09-20. The generator returns, per candidate, a narrator, a
 * cast, SEVEN spine answers and the prose. The narrator and the cast were
 * fixed above. The answers stayed in `premise_candidates`, where only Gate 1's
 * checks read them — so fourteen checks ran over them, the operator picked on
 * what those checks said, and the outline answered all seven again from the
 * prose, because `stories.premise` is all it was handed.
 *
 * Four of the seven are at least tied to the prose by the premise checks'
 * two-word overlap. THREE ARE TIED TO NOTHING: `accomplice_motive`,
 * `accomplice_performance` and `narrator_at_exposure` are checked for their own
 * content and never for whether the prose carries them, so those three could
 * only ever have reached the outline by luck.
 */
class ChosenCastTest extends TestCase
{
    use RefreshDatabase;

    private FakeScriptWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(ScriptWriter::class);
    }

    public function test_picking_a_premise_keeps_its_cast_and_its_narrator(): void
    {
        $story = $this->draftStory();
        $this->rollTwo($story);

        Livewire::test(OutlineGate::class, ['story' => $story->fresh()])
            ->call('usePremise', 1)
            ->assertSet('cast', OutlineCast::rows($this->story38Cast()));

        $story->refresh();

        $this->assertSame(OutlineCast::rows($this->story38Cast()), $story->outline_cast);
        $this->assertSame(['name' => 'Jason Kong', 'role' => 'narrator'], array_intersect_key($story->outline_cast[0], ['name' => 1, 'role' => 1]));
        $this->assertSame(StoryStatus::Draft, $story->status, 'Picking a premise must not move the story.');
    }

    /**
     * A candidate with no cast (a roll written before the cast existed)
     * clears the column: a previous pick's cast must not outlive its prose.
     */
    public function test_picking_a_candidate_with_no_cast_clears_the_previous_pick(): void
    {
        $story = $this->draftStory();
        $this->rollTwo($story);

        Livewire::test(OutlineGate::class, ['story' => $story->fresh()])
            ->call('usePremise', 1)
            ->call('usePremise', 0);

        $this->assertNull($story->fresh()->outline_cast);
    }

    public function test_the_outline_prompt_hands_over_the_chosen_cast_and_only_when_there_is_one(): void
    {
        $plain = $this->draftStory();
        $this->assertStringNotContainsString('THE CAST IS ALREADY CHOSEN', $this->outlinePrompt($plain),
            'A typed premise with no cast keeps the prompt it had.');

        $chosen = $this->draftStory(['outline_cast' => OutlineCast::rows($this->story38Cast())]);
        $prompt = $this->outlinePrompt($chosen);

        $this->assertStringContainsString('THE CAST IS ALREADY CHOSEN', $prompt);
        $this->assertStringContainsString('- Jason Kong (Narrator)', $prompt);
        $this->assertStringContainsString('- Chloe Rong (Future partner): Nicole\'s best friend since university', $prompt);
        $this->assertStringContainsString('that is the name in your narrator field', $prompt);
    }

    /**
     * End to end on the fake: pick, outline, and the partner and the narrator
     * are the ones that were picked.
     */
    public function test_the_picked_partner_and_narrator_survive_the_outline(): void
    {
        $story = $this->draftStory();
        $this->rollTwo($story);

        Livewire::test(OutlineGate::class, ['story' => $story->fresh()])->call('usePremise', 1);

        app(GenerateOutline::class)->handle($story->fresh());

        $members = OutlineCast::members($story->fresh()->outline_cast);
        $partner = array_values(array_filter($members, fn (CastMember $m): bool => $m->role === CastRole::FuturePartner));

        $this->assertSame('Jason Kong', $members[0]->name);
        $this->assertSame(CastRole::Narrator, $members[0]->role);
        $this->assertSame(['Chloe Rong'], array_map(fn (CastMember $m): string => $m->name, $partner));
    }

    /**
     * RED: story 38's swap, replayed — the picked partner comes back as a
     * friend and a stranger holds the row. Refused after the cost row, with
     * its kind and check, and nothing stored over the chosen cast.
     */
    public function test_red_an_outline_that_swaps_the_partner_is_refused_after_the_cost_row(): void
    {
        $story = $this->draftStory(['outline_cast' => OutlineCast::rows($this->story38Cast())]);
        $this->writer->castOverride = [
            new CastMember('Jason Kong', CastRole::Narrator, 'Nicole\'s husband'),
            new CastMember('Nicole Pei', CastRole::Antagonist, 'my wife'),
            new CastMember('Derek Xiao', CastRole::Accomplice, 'her intern'),
            new CastMember('Chloe Rong', CastRole::NarratorSide, 'Nicole\'s university roommate'),
            new CastMember('Vera Qin', CastRole::FuturePartner, 'the operations director who hired me'),
        ];

        try {
            app(GenerateOutline::class)->handle($story);
            $this->fail('Expected an outline that re-roled the picked partner to be refused.');
        } catch (ScriptWriterException $e) {
            $this->assertStringContainsString('Chloe Rong came back as On the narrator\'s side instead of Future partner', $e->getMessage());
        }

        $row = RenderJob::query()->where('story_id', $story->id)->where('stage', RenderStage::Outline)->sole();
        $this->assertSame(FailureKind::OutlineRefused, $row->failure_kind);
        $this->assertSame(['check' => 'chosen_cast'], $row->failure_facts);

        $this->assertSame(0, $story->acts()->count());
        $this->assertSame(OutlineCast::rows($this->story38Cast()), $story->fresh()->outline_cast, 'The chosen cast is not overwritten.');
        $this->assertSame(1, $story->costEntries()->where('operation', 'generate_outline')->count(), 'The call was billed.');
    }

    /**
     * GREEN: everyone picked, in their roles, plus one more person and a
     * fuller relationship line. That is what the prompt allows.
     */
    public function test_green_an_outline_that_adds_a_person_and_rewrites_a_relationship_passes(): void
    {
        $story = $this->draftStory(['outline_cast' => OutlineCast::rows($this->story38Cast())]);
        $this->writer->castOverride = [
            new CastMember('Jason Kong', CastRole::Narrator, 'Nicole\'s husband of eight years, the co-founder'),
            new CastMember('Nicole Pei', CastRole::Antagonist, 'my wife'),
            new CastMember('Derek Xiao', CastRole::Accomplice, 'her intern'),
            new CastMember('Chloe Rong', CastRole::FuturePartner, 'Nicole\'s roommate since their first year, who did not laugh'),
            new CastMember('Felix Tan', CastRole::NarratorSide, 'our outside counsel'),
        ];

        app(GenerateOutline::class)->handle($story);

        $this->assertSame('Felix Tan', $story->fresh()->outline_cast[4]['name']);
        $this->assertSame([], OutlineCast::chosenCastChanges($this->story38Cast(), $this->writer->castOverride));
    }

    /**
     * RED/GREEN: a re-outline is HELD to the cast on the story, and released
     * only when the operator says so on the confirm.
     *
     * -----------------------------------------------------------------------
     * THIS TEST USED TO ASSERT THE OPPOSITE, AND THE OLD ASSERTION WAS THE
     * DEFECT RATHER THAN A DESCRIPTION OF ONE
     * -----------------------------------------------------------------------
     *
     * It read `test_green_a_reoutline_of_a_story_with_acts_may_change_its_cast`
     * and pinned `chosenBeforeOutline()` returning nothing the moment a story
     * had acts. The reasoning was sound as far as it went — a bad cast has to
     * be repairable by re-outlining — and it was half the question. The same
     * condition also decided whether a re-outline may destroy a GOOD cast, and
     * nothing asked which case a story was in.
     *
     * Story 39, 2026-09-20, measured: re-outlined at `outlined` with five acts
     * and no scripts to put a chosen end state in its last summary. The cast
     * was not held, so the prompt never said it was chosen and this file's own
     * invariant had nothing to compare. The outline renamed the narrator,
     * demoted the partner the operator's idea had named — and whose end state
     * they had just chosen — to narrator_side, and invented a stranger for the
     * row. $0.2566. Story 38's swap, past the guard written for story 38.
     *
     * So the repair path became a press-level choice instead of a silent
     * default, and this pair holds both halves of it.
     */
    public function test_red_green_a_reoutline_holds_the_cast_unless_it_is_released(): void
    {
        $story = $this->draftStory(['outline_cast' => OutlineCast::rows($this->story38Cast())]);
        app(GenerateOutline::class)->handle($story);

        // The state the whole finding is about: acts exist, nothing written
        // against them. The old scope went inert here.
        $this->assertTrue($story->fresh()->acts()->exists());
        $this->assertFalse($story->fresh()->hasWrittenActs());

        // GREEN — held by default, and the prompt says so.
        $this->assertStringContainsString('THE CAST IS ALREADY CHOSEN', $this->outlinePrompt($story->fresh()));
        $this->assertNotSame([], OutlineCast::chosenBeforeOutline($story->fresh()));

        $swap = [
            new CastMember('Jason Kong', CastRole::Narrator, 'the narrator'),
            new CastMember('Nicole Pei', CastRole::Antagonist, 'my wife'),
        ];

        $this->writer->castOverride = $swap;

        // RED — the swap story 39 bought is refused now, before anything is
        // stored, and the refusal names the check.
        try {
            app(GenerateOutline::class)->handle($story->fresh());
            $this->fail('A re-outline that dropped the chosen cast was accepted.');
        } catch (ScriptWriterException $e) {
            $this->assertSame(FailureKind::OutlineRefused, $e->failureKind());
            $this->assertSame('chosen_cast', $e->failureFacts()['check'] ?? null);
        }

        $this->assertSame(
            array_column(OutlineCast::rows($this->story38Cast()), 'name'),
            array_column($story->fresh()->outline_cast, 'name'),
            'The refused call must store nothing.',
        );

        // GREEN — released on the confirm, which is the repair the old scope
        // served silently. The prompt stops claiming a chosen cast, so the
        // request and the invariant move together.
        $this->assertStringNotContainsString(
            'THE CAST IS ALREADY CHOSEN',
            $this->outlinePrompt($story->fresh(), keepCast: false),
        );

        app(GenerateOutline::class)->handle($story->fresh(), keepCast: false);

        $this->assertSame(['Jason Kong', 'Nicole Pei'], array_column($story->fresh()->outline_cast, 'name'));
    }

    /**
     * The line is the SCRIPT, not the act row — the same line PartnerEnding
     * draws, from the same predicate. Once the words are in the prose the
     * outline cannot be replaced at all, so the cast stops being a question.
     */
    public function test_a_written_act_ends_the_chosen_cast_and_refuses_the_reoutline(): void
    {
        $story = $this->draftStory(['outline_cast' => OutlineCast::rows($this->story38Cast())]);
        app(GenerateOutline::class)->handle($story);

        $story->fresh()->acts()->first()->update(['script' => 'A written act. '.str_repeat('Words. ', 40)]);

        $this->assertTrue($story->fresh()->hasWrittenActs());
        $this->assertSame([], OutlineCast::chosenBeforeOutline($story->fresh()));

        $this->expectExceptionMessage(GenerateOutline::WRITTEN_ACTS);
        app(GenerateOutline::class)->handle($story->fresh());
    }

    public function test_red_green_a_renamed_narrator_is_a_change(): void
    {
        $returned = $this->story38Cast();
        $returned[0] = new CastMember('Marcus Pei', CastRole::Narrator, 'Nicole\'s husband');

        $this->assertSame(
            ['Jason Kong (Narrator) is not in the outline\'s cast.'],
            OutlineCast::chosenCastChanges($this->story38Cast(), $returned),
        );
        $this->assertSame([], OutlineCast::chosenCastChanges([], $returned), 'Nothing chosen, nothing to keep.');
    }

    // -- The seven answers, the third thing lost at this click -------------------

    public function test_picking_a_premise_keeps_the_answers_it_was_checked_on(): void
    {
        $story = $this->draftStory();
        $this->rollTwo($story);

        Livewire::test(OutlineGate::class, ['story' => $story->fresh()])->call('usePremise', 1);

        $spine = $story->fresh()->premise_spine;

        $this->assertIsArray($spine);

        // The SET, not the order. MySQL's JSON type does not preserve object
        // key order, so the column comes back shuffled — which costs nothing,
        // because the prompt walks PremiseCandidate::FIELDS rather than the
        // stored keys. That ordering is asserted on the prompt below.
        $keys = array_keys($spine);
        sort($keys);
        $fields = PremiseCandidate::FIELDS;
        sort($fields);
        $this->assertSame($fields, $keys, 'All seven.');

        // The three nothing ties to the prose are the point: those are the
        // ones that could only ever have survived by luck.
        foreach (['accomplice_motive', 'accomplice_performance', 'narrator_at_exposure'] as $field) {
            $this->assertNotSame('', trim((string) $spine[$field]));
        }
    }

    /**
     * A pick always replaces the previous pick whole, so a candidate with no
     * answers clears the column — the cast's own rule, for its own reason: a
     * previous pick's answers must not outlive its prose.
     */
    public function test_picking_a_candidate_with_no_answers_clears_the_previous_pick(): void
    {
        $story = $this->draftStory();
        $base = $this->writer->premiseCandidate($story, 'her thirtieth birthday', 'two dozen friends', 'founding stake', 'share register');

        $this->writer->premiseOverride = [
            new PremiseCandidate('The first premise. '.$base->premise, $this->story38Cast(), $base->fields),
            new PremiseCandidate('The second premise. '.$base->premise, $this->story38Cast(), array_fill_keys(PremiseCandidate::FIELDS, '')),
        ];

        app(GeneratePremises::class)->handle($story, 'My CEO wife cheated with her intern. I divorced her and married her best friend.');

        Livewire::test(OutlineGate::class, ['story' => $story->fresh()])
            ->call('usePremise', 0)
            ->call('usePremise', 1);

        $this->assertNull($story->fresh()->premise_spine);
    }

    /**
     * The outline is HANDED them, and is not refused for rewriting them —
     * unlike the cast, and the difference is the reason. A name is checkable
     * for identity; a spine answer is prose the outline has to expand into an
     * exposure and a refusal it also has to invent, so "the outline changed
     * it" is not by itself a defect and a refusal would turn an ordinary
     * rewrite into a billed failure.
     */
    public function test_the_outline_prompt_is_handed_the_answers_and_only_before_it_has_answered(): void
    {
        $story = $this->draftStory();
        $this->rollTwo($story);

        Livewire::test(OutlineGate::class, ['story' => $story->fresh()])->call('usePremise', 1);

        $prompt = $this->outlinePrompt($story->fresh());
        $spine = $story->fresh()->premise_spine;

        $this->assertStringContainsString('THE PREMISE CAME WITH THESE ANSWERS', $prompt);
        $this->assertStringContainsString('keep the specifics', $prompt);

        $at = -1;

        foreach (PremiseCandidate::FIELDS as $field) {
            $line = '- '.$field.': '.$spine[$field];
            $this->assertStringContainsString($line, $prompt);

            // Generation order is the schema's, not the JSON column's, which
            // comes back shuffled. The prompt walks FIELDS, so this holds.
            $found = strpos($prompt, $line);
            $this->assertIsInt($found);
            $this->assertGreaterThan($at, $found, "{$field} is out of schema order in the prompt.");
            $at = $found;
        }

        // Scoped like the chosen cast, and it moved with it. This used to
        // assert that a re-outline stops receiving them, on the reasoning that
        // the outline has now answered — which reads well and is the wrong
        // default: these are the answers Gate 1 checked and the operator
        // picked the premise ON, and a re-outline that re-answers them from
        // the prose discards a decision somebody made. Story 39's candidate
        // withheld a warehouse licence only the narrator could sign; the
        // re-outline that never saw the answers invented an 8.4M yuan Hamburg
        // account instead, and the one that did got the licence back.
        app(GenerateOutline::class)->handle($story->fresh());
        $this->assertStringContainsString('THE PREMISE CAME WITH THESE ANSWERS', $this->outlinePrompt($story->fresh()));

        // The line is the script, not the act row.
        $story->fresh()->acts()->first()->update(['script' => 'Written. '.str_repeat('Words. ', 40)]);
        $this->assertStringNotContainsString('THE PREMISE CAME WITH THESE ANSWERS', $this->outlinePrompt($story->fresh()));

        $plain = $this->draftStory();
        $this->assertStringNotContainsString('THE PREMISE CAME WITH THESE ANSWERS', $this->outlinePrompt($plain),
            'A typed premise never had answers beside it.');
    }

    /**
     * A fork holds one thing fixed while another varies. An outline written
     * from the prose alone would vary two.
     */
    public function test_a_fork_carries_the_picked_answers(): void
    {
        $story = $this->draftStory();
        $this->rollTwo($story);

        Livewire::test(OutlineGate::class, ['story' => $story->fresh()])->call('usePremise', 1);
        app(GenerateOutline::class)->handle($story->fresh());

        $this->artisan('story:fork', ['story' => $story->slug])->assertSuccessful();

        $fork = Story::query()->whereKeyNot($story->id)->latest('id')->first();
        $this->assertSame($story->fresh()->premise_spine, $fork?->premise_spine);
    }

    /** @return array<int, CastMember> */
    private function story38Cast(): array
    {
        return [
            new CastMember('Jason Kong', CastRole::Narrator, 'a structural engineer, husband to the antagonist'),
            new CastMember('Nicole Pei', CastRole::Antagonist, 'the narrator\'s wife, CEO of a tech company'),
            new CastMember('Derek Xiao', CastRole::Accomplice, 'Nicole\'s intern'),
            new CastMember('Chloe Rong', CastRole::FuturePartner, 'Nicole\'s best friend since university'),
        ];
    }

    /** Candidate 1 has no cast (an old roll's shape); candidate 2 is story 38's. */
    private function rollTwo(Story $story): void
    {
        $base = $this->writer->premiseCandidate($story, 'her thirtieth birthday', 'two dozen friends', 'founding stake', 'share register');

        $this->writer->premiseOverride = [
            new PremiseCandidate('The first premise. '.$base->premise, [], $base->fields),
            new PremiseCandidate('The second premise. '.$base->premise, $this->story38Cast(), $base->fields),
        ];

        app(GeneratePremises::class)->handle($story, 'My CEO wife cheated with her intern. I divorced her and married her best friend.');
    }

    private function outlinePrompt(Story $story, bool $keepCast = true): string
    {
        $claude = new ClaudeScriptWriter(client: app(Client::class), locale: app(LocaleGuard::class), text: app(CharacterTextGuard::class));

        return (new ReflectionMethod($claude, 'outlinePrompt'))->invoke($claude, $story, 5, $keepCast);
    }

    private function draftStory(array $attributes = []): Story
    {
        return Story::factory()->status(StoryStatus::Draft)->create($attributes + [
            'format' => StoryFormat::Single,
            'locale_profile' => 'en-US',
            // Set here rather than defaulted on the factory, and every case in
            // this file went red the day the column arrived — correctly, since
            // story 38's chosen cast names a future partner and the outline is
            // now refused until somebody says what they are by the end. A
            // factory default would have made that refusal unreachable in the
            // one file whose fixture can express it, which is the
            // fixture-cannot-hold-the-failure shape. See PartnerEnding and
            // EndingChoiceTest's red/green pair for the refusal itself.
            'partner_end_state' => PartnerEndState::Together,
            'premise' => 'My wife held her intern\'s hand at her thirtieth birthday and told the table I had been gone for years.',
        ]);
    }
}
