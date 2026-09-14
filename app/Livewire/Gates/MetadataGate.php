<?php

namespace App\Livewire\Gates;

use App\Actions\AssertWorkersCurrent;
use App\Actions\ComposeDescription;
use App\Actions\ComposeThumbnails;
use App\Actions\DeliverThumbnail;
use App\Actions\ValidateYoutubeMetadata;
use App\Contracts\MetadataWriter;
use App\Enums\Gate;
use App\Enums\MetadataStatus;
use App\Enums\OperatorAction;
use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
use App\Enums\StoryStatus;
use App\Exceptions\DispatchRefusedException;
use App\Jobs\GenerateMetadataJob;
use App\Models\RenderJob;
use App\Models\Story;
use App\Models\YoutubeMetadata;
use App\Support\ChapterRules;
use App\Support\GateVoice;
use App\Support\ModelRoster;
use App\Support\PublishChecklist;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use App\Support\RefusedFields;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

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

    /**
     * The composed split-panel candidate the operator picked.
     *
     * A composition rather than a still, and picked here the way a title is:
     * four options, one choice, and the choice is what gets copied out beside
     * the video. `thumbnailSceneId` is a different question and stays — that
     * one names the single representative frame, and this one is a pair.
     */
    public string $thumbnailChoice = '';

    public string $pinnedComment = '';

    /**
     * The scheduled publish time, typed in US Eastern.
     *
     * Eastern rather than Manila, and that is the whole point of the field.
     * Peak US viewing is 6-10 PM ET, which is the small hours here — so the
     * decision is made in ET and the conversion is the app's job, because a
     * conversion done in somebody's head at 1am is the thing that gets fumbled.
     * Stored in UTC; both zones are displayed back.
     *
     * The column, `Story::targetPublishAtEastern()`, `targetPublishAtManila()`
     * and a two-timezone block on the stories index all existed before this
     * input did — so the column was always null, the block never rendered, and
     * the Gate 4 checklist asked the operator to confirm a time the app had no
     * way to hold. This is the input those were written for.
     */
    public string $publishAtEastern = '';

    /** @var array<string, bool> */
    public array $checklist = [];

    /**
     * The checklist with the VALUE each item is asking about.
     *
     * Computed rather than stored: every channel item comes from config and
     * every per-story one from the record in front of us, so there is nothing
     * here worth holding in Livewire state where it could go stale against the
     * config it was read from.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function checklistItems(): array
    {
        return PublishChecklist::items($this->story, $this->metadata);
    }

    public ?string $notice = null;

    public ?string $problem = null;

    /** Guard on the drafting button: three billed calls, so it asks first. */
    public bool $confirmingDraft = false;

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
        $this->thumbnailChoice = (string) $this->metadata->thumbnail_selected;
        $this->pinnedComment = (string) $this->metadata->pinned_comment;
        $this->publishAtEastern = $story->targetPublishAtEastern()?->format('Y-m-d\TH:i') ?? '';

        $state = $this->metadata->checklist_state ?? [];

        foreach (config('youtube.checklist') as $key => $item) {
            $this->checklist[$key] = (bool) ($state[$key] ?? false);
        }
    }

    /**
     * How this page is allowed to talk about its own decisions.
     *
     * One sentence uses it so far, and that sentence was wrong: "N thing(s)
     * block approval" rendered on a story at `draft`. The body of this gate is
     * still to be rebuilt; the mechanism is not gate 4's to invent when it is.
     */
    #[Computed]
    public function voice(): GateVoice
    {
        return GateVoice::for(Gate::Metadata, $this->story->status);
    }

    #[Computed]
    public function editable(): bool
    {
        return in_array($this->story->status, [StoryStatus::Rendered, StoryStatus::MetadataReady], true);
    }

    /**
     * The story is past this gate, so writing the sheet is not an action that
     * can exist here any more.
     *
     * NOT `! editable()`. That is false on both sides of the gate — at `draft`
     * as much as at `published` — and the two want opposite treatment. Before
     * the gate the refusal is the only thing on the page that says what has to
     * happen first, so it stays and it stays loud. After it, the strip above
     * has already said the gate is behind this story and why the sheet is
     * read-only, and the drafting panel repeats that in red under a surface
     * offering a button it cannot draw.
     *
     * A refusal repeated in a state that has already been explained is chrome,
     * and chrome is what the alarm-band rule exists to prevent: this file's
     * standing rule is that no refusal gets QUIETER, not that a refusal may
     * never stop being repeated by a second surface. Nothing here is toned
     * down — `draftBlockers()` is untouched, `draft()` still refuses through
     * `OperatorAction::WriteMetadata`, and `metadata:generate` still refuses at
     * the same statuses. What goes is a panel offering an action that cannot
     * exist, which is `x-gate-group`'s empty slot at the size of a section.
     */
    #[Computed]
    public function pastThisGate(): bool
    {
        return $this->story->status->rank() > Gate::Metadata->waitsAt()->rank();
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

    // -- Thumbnail composition -----------------------------------------------

    /**
     * The composed candidates, as they were stored.
     *
     * Read off the record rather than recomposed on render: composing shells
     * out to FFmpeg four times, and a computed property runs on every Livewire
     * round trip — a checkbox tick would rebuild four JPEGs.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function thumbnailOptions(): array
    {
        return (array) ($this->metadata->thumbnail_options ?? []);
    }

    /**
     * Whether composing is possible, and what is missing when it is not.
     *
     * Said rather than swallowed. A button that quietly disappears when a story
     * has no stills is a page saying nothing where it should say why.
     */
    #[Computed]
    public function thumbnailBlocker(): ?string
    {
        if (! $this->editable()) {
            return 'The sheet is read-only once Gate 4 is approved.';
        }

        $stills = $this->story->scenes()->whereNotNull('image_path')->count();

        return $stills >= 2
            ? null
            : sprintf(
                'This story has %d still with an image on disk, and a split panel needs two. Stills '
                .'are generated after Gate 2.',
                $stills,
            );
    }

    /**
     * Build the candidates from stills the story already owns.
     *
     * Free, and that is the constraint the feature is built around rather than
     * a side benefit: it crops frames that have already been paid for and never
     * generates one. So there is no confirm step here, unlike the drafting
     * button beneath it — the thing a confirm protects against is a bill.
     */
    public function composeThumbnails(): void
    {
        $this->problem = null;
        $this->notice = null;

        if (($blocked = $this->thumbnailBlocker()) !== null) {
            $this->problem = $blocked;

            return;
        }

        try {
            $result = app(ComposeThumbnails::class)->handle($this->story);
        } catch (Throwable $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->metadata->refresh();
        $this->thumbnailChoice = (string) $this->metadata->thumbnail_selected;
        unset($this->thumbnailOptions);

        $this->notice = sprintf(
            '%d thumbnail composition(s) built from %d still(s). Nothing was generated and nothing '
            .'was billed — these are frames this story already owns. %s',
            count($result['composed']),
            $result['pool'],
            implode(' ', $result['notes']),
        );
    }

    // -- Drafting ------------------------------------------------------------

    /**
     * Whether the sheet can be written at all, and why not when it cannot.
     *
     * The chapter rules are checked HERE, before the button is offered, rather
     * than discovered by the stage after it has billed three calls. They are
     * the same four rules the gate validates and the same object applies them.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function draftBlockers(): array
    {
        $blockers = app(ChapterRules::class)->problems($this->metadata->chapters());

        // The shared predicate, not a second expression of it. This block used
        // to compare the status rank here and nowhere else, which meant the
        // page and `metadata:generate` each carried their own answer to "may
        // this run" — the arrangement that has produced this bug five times.
        $refusal = OperatorAction::WriteMetadata->refusalReason($this->story->status);

        if ($refusal !== null) {
            $blockers[] = ucfirst($refusal);
        }

        if ($this->stale() && ! $this->metadata->hasFreshRender()) {
            $blockers[] = 'This sheet describes a render that was thrown away. Re-render first.';
        }

        return array_values(array_unique($blockers));
    }

    /** Whether pressing the button would overwrite copy that already exists. */
    #[Computed]
    public function draftWouldOverwrite(): bool
    {
        return ($this->metadata->title_options ?? []) !== [];
    }

    /** The models the three calls will bill against, named before they run. */
    #[Computed]
    public function draftRoster(): array
    {
        return app(ModelRoster::class)->lines(ModelRoster::METADATA_OPERATIONS);
    }

    /** Whether the drafting stage is queued or running right now. */
    #[Computed]
    public function drafting(): bool
    {
        $job = RenderJob::query()
            ->where('story_id', $this->story->id)
            ->where('stage', RenderStage::Metadata)
            ->whereNull('scene_id')
            ->latest('id')
            ->first();

        return $job !== null && $job->status === RenderJobStatus::Running;
    }

    /** The last drafting run's outcome, for the page to report. */
    #[Computed]
    public function lastDraftJob(): ?RenderJob
    {
        return RenderJob::query()
            ->where('story_id', $this->story->id)
            ->where('stage', RenderStage::Metadata)
            ->whereNull('scene_id')
            ->latest('id')
            ->first();
    }

    public function askToDraft(): void
    {
        $this->authorizeEdit();

        $this->problem = null;
        $this->confirmingDraft = true;
    }

    public function cancelDraft(): void
    {
        $this->confirmingDraft = false;
    }

    /**
     * Queue the three calls that write the sheet.
     *
     * Queued rather than run here: one of them is a thinking model at high
     * effort and a Livewire request is the wrong place to hold that open. It
     * goes on the `text` queue, which is the queue the setup docs have been
     * telling operators to run a worker for since Phase 1 and which nothing in
     * the app had ever dispatched to.
     *
     * The worker check is BEFORE the dispatch and not inside the job, for the
     * reason that guard exists: the dispatching process is the only one with
     * fresh code by construction, so it is the only one that can honestly
     * decide whether the worker is stale.
     */
    public function draft(): void
    {
        $this->authorizeEdit();

        $this->confirmingDraft = false;
        $this->problem = null;

        if ($this->draftBlockers() !== []) {
            $this->problem = 'The sheet cannot be written yet: '.implode(' ', $this->draftBlockers());

            return;
        }

        try {
            $workers = app(AssertWorkersCurrent::class)->handle((string) config('render.queues.text'));
        } catch (DispatchRefusedException $e) {
            $this->problem = $e->getMessage();

            return;
        }

        // A stale worker is a refusal and an ABSENT one is not — nothing is
        // lost, the job waits. But "queued" and "queued, and nothing is
        // listening" must not read the same on this page: an operator told the
        // sheet is being written, watching a page that never changes, is the
        // reporting failure this project keeps finding rather than a new one.
        $warnings = array_column(
            array_filter($workers, fn (array $line): bool => $line['level'] !== 'ok'),
            'message'
        );

        GenerateMetadataJob::dispatch($this->story->id, force: $this->draftWouldOverwrite());

        $provider = app(MetadataWriter::class);

        $this->notice = sprintf(
            'Queued on the "%s" queue against %s. Three calls, and the page will show them when it '
            .'reloads. Nothing is selected for you — five titles are written and picking one is this '
            .'gate.',
            config('render.queues.text'),
            // From the container, not from config. Those are two different
            // questions and the one time they disagreed this app reported a
            // vendor it had never contacted.
            $provider->isSimulated() ? $provider->providerName().' (SIMULATED — nothing billed)' : $provider->providerName(),
        );

        if ($warnings !== []) {
            $this->problem = implode(' ', $warnings);
        }
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

    /**
     * The rules save() validates against. Public and static so a test can walk
     * every key and prove each refusal is visible beside the Save button.
     *
     * These two bounds are YouTube's — 100 and 5,000 — and were never sized by
     * feel: `config/youtube.php` holds them, the generator drops titles past
     * the hard limit after its call, and ValidateYoutubeMetadata blocks a
     * description over its limit. What this form had in common with Gate 1
     * and Gate 2 was not the number but the SILENCE: neither field had an
     * `@error`, so a refused save said nothing. Largest stored today: title
     * 88, description 965.
     *
     * @return array<string, array<int, string>>
     */
    public static function saveRules(): array
    {
        return [
            'titleSelected' => ['nullable', 'string', 'max:'.config('youtube.limits.title_hard')],
            'description' => ['nullable', 'string', 'max:'.config('youtube.limits.description')],
            'publishAtEastern' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ];
    }

    /**
     * Every validation failure on the sheet, labelled and anchored, for the
     * block beside Save. See RefusedFields.
     *
     * @return array<int, array{key: string, label: string, anchor: string, message: string}>
     */
    #[Computed]
    public function refusedFields(): array
    {
        return RefusedFields::from($this->getErrorBag(), fn (string $key): array => match ($key) {
            'titleSelected' => ['Selected title', 'title'],
            'description' => ['Description', 'description'],
            'publishAtEastern' => ['Scheduled publish time', 'publishat'],
            default => RefusedFields::plain($key),
        });
    }

    public function save(): void
    {
        $this->authorizeEdit();

        // A stale refusal must not outlive the press it was about.
        $this->resetErrorBag();
        unset($this->refusedFields);

        // Checked here as well as in the validator, because these two have a
        // column behind them: title_selected is varchar(100) precisely because
        // 100 is YouTube's limit, and an over-long title would otherwise reach
        // MySQL and come back as a truncation error rather than as a sentence
        // the operator can act on.
        $this->validate(self::saveRules(), [
            'titleSelected.max' => 'YouTube truncates titles at :max characters. Shorten it, or the tail is lost.',
            'description.max' => 'The description limit is :max characters.',
            'publishAtEastern.date_format' => 'Give the publish time as a date and a time, in US Eastern.',
        ]);

        // Parsed in Eastern and stored in UTC. The column is UTC because a
        // stored local time is a bug waiting on the next DST change: US Eastern
        // is UTC-5 in winter and UTC-4 in summer, and the target window is the
        // same clock time in both.
        $this->story->update([
            'target_publish_at' => $this->publishAtEastern === ''
                ? null
                : Carbon::createFromFormat('Y-m-d\TH:i', $this->publishAtEastern, 'America/New_York')->utc(),
        ]);

        $this->metadata->fill([
            'title_selected' => $this->titleSelected ?: null,
            'title_options' => $this->titleOptions ?: null,
            'description' => $this->description ?: null,
            'tags' => $this->parsedTags() ?: null,
            'thumbnail_text_options' => $this->parsedThumbnailText() ?: null,
            'thumbnail_scene_id' => $this->thumbnailSceneId,
            'thumbnail_selected' => $this->thumbnailChoice ?: null,
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
        $this->story->refresh();
        $this->notice ??= 'Saved.';

        // After the save, so the record is right whatever the copy does, and
        // reported rather than silent: this writes to a folder outside the
        // project, which is the one place in this app where "it worked" and
        // "it worked on my machine" can differ.
        $this->notice .= $this->deliverThumbnail();
    }

    /**
     * Copy the picked composition out beside the video, as `<slug>.jpg`.
     *
     * Returns what happened, for the notice. A failure here does not throw:
     * the sheet is saved and correct, the workspace still holds the image, and
     * an unplugged drive should not lose the operator's edits. It is SAID,
     * though — a delivery that did not happen and reports nothing is the
     * false-success shape this project keeps paying for.
     */
    private function deliverThumbnail(): string
    {
        if ($this->thumbnailChoice === '') {
            return '';
        }

        try {
            $delivered = app(DeliverThumbnail::class)->handle($this->story, $this->metadata);
        } catch (Throwable $e) {
            $this->problem = 'The sheet is saved, but the thumbnail could not be copied out: '
                .$e->getMessage();

            return '';
        }

        if (! $delivered['delivered']) {
            return ' '.(string) $delivered['reason'];
        }

        return sprintf(
            ' Thumbnail %s to %s.',
            $delivered['replaced'] ? 'replaced' : 'copied',
            (string) $delivered['destination'],
        );
    }

    /**
     * The scheduled publish time in both zones, or null.
     *
     * Both, always, and never just one. The schedule is reasoned about in ET
     * because that is where the viewers are, and executed from Manila where the
     * operator is — and that window lands in the small hours here, which is
     * exactly how a publish time gets fumbled.
     *
     * @return array{et: string, pht: string}|null
     */
    #[Computed]
    public function publishWindow(): ?array
    {
        $et = $this->story->targetPublishAtEastern();

        if ($et === null) {
            return null;
        }

        return [
            'et' => $et->format('D d M Y, H:i').' ET',
            'pht' => $this->story->targetPublishAtManila()?->format('D d M Y, H:i').' PHT',
        ];
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

    /**
     * Whether a sheet has actually been written, as opposed to a row existing.
     *
     * THE ROW IS NOT THE ANSWER AND CANNOT BE. `mount()` calls
     * `firstOrCreate()`, so opening this page on any story creates a
     * `youtube_metadata` row — two live stories have one for no other reason.
     * A page that took row existence for "the sheet is here" would render the
     * full form over something it manufactured itself, which is the original
     * Gate 4 defect wearing a producer: the form was there, the limits were
     * checked, and there was nothing behind any of it.
     *
     * The CONTENT is the answer. `pending` with no titles and no description is
     * a sheet nobody has written.
     */
    #[Computed]
    public function sheetGenerated(): bool
    {
        return $this->metadata->status !== MetadataStatus::Pending
            || $this->titleOptions !== []
            || trim($this->description) !== '';
    }

    /**
     * The title against its target and its hard limit.
     *
     * The mock draws a target-70 / hard-100 meter for a title that is inside
     * both. A title over 100 is the case the limit exists FOR, and a fill
     * computed as `length / 100` walks off the element at 101 — the same shape
     * as Gate 3's window bar before its axis was derived.
     *
     * Nothing here relaxes the limit: 100 characters stays hard and the
     * validator still refuses. This only keeps the picture honest, and names
     * the overage, because a clamped bar reports 101 and 200 identically.
     *
     * @return array{used: int, target: int, hard: int, used_percent: float, target_percent: float, over_target: int, over_hard: int, tone: string}
     */
    #[Computed]
    public function titleMeter(): array
    {
        $used = mb_strlen(trim($this->titleSelected));
        $hard = (int) config('youtube.limits.title_hard', 100);
        $target = (int) config('youtube.limits.title_target', 70);

        $percent = static fn (int $n): float => round(max(0.0, min(100.0, $n / max(1, $hard) * 100)), 2);

        return [
            'used' => $used,
            'target' => $target,
            'hard' => $hard,
            'used_percent' => $percent($used),
            'target_percent' => $percent($target),
            'over_target' => max(0, $used - $target),
            'over_hard' => max(0, $used - $hard),
            'tone' => match (true) {
                $used > $hard => 'fail',
                $used > $target => 'warn',
                default => 'ok',
            },
        ];
    }

    /**
     * The tags, each one told whether it is inside the budget.
     *
     * "500-character total budget across all tags — enforce it, do not silently
     * truncate" is the spec's own line, and a TOTAL cannot be acted on: the
     * operator needs to know which entries are past the line, because dropping
     * them here is a decision and dropping them at upload is an accident.
     *
     * The running count matches `YoutubeMetadata::charCountFor()` exactly —
     * every tag plus the separator before it — rather than being a second
     * arithmetic that agrees only on the day it is written.
     *
     * @return array<int, array{text: string, chars: int, running: int, over: bool}>
     */
    #[Computed]
    public function tagRows(): array
    {
        $budget = (int) config('youtube.limits.tags_chars');
        $running = 0;
        $rows = [];

        foreach ($this->parsedTags() as $i => $tag) {
            $running += mb_strlen($tag) + ($i === 0 ? 0 : 1);

            $rows[] = [
                'text' => $tag,
                'chars' => mb_strlen($tag),
                'running' => $running,
                'over' => $running > $budget,
            ];
        }

        return $rows;
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
