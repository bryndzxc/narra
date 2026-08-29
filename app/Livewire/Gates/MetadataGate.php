<?php

namespace App\Livewire\Gates;

use App\Actions\ComposeDescription;
use App\Actions\ValidateYoutubeMetadata;
use App\Enums\Gate;
use App\Enums\MetadataStatus;
use App\Enums\StoryStatus;
use App\Models\Story;
use App\Models\YoutubeMetadata;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Gate 4 — the publish sheet.
 *
 * The app produces a file and a metadata sheet. It does not upload, and there
 * is no button here that talks to YouTube. Approving this gate marks the story
 * published in our records; the operator uploads, sets the synthetic-content
 * disclosure, and schedules it themselves.
 *
 * The five title variants and the drafted copy come from a provider in Phase 2.
 * Everything mechanical — chapters from act timings, the tag budget, the
 * character limits, the checklist — is here now, because it is arithmetic
 * rather than writing and it is what silently breaks an upload.
 */
class MetadataGate extends Component
{
    public Story $story;

    public YoutubeMetadata $metadata;

    public string $titleSelected = '';

    /** @var array<int, string> */
    public array $titleOptions = [];

    public string $newTitleOption = '';

    public string $description = '';

    public string $tagsInput = '';

    public string $thumbnailTextInput = '';

    public ?int $thumbnailSceneId = null;

    public string $pinnedComment = '';

    /** @var array<string, bool> */
    public array $checklist = [];

    public ?string $notice = null;

    public function mount(Story $story): void
    {
        $this->story = $story;

        $this->metadata = YoutubeMetadata::query()->firstOrCreate(
            ['story_id' => $story->id],
            ['status' => MetadataStatus::Pending]
        );

        $this->titleSelected = (string) $this->metadata->title_selected;
        $this->titleOptions = $this->metadata->title_options ?? [];
        $this->description = (string) $this->metadata->description;
        $this->tagsInput = implode(', ', $this->metadata->tags ?? []);
        $this->thumbnailTextInput = implode("\n", $this->metadata->thumbnail_text_options ?? []);
        $this->thumbnailSceneId = $this->metadata->thumbnail_scene_id
            ?? $story->scenes()->where('is_thumbnail_candidate', true)->value('id');
        $this->pinnedComment = (string) $this->metadata->pinned_comment;

        $state = $this->metadata->checklist_state ?? [];

        foreach (config('youtube.checklist') as $key => $item) {
            $this->checklist[$key] = (bool) ($state[$key] ?? false);
        }
    }

    #[Computed]
    public function editable(): bool
    {
        return in_array($this->story->status, [StoryStatus::Rendered, StoryStatus::MetadataReady], true);
    }

    #[Computed]
    public function canApprove(): bool
    {
        return $this->story->status === StoryStatus::MetadataReady && $this->validation()['blocking'] === [];
    }

    /**
     * The sheet describes a render that was thrown away when Gate 2 reopened.
     *
     * Kept visible rather than hidden or wiped: the operator's own words in the
     * description are worth preserving, and the wrong thing to do with a wrong
     * chapter list is to make it disappear quietly.
     */
    #[Computed]
    public function stale(): bool
    {
        return $this->metadata->isStale();
    }

    /** Whether the re-render this sheet is waiting on has actually happened. */
    #[Computed]
    public function canRegenerate(): bool
    {
        return $this->metadata->canClearStale();
    }

    #[Computed]
    public function validation(): array
    {
        return app(ValidateYoutubeMetadata::class)->handle($this->metadata);
    }

    #[Computed]
    public function chapters(): array
    {
        return $this->metadata->chapters();
    }

    /**
     * Live tag budget. 500 characters across all tags, separators included —
     * enforced rather than silently truncated at upload.
     */
    #[Computed]
    public function tagBudget(): array
    {
        $tags = $this->parsedTags();
        $used = YoutubeMetadata::charCountFor($tags);
        $budget = (int) config('youtube.limits.tags_chars');

        return [
            'tags' => $tags,
            'used' => $used,
            'budget' => $budget,
            'over' => max(0, $used - $budget),
            'percent' => min(100, (int) round($used / max(1, $budget) * 100)),
        ];
    }

    /**
     * @return array<int, array{id: int, sequence: int, flagged: bool}>
     */
    #[Computed]
    public function thumbnailChoices(): array
    {
        return $this->story->scenes()
            ->where(fn ($q) => $q->where('is_thumbnail_candidate', true)->orWhere('is_hook', true))
            ->orderBy('sequence')
            ->get()
            ->map(fn ($scene): array => [
                'id' => $scene->id,
                'sequence' => $scene->sequence,
                'flagged' => (bool) $scene->is_thumbnail_candidate,
            ])->all();
    }

    public function addTitleOption(): void
    {
        $title = trim($this->newTitleOption);

        if ($title === '') {
            return;
        }

        $this->titleOptions[] = $title;
        $this->newTitleOption = '';
        $this->save();
    }

    public function removeTitleOption(int $index): void
    {
        unset($this->titleOptions[$index]);
        $this->titleOptions = array_values($this->titleOptions);
        $this->save();
    }

    public function chooseTitle(int $index): void
    {
        $this->titleSelected = $this->titleOptions[$index] ?? $this->titleSelected;
        $this->save();
    }

    /**
     * Rebuild the description from whatever the operator has written above the
     * chapter list, plus the derived chapters and the channel footer.
     *
     * Idempotent by construction: the opening is taken as everything before the
     * chapter block, so pressing this twice does not stack two chapter lists.
     */
    public function insertChapters(): void
    {
        $this->authorizeEdit();

        $opening = trim(preg_split('/^Chapters:$/m', $this->description)[0] ?? '');

        $this->description = app(ComposeDescription::class)->handle($this->metadata, $opening);

        $this->save();
        $this->notice = 'Chapters and footer rebuilt from the act timings.';
    }

    /**
     * Rebuild the sheet against the render that exists now, and lift the mark.
     *
     * This is the only way out of Stale, and it is deliberately not the save
     * button: saving is typing, and typing does not make a chapter timestamp
     * correct. Only a newer successful mux does, because only a mux fills in
     * the act timings the chapters are derived from — which is checked, not
     * assumed. See YoutubeMetadata::hasFreshRender().
     */
    public function regenerate(): void
    {
        $this->authorizeEdit();

        abort_unless(
            $this->canRegenerate(),
            403,
            'This sheet is waiting on a render. Its chapter timestamps come from act timings, and those '
            .'do not exist again until the story has been re-rendered.'
        );

        $opening = trim(preg_split('/^Chapters:$/m', $this->description)[0] ?? '');

        $this->description = app(ComposeDescription::class)->handle($this->metadata, $opening);

        $this->metadata->clearStale();
        $this->metadata->refresh();

        $this->save();

        unset($this->stale, $this->canRegenerate, $this->validation, $this->chapters);

        $this->notice = 'Sheet regenerated against the new render. Chapter timestamps rebuilt from the current act timings.';
    }

    public function save(): void
    {
        $this->authorizeEdit();

        // Checked here as well as in the validator, because these two have a
        // column behind them: title_selected is varchar(100) precisely because
        // 100 is YouTube's limit, and an over-long title would otherwise reach
        // MySQL and come back as a truncation error rather than as a sentence
        // the operator can act on.
        $this->validate([
            'titleSelected' => ['nullable', 'string', 'max:'.config('youtube.limits.title_hard')],
            'description' => ['nullable', 'string', 'max:'.config('youtube.limits.description')],
        ], [
            'titleSelected.max' => 'YouTube truncates titles at :max characters. Shorten it, or the tail is lost.',
            'description.max' => 'The description limit is :max characters.',
        ]);

        $this->metadata->fill([
            'title_selected' => $this->titleSelected ?: null,
            'title_options' => $this->titleOptions ?: null,
            'description' => $this->description ?: null,
            'tags' => $this->parsedTags() ?: null,
            'thumbnail_text_options' => $this->parsedThumbnailText() ?: null,
            'thumbnail_scene_id' => $this->thumbnailSceneId,
            'pinned_comment' => $this->pinnedComment ?: null,
            'checklist_state' => $this->checklist,
        ]);

        // Pending only. A Stale sheet is NOT lifted by saving it — see
        // regenerate(). Typing does not make a chapter timestamp true.
        if ($this->metadata->status === MetadataStatus::Pending && $this->titleSelected !== '') {
            $this->metadata->status = MetadataStatus::Generated;
        }

        $this->metadata->save();

        // Deliberately no status change here. `rendered -> metadata_ready` is
        // Gate 3's crossing, not a side effect of typing a title: the operator
        // has to have watched the render. The sheet can be drafted before that
        // — it just cannot be approved until Gate 3 is.
        $this->metadata->refresh();
        $this->notice ??= 'Saved.';
    }

    public function approve(): void
    {
        $this->save();

        $problems = $this->validation()['blocking'];

        abort_if($problems !== [], 422, 'The publish sheet still has blocking problems: '.implode(' ', $problems));

        $this->metadata->update(['status' => MetadataStatus::Approved]);
        $this->story->approveGate(Gate::Metadata);
        $this->story->refresh();

        $this->notice = 'Gate 4 approved. The sheet is ready to copy — the upload itself is yours to do.';
    }

    public function render(): View
    {
        return view('livewire.gates.metadata-gate');
    }

    /**
     * @return array<int, string>
     */
    private function parsedTags(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[,\n]/', $this->tagsInput) ?: []
        ), fn (string $tag): bool => $tag !== ''));
    }

    /**
     * @return array<int, string>
     */
    private function parsedThumbnailText(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/\n/', $this->thumbnailTextInput) ?: []
        ), fn (string $line): bool => $line !== ''));
    }

    private function authorizeEdit(): void
    {
        abort_unless($this->editable(), 403, 'The publish sheet is locked once the story is published.');
    }
}
