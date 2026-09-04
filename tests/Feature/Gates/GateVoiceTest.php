<?php

namespace Tests\Feature\Gates;

use App\Enums\Gate;
use App\Enums\StoryStatus;
use App\Livewire\Gates\MetadataGate;
use App\Livewire\Gates\OutlineGate;
use App\Livewire\Gates\ScenesGate;
use App\Models\Story;
use App\Support\GateVoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PageProbe;
use Tests\TestCase;

/**
 * The mechanism itself, before any page is asked to use it.
 *
 * GateLayoutContractTest checks that no gate page named an action it did not
 * have. That check is only worth what its inputs are worth, and its inputs come
 * from here — so the three ways this class could be confidently wrong are
 * asserted first.
 */
class GateVoiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every voice on the grid, so a clause is never asked about a state no
     * story can be in.
     *
     * `phrasings()` used to fabricate one "open" and one "settled" voice through
     * the private constructor. That shape could not hold a position clause — it
     * has three phrasings and two of them are claims — and a fabricated voice is
     * a voice no story is ever in, which is the shape behind half the
     * self-defeating checks this project has found.
     *
     * @return array<string, array{0: Gate, 1: StoryStatus}>
     */
    public static function everyVoice(): array
    {
        $out = [];

        foreach (Gate::cases() as $gate) {
            foreach (StoryStatus::cases() as $status) {
                $out[sprintf('gate %d at %s', $gate->value, $status->value)] = [$gate, $status];
            }
        }

        return $out;
    }

    /**
     * No clause emits a claim the voice saying it is not entitled to.
     *
     * THE IDENTITY CASE, in the sense this codebase means it, and the position
     * clauses make it sharper than it was. The claim check greps a rendered page
     * for a fragment; if the wording written to STOP claiming something happened
     * to contain that fragment, the guard would fire on its own fix and the only
     * way to make it pass would be to weaken it. "None of these blocked
     * approval." is one character away from doing exactly that.
     *
     * An action clause can be made safe by writing a careful settled sentence,
     * because its settled sentence asserts nothing. A position clause cannot:
     * `standing()` must say "is behind this story" past the gate and "has not
     * been reached" short of it and NEITHER parked at it, so every wording it
     * has is a claim and the only thing that can make it safe is the capability
     * being right.
     */
    #[DataProvider('everyVoice')]
    public function test_no_clause_emits_a_claim_its_voice_is_not_entitled_to(
        Gate $gate,
        StoryStatus $status,
    ): void {
        $voice = GateVoice::for($gate, $status);

        foreach ($voice->everyClause() as $clause => $text) {
            $this->assertSame(
                [],
                PageProbe::claimsNotEntitledTo($text, $voice),
                sprintf(
                    '%s emits a claim it is not entitled to at gate %d / %s: "%s"',
                    $clause,
                    $gate->value,
                    $status->value,
                    $text,
                ),
            );
        }
    }

    /**
     * And an entitled claim really is emitted, so something can find it.
     *
     * The other half. Without it a clause could satisfy the check above by
     * claiming nothing anywhere — a voice that is silent rather than one that is
     * honest — and since `claimsNotEntitledTo` greps a page for these fragments,
     * a claim absent from the sentence it belongs to is a claim nothing on any
     * page can ever be checked against.
     */
    #[DataProvider('everyVoice')]
    public function test_every_entitled_claim_is_actually_emitted(
        Gate $gate,
        StoryStatus $status,
    ): void {
        $voice = GateVoice::for($gate, $status);
        $clauses = $voice->everyClause();

        foreach (GateVoice::clauses() as $clause) {
            foreach (GateVoice::claimsOf($clause) as $capability => $fragment) {
                if (! $voice->can($capability)) {
                    continue;
                }

                $this->assertStringContainsString(
                    $fragment,
                    $clauses[$clause],
                    sprintf(
                        '%s is entitled to "%s" at gate %d / %s and does not say it, so nothing can '
                        .'find the claim on a page.',
                        $clause,
                        $capability,
                        $gate->value,
                        $status->value,
                    ),
                );
            }
        }
    }

    /**
     * A clause is never entitled to two of its own phrasings at once.
     *
     * Position is where this could go wrong. PASSED and AHEAD are deliberately
     * NOT complements — at the gate's own status both are false — and a
     * mis-derived pair would make them both true somewhere, which is a page
     * claiming a story is on both sides of one gate.
     */
    #[DataProvider('everyVoice')]
    public function test_a_clause_is_entitled_to_at_most_one_of_its_phrasings(
        Gate $gate,
        StoryStatus $status,
    ): void {
        $voice = GateVoice::for($gate, $status);

        foreach (GateVoice::clauses() as $clause) {
            $entitled = array_values(array_filter(
                array_keys(GateVoice::claimsOf($clause)),
                static fn (string $capability): bool => $voice->can($capability),
            ));

            $this->assertLessThanOrEqual(
                1,
                count($entitled),
                sprintf(
                    '%s is entitled to %s at once at gate %d / %s.',
                    $clause,
                    implode(' and ', $entitled),
                    $gate->value,
                    $status->value,
                ),
            );
        }
    }

    /**
     * The voice agrees with the gate bodies that decide this for themselves.
     *
     * Same reasoning as OperatorAction: a page deciding for itself and a shared
     * predicate deciding separately are two expressions of one rule, compared
     * only by hand, and this codebase's list of drifted copies is long.
     *
     * Three of the four are here — every gate body that has written the range
     * out for itself. It is what the derivation is FOR: the first attempt at it
     * read "from the previous gate's crossing", which agrees with Gates 1 and 2
     * and is wrong about Gate 4, whose sheet is written a status before the gate
     * waits. Gate 3 joins when its body grows an `editable()`.
     */
    public function test_the_voice_agrees_with_the_gate_bodies(): void
    {
        foreach (StoryStatus::cases() as $status) {
            $story = Story::factory()->status($status)->create([
                'slug' => 'voice-'.strtolower($status->value),
            ]);

            $outline = Livewire::test(OutlineGate::class, ['story' => $story]);
            $scenes = Livewire::test(ScenesGate::class, ['story' => $story]);
            $metadata = Livewire::test(MetadataGate::class, ['story' => $story]);

            $this->assertSame(
                $outline->instance()->editable(),
                GateVoice::for(Gate::Outline, $status)->can(GateVoice::EDIT),
                "Gate 1's own editable() and GateVoice disagree at {$status->value}.",
            );
            $this->assertSame(
                $outline->instance()->canApprove(),
                GateVoice::for(Gate::Outline, $status)->can(GateVoice::APPROVE),
                "Gate 1's own canApprove() and GateVoice disagree at {$status->value}.",
            );

            $this->assertSame(
                $scenes->instance()->editable(),
                GateVoice::for(Gate::Scenes, $status)->can(GateVoice::EDIT),
                "Gate 2's own editable() and GateVoice disagree at {$status->value}.",
            );
            $this->assertSame(
                $scenes->instance()->canApprove(),
                GateVoice::for(Gate::Scenes, $status)->can(GateVoice::APPROVE),
                "Gate 2's own canApprove() and GateVoice disagree at {$status->value}.",
            );

            $this->assertSame(
                $metadata->instance()->editable(),
                GateVoice::for(Gate::Metadata, $status)->can(GateVoice::EDIT),
                "Gate 4's own editable() and GateVoice disagree at {$status->value}.",
            );
        }
    }

    /**
     * Every claim is keyed to a capability that can() genuinely varies on.
     *
     * A clause added with a capability nothing resolves would be a claim nothing
     * checks — the "declared but never called" shape, in a guard. `can()` ends in
     * `default => false`, so a typo produces a capability that is false
     * everywhere and a claim keyed to it can never fail.
     *
     * REWRITTEN FOR THE POSITION AXIS, and the rewrite is the honest version of
     * what the old one was reaching for. It asserted that every capability is
     * TRUE at `outlined` and FALSE at `published` — a hard-coded pair that
     * happens to separate the action capabilities and gets PASSED exactly
     * backwards, so adding the position axis to it would have meant weakening
     * it. What the check is about is that the capability discriminates at all,
     * so it asks the grid for one voice that has it and one that does not.
     */
    public function test_every_claim_is_keyed_to_a_capability_that_resolves(): void
    {
        $this->assertNotEmpty(GateVoice::claims());

        foreach (array_keys(GateVoice::claims()) as $capability) {
            $answers = [];

            foreach (self::everyVoice() as [$gate, $status]) {
                $answers[] = GateVoice::for($gate, $status)->can($capability);
            }

            $this->assertContains(
                true,
                $answers,
                'can("'.$capability.'") is false at every gate and every status, so it is either a '
                .'typo falling through to the default or a claim that can never be made.',
            );
            $this->assertContains(
                false,
                $answers,
                'can("'.$capability.'") is true at every gate and every status, so no claim keyed to '
                .'it can ever fail.',
            );
        }
    }
}
