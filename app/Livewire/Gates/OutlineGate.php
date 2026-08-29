<?php

namespace App\Livewire\Gates;

use App\Enums\Gate;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\Story;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Gate 1 — the operator writes or edits the premise and approves the act
 * outline.
 *
 * This is the cheapest gate to get right and the most expensive to get wrong.
 * Everything downstream is generated against this outline: 5,500-8,000 words of
 * script, then 150-250 stills. An act that does not work here produces ten
 * minutes of video nobody watches, and the cost of finding out is the whole
 * pipeline.
 */
class OutlineGate extends Component
{
    public Story $story;

    public string $premise = '';

    /** @var array<int, array{id: int, sequence: int, title: string, summary: string, is_rehook_written: bool}> */
    public array $acts = [];

    public ?string $saved = null;

    public function mount(Story $story): void
    {
        $this->story = $story;
        $this->premise = (string) $story->premise;
        $this->loadActs();
    }

    /**
     * Editable up to the moment the gate is crossed, and again if the operator
     * explicitly reopens it. Never editable while a render is in flight.
     */
    #[Computed]
    public function editable(): bool
    {
        return in_array($this->story->status, [StoryStatus::Draft, StoryStatus::Outlined], true);
    }

    #[Computed]
    public function canApprove(): bool
    {
        return $this->story->status === StoryStatus::Outlined;
    }

    /**
     * The re-hook check, surfaced rather than buried.
     *
     * A 15-second opening hook is not enough over 35 minutes: every act has to
     * open with a line that carries the viewer forward, and the act that
     * quietly does not is the one where the retention graph falls off.
     */
    #[Computed]
    public function actsMissingRehooks(): array
    {
        return array_values(array_filter(
            $this->acts,
            fn (array $act): bool => $act['sequence'] > 1 && ! $act['is_rehook_written']
        ));
    }

    public function save(): void
    {
        $this->authorizeEdit();

        $this->validate([
            'premise' => ['required', 'string', 'min:20'],
            'acts.*.title' => ['required', 'string', 'max:100'],
            'acts.*.summary' => ['nullable', 'string', 'max:2000'],
        ], [
            'acts.*.title.max' => 'An act title doubles as a YouTube chapter title; keep it under 100 characters.',
        ]);

        $this->story->update(['premise' => $this->premise]);

        foreach ($this->acts as $act) {
            Act::query()->whereKey($act['id'])->update([
                'title' => $act['title'],
                'summary' => $act['summary'],
                'is_rehook_written' => $act['is_rehook_written'],
            ]);
        }

        // draft -> outlined is an ordinary move, not a gate: the gate is the
        // NEXT step, and it needs the operator to press the other button.
        if ($this->story->status === StoryStatus::Draft) {
            $this->story->transitionTo(StoryStatus::Outlined);
        }

        $this->saved = 'Outline saved.';
        $this->story->refresh();
    }

    public function approve(): void
    {
        $this->save();

        $this->story->approveGate(Gate::Outline);
        $this->story->refresh();

        $this->saved = 'Gate 1 approved. Act scripts can be generated from this outline.';
    }

    /**
     * Back through the gate. Legal, and deliberately explicit: the scripts
     * written against this outline do not disappear, and regenerating them
     * costs money from Phase 2.
     */
    public function reopen(): void
    {
        $this->story->transitionTo(StoryStatus::Outlined);
        $this->story->refresh();

        $this->saved = 'Gate 1 reopened. Scripts already written against the old outline are still there.';
    }

    public function render(): View
    {
        return view('livewire.gates.outline-gate');
    }

    private function loadActs(): void
    {
        $this->acts = $this->story->acts()->get()->map(fn (Act $act): array => [
            'id' => $act->id,
            'sequence' => $act->sequence,
            'title' => (string) $act->title,
            'summary' => (string) $act->summary,
            'is_rehook_written' => (bool) $act->is_rehook_written,
        ])->all();
    }

    private function authorizeEdit(): void
    {
        abort_unless($this->editable(), 403, 'The outline is locked once Gate 1 has been approved.');
    }
}
