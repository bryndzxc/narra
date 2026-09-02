<?php

namespace App\Livewire\Gates;

use App\Actions\EstimateCharacterSheets;
use App\Actions\GenerateCharacterSheet;
use App\Actions\SelectCharacterReference;
use App\Actions\ValidateCharacterSheets;
use App\Models\Character;
use App\Models\CharacterReference;
use App\Models\Story;
use App\Support\CharacterSheetEstimate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * Gate 2's sub-step: pick a face for every character before any still is
 * bought.
 *
 * The first screen in this app behind which real money moves, so it is built
 * around the two things that makes true rather than around browsing:
 *
 * **The cost is on screen before the button is.** Per sheet and projected
 * total, itemised, with the provider and model named. The operator reads the
 * bill and then decides — they never discover it in the ledger afterwards.
 *
 * **The operator produces nothing themselves.** Candidates are generated from
 * the description already in the characters table. There is no upload field and
 * no prompt box: a description that is wrong is fixed by fixing the
 * description, which is the same text 150-250 prompts are built from, rather
 * than by hand-feeding this one screen something the scene prompts will never
 * see.
 *
 * Generation runs synchronously rather than through the queue, and that is a
 * deliberate exception to how the rest of the pipeline works. A sheet is four
 * images and tens of seconds; the operator is sitting in front of it waiting to
 * choose. Fanning it out to a worker would buy nothing and cost the one thing
 * this screen is for — seeing the result and picking.
 */
class CharacterSheets extends Component
{
    public Story $story;

    /** Character whose sheet the confirmation is currently armed for. */
    public ?int $confirming = null;

    public ?string $notice = null;

    public ?string $problem = null;

    public function mount(Story $story): void
    {
        $this->story = $story;
    }

    /**
     * @return Collection<int, Character>
     */
    #[Computed]
    public function cast(): Collection
    {
        return $this->story->characters()
            ->with(['references' => fn ($q) => $q->orderBy('batch')->orderBy('sequence')])
            ->withCount('scenes')
            ->orderByDesc('scenes_count')
            ->orderBy('name')
            ->get();
    }

    /** What generating every outstanding sheet would cost. */
    #[Computed]
    public function estimate(): CharacterSheetEstimate
    {
        return app(EstimateCharacterSheets::class)->handle($this->story);
    }

    /** What one named character's sheet would cost, regeneration included. */
    public function estimateFor(int $characterId): CharacterSheetEstimate
    {
        return app(EstimateCharacterSheets::class)
            ->handle($this->story, $this->character($characterId));
    }

    /**
     * @return array{ready: bool, missing: Collection<int, Character>, scenes_blocked: int, unused: Collection<int, Character>}
     */
    #[Computed]
    public function readiness(): array
    {
        return app(ValidateCharacterSheets::class)->handle($this->story);
    }

    /**
     * Whether spending is permitted from where this story actually is.
     *
     * The same predicate the model guard uses, not a paraphrase of it. Showing
     * a button off one expression and refusing the action from another is how
     * an operator ends up in front of a move the app was never going to allow.
     */
    #[Computed]
    public function canGenerate(): bool
    {
        return $this->story->canGenerateReferences();
    }

    public function askToGenerate(int $characterId): void
    {
        $this->problem = null;
        $this->confirming = $characterId;
    }

    public function cancel(): void
    {
        $this->confirming = null;
    }

    public function generate(int $characterId): void
    {
        abort_unless($this->canGenerate(), 403, 'Character sheets cannot be generated from this status.');

        $character = $this->character($characterId);
        $estimate = $this->estimateFor($characterId);

        try {
            $produced = app(GenerateCharacterSheet::class)->handle($character);
        } catch (Throwable $e) {
            $this->confirming = null;
            $this->problem = $e->getMessage();

            return;
        }

        $failed = $produced->filter(fn (CharacterReference $r): bool => ! $r->isUsable());

        $this->confirming = null;
        $this->forget();

        // The quote and the charge, side by side. Every candidate is billed
        // including the ones that failed, so an operator who sees "4 generated,
        // 1 failed" should also see that they paid for the attempt.
        $this->notice = sprintf(
            '%s: %d candidate(s) generated%s. Quoted $%s.',
            $character->name,
            $produced->count(),
            $failed->isEmpty()
                ? ''
                : sprintf(', %d failed (still billed — every attempt is charged)', $failed->count()),
            number_format($estimate->usdPerSheet(), 4),
        );
    }

    public function select(int $referenceId): void
    {
        $reference = CharacterReference::findOrFail($referenceId);
        $character = $this->character($reference->character_id);

        try {
            $invalidated = app(SelectCharacterReference::class)->handle($character, $reference);
        } catch (Throwable $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->forget();

        $this->notice = $invalidated === 0
            ? sprintf(
                '%s is locked to candidate %d. Every still they appear in will be generated with '
                .'this face.',
                $character->name,
                $reference->sequence,
            )
            // Nothing is deleted here, in keeping with how reopening Gate 2
            // behaves. The count is stated and the cost of acting on it is
            // shown at the gate, where the operator decides.
            : sprintf(
                '%s is now candidate %d. %d still(s) already generated show the previous face and '
                .'are now stale — nothing has been deleted; reopen Gate 2 to see what regenerating '
                .'them costs.',
                $character->name,
                $reference->sequence,
                $invalidated,
            );
    }

    public function render(): View
    {
        return view('livewire.gates.character-sheets');
    }

    private function character(int $id): Character
    {
        return $this->story->characters()->whereKey($id)->firstOrFail();
    }

    private function forget(): void
    {
        unset($this->cast, $this->estimate, $this->readiness);
    }
}
