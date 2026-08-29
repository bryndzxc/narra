<?php

namespace App\Livewire\Gates;

use App\Actions\ApproveScenesGate;
use App\Actions\ReorderScenes;
use App\Enums\MotionPreset;
use App\Enums\StoryStatus;
use App\Models\Scene;
use App\Models\Story;
use App\Support\SceneChangeSet;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Gate 2 — the last free moment.
 *
 * The operator reads every scene's narration and image prompt, edits, reorders
 * or deletes, and only then approves. Everything after this gate bills: 150-250
 * image generations at roughly 70% of a video's cost, plus TTS and
 * transcription per scene. A bad prompt found here is a text edit; found after
 * approval it is a re-bill.
 *
 * The paid-asset line is enforced on the model, not here — this component is
 * the place an operator crosses it, not the thing that decides they may.
 */
class ScenesGate extends Component
{
    use WithPagination;

    /** 200+ scenes is the design point. A full list would be unreadable and slow. */
    private const PER_PAGE = 20;

    public Story $story;

    /** Scene currently open for editing, by id. */
    public ?int $editing = null;

    public string $narration = '';

    public string $imagePrompt = '';

    public string $motion = '';

    public bool $isHook = false;

    public bool $isThumbnailCandidate = false;

    public ?string $notice = null;

    public bool $confirmingApproval = false;

    public function mount(Story $story): void
    {
        $this->story = $story;
    }

    #[Computed]
    public function editable(): bool
    {
        return in_array($this->story->status, [StoryStatus::Scripted, StoryStatus::ScenesDrafted], true);
    }

    #[Computed]
    public function canApprove(): bool
    {
        return $this->story->status === StoryStatus::ScenesDrafted;
    }

    /**
     * Whether Gate 2 may be reopened from where this story actually is.
     *
     * Not `! editable()`. That negation is true for seven statuses — including
     * `published`, which is terminal, and `rendering`, which has a clip batch in
     * flight — and only one of them ever had the transition. Offering the button
     * on the strength of "the scenes are locked" is what put an operator in
     * front of a move the state machine had never been told about. The predicate
     * that guards the move is the predicate that shows the button.
     */
    #[Computed]
    public function canReopen(): bool
    {
        return $this->story->status->canReopenScenesGate();
    }

    /** Why the button is absent, when the operator might expect it. */
    #[Computed]
    public function reopenRefusal(): ?string
    {
        return $this->story->status->reopenRefusalReason();
    }

    /**
     * What approving this gate is about to do, and what it is about to charge.
     *
     * On a first approval this is every scene, exactly as before. On a
     * re-approval after a reopen it is only what actually changed — a count of
     * five stills when five prompts were edited, not two hundred and fifty
     * because the operator opened the page. Same computation both times: each
     * asset is needed if it is absent OR stale, and on a first approval nothing
     * exists.
     */
    #[Computed]
    public function changes(): SceneChangeSet
    {
        return SceneChangeSet::for($this->story);
    }

    /**
     * @return array{scenes: int, images: int, narrations: int, transcriptions: int, preserved: int}
     */
    #[Computed]
    public function costPreview(): array
    {
        $changes = $this->changes();

        return [
            'scenes' => $changes->sceneCount,
            'images' => $changes->needsImage->count(),
            'narrations' => $changes->needsNarration->count(),
            'transcriptions' => $changes->needsTranscription->count(),
            'preserved' => $changes->preserved(),
        ];
    }

    #[Computed]
    public function warnings(): array
    {
        $warnings = [];

        $missingPrompt = $this->story->scenes()
            ->where(fn ($q) => $q->whereNull('image_prompt')->orWhere('image_prompt', ''))
            ->count();

        if ($missingPrompt > 0) {
            $warnings[] = "{$missingPrompt} scene(s) have no image prompt.";
        }

        if ($this->story->scenes()->where('is_hook', true)->count() === 0) {
            $warnings[] = 'No scene is flagged as the opening hook.';
        }

        if ($this->story->scenes()->where('is_thumbnail_candidate', true)->count() === 0) {
            $warnings[] = 'No scene flagged as a thumbnail candidate. Gate 4 will ask for one.';
        }

        return $warnings;
    }

    public function edit(int $sceneId): void
    {
        $scene = $this->scene($sceneId);

        $this->editing = $scene->id;
        $this->narration = (string) $scene->narration_text;
        $this->imagePrompt = (string) $scene->image_prompt;
        $this->motion = $scene->motion_preset->value;
        $this->isHook = (bool) $scene->is_hook;
        $this->isThumbnailCandidate = (bool) $scene->is_thumbnail_candidate;
    }

    public function cancelEdit(): void
    {
        $this->editing = null;
    }

    public function saveScene(): void
    {
        $this->authorizeEdit();

        $this->validate([
            'narration' => ['required', 'string', 'min:5'],
            'imagePrompt' => ['nullable', 'string', 'max:2000'],
            'motion' => ['required', 'string'],
        ]);

        $scene = $this->scene((int) $this->editing);

        // One hook per story. Flagging a new one unflags the old rather than
        // leaving two scenes both claiming to be the opening.
        if ($this->isHook) {
            $this->story->scenes()->whereKeyNot($scene->id)->update(['is_hook' => false]);
        }

        $scene->update([
            'narration_text' => $this->narration,
            'image_prompt' => $this->imagePrompt ?: null,
            'motion_preset' => MotionPreset::from($this->motion),
            'is_hook' => $this->isHook,
            'is_thumbnail_candidate' => $this->isThumbnailCandidate,
        ]);

        $this->editing = null;
        $this->notice = "Scene {$scene->sequence} saved.";
    }

    public function move(int $sceneId, int $direction): void
    {
        $this->authorizeEdit();

        $position = app(ReorderScenes::class)->move($this->scene($sceneId), $direction);

        if ($position !== null) {
            $this->notice = "Scene moved to position {$position}.";
        }
    }

    public function deleteScene(int $sceneId): void
    {
        $this->authorizeEdit();

        $sequence = app(ReorderScenes::class)->delete($this->scene($sceneId));

        $this->notice = "Scene {$sequence} deleted and the rest renumbered.";
    }

    public function askToApprove(): void
    {
        $this->confirmingApproval = true;
    }

    public function cancelApproval(): void
    {
        $this->confirmingApproval = false;
    }

    public function approve(): void
    {
        abort_unless($this->canApprove(), 403, 'Gate 2 is not the gate this story is waiting at.');

        $changes = app(ApproveScenesGate::class)->handle($this->story);

        $this->story->refresh();
        unset($this->changes, $this->costPreview);

        $this->confirmingApproval = false;
        $this->notice = $changes->billsAnything()
            ? sprintf(
                'Gate 2 approved. %d image(s) and %d narration(s) will be generated; %d scene(s) kept what they already had.',
                $changes->needsImage->count(),
                $changes->needsNarration->count(),
                $changes->preserved()
            )
            : 'Gate 2 approved. Nothing changed, so nothing is regenerated and nothing is billed.';
    }

    /**
     * Reopen the gate to fix a scene.
     *
     * Destroys nothing. An operator who reopens, reads scene 147 and changes
     * their mind has spent nothing and lost nothing — not the stills, not the
     * narration, not even the finished render. What actually went stale is
     * worked out and applied when the gate is approved again, where the cost is
     * shown before it is incurred.
     */
    public function reopen(): void
    {
        abort_unless($this->canReopen(), 403, (string) $this->reopenRefusal());

        $from = $this->story->status;

        $this->story->reopenScenesGate();
        $this->story->refresh();
        unset($this->changes, $this->costPreview, $this->canReopen, $this->editable);

        $this->notice = sprintf(
            "Gate 2 reopened from '%s'. Nothing has been deleted. Only scenes whose narration or "
            .'image prompt you actually change will be regenerated when you approve again%s',
            $from->value,
            $from->rank() >= StoryStatus::Rendered->rank()
                ? ' — but any change at all discards the finished render, which has to be rebuilt.'
                : '.'
        );
    }

    public function render(): View
    {
        return view('livewire.gates.scenes-gate', [
            'scenes' => $this->story->scenes()->with('act')->paginate(self::PER_PAGE),
            'motions' => MotionPreset::cases(),
        ]);
    }

    private function scene(int $id): Scene
    {
        return $this->story->scenes()->whereKey($id)->firstOrFail();
    }

    private function authorizeEdit(): void
    {
        abort_unless(
            $this->editable(),
            403,
            'Scenes are locked once Gate 2 has been approved. Reopen the gate to edit them.'
        );
    }
}
