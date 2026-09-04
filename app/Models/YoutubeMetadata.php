<?php

namespace App\Models;

use App\Enums\MetadataStatus;
use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
use App\Enums\StoryStatus;
use Database\Factories\YoutubeMetadataFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The publish sheet. One row per story.
 *
 * Chapters are not columns here. They are derived from `acts`, which carry
 * start_ms and duration_ms once the render has filled them in — see chapters().
 * Storing them again would mean two answers to the same question.
 *
 * @property MetadataStatus $status
 */
class YoutubeMetadata extends Model
{
    /** @use HasFactory<YoutubeMetadataFactory> */
    use HasFactory;

    /** Laravel would guess `youtube_metadatas`. */
    protected $table = 'youtube_metadata';

    /** YouTube's hard limit. Target 60-70 so nothing truncates in search or on mobile. */
    public const TITLE_MAX = 100;

    public const DESCRIPTION_MAX = 5000;

    /** Total across all tags, not per tag. Enforced, never silently truncated. */
    public const TAGS_CHAR_BUDGET = 500;

    /** YouTube ignores a chapter list shorter than this, and so should we. */
    public const MIN_CHAPTERS = 3;

    /** YouTube's minimum chapter length. */
    public const MIN_CHAPTER_MS = 10_000;

    protected $fillable = [
        'story_id',
        'title_options',
        'title_selected',
        'description',
        'tags',
        'thumbnail_text_options',
        // The composed split-panel candidates, and which one was picked. A
        // composition is a PAIR of stills, so it could not live in
        // `thumbnail_scene_id` without that column meaning two things.
        'thumbnail_options',
        'thumbnail_selected',
        'thumbnail_scene_id',
        'pinned_comment',
        'checklist_state',
        'status',
        'stale_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'title_options' => 'array',
            'tags' => 'array',
            'thumbnail_text_options' => 'array',
            'thumbnail_options' => 'array',
            'checklist_state' => 'array',
            'tags_char_count' => 'integer',
            'stale_at' => 'datetime',
            'status' => MetadataStatus::class,
        ];
    }

    protected static function booted(): void
    {
        // Derived, so it cannot drift from the tags it counts. Whether the
        // budget is exceeded is a generation-time decision — this only ever
        // reports the truth about what is stored.
        static::saving(function (YoutubeMetadata $metadata): void {
            $metadata->tags_char_count = self::charCountFor($metadata->tags ?? []);
        });
    }

    /** @return BelongsTo<Story, $this> */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    /**
     * The composed thumbnail the operator picked, or null.
     *
     * Read off the stored options rather than held in a second column: the
     * options carry the path, the score and the reasoning, and a duplicated
     * path is one more thing that can point somewhere the file is not.
     *
     * @return array<string, mixed>|null
     */
    public function selectedThumbnail(): ?array
    {
        $selected = trim((string) $this->thumbnail_selected);

        if ($selected === '') {
            return null;
        }

        foreach ((array) ($this->thumbnail_options ?? []) as $option) {
            if (($option['key'] ?? null) === $selected) {
                return $option;
            }
        }

        return null;
    }

    /** @return BelongsTo<Scene, $this> */
    public function thumbnailScene(): BelongsTo
    {
        return $this->belongsTo(Scene::class, 'thumbnail_scene_id');
    }

    // -- Staleness -----------------------------------------------------------

    /**
     * Mark the sheet as describing a render that no longer exists.
     *
     * Called when Gate 2 is reopened. A Pending sheet is left alone — there is
     * nothing in it to be wrong yet — and an Approved one is marked too, since
     * approval is a record of a decision made against timings that have since
     * been invalidated.
     */
    public function markStale(): void
    {
        if ($this->status === MetadataStatus::Pending) {
            return;
        }

        $this->forceFill([
            'status' => MetadataStatus::Stale,
            'stale_at' => now(),
        ])->save();
    }

    public function isStale(): bool
    {
        return $this->status === MetadataStatus::Stale;
    }

    /**
     * Whether a successful render has finished since this sheet went stale.
     *
     * This is what makes "regenerated post-render" a checkable condition rather
     * than a promise. Pressing save does not clear staleness; a new mux does,
     * because only a new mux produces the act timings the chapters are built
     * from. Compared against the mux stage specifically — it is the last stage,
     * the one that writes final.mp4, and the only one whose completion means
     * the timings are real.
     */
    public function hasFreshRender(): bool
    {
        if ($this->stale_at === null) {
            return true;
        }

        return RenderJob::query()
            ->where('story_id', $this->story_id)
            ->where('stage', RenderStage::Mux)
            ->where('status', RenderJobStatus::Succeeded)
            ->where('finished_at', '>', $this->stale_at)
            ->exists();
    }

    /**
     * Whether the sheet may be lifted out of Stale.
     *
     * False while the story is still upstream of a render, which is the common
     * case immediately after a reopen: the operator is at Gate 2 and the video
     * they would be describing does not exist yet.
     */
    public function canClearStale(): bool
    {
        return $this->isStale()
            && $this->hasFreshRender()
            && $this->story->status->rank() >= StoryStatus::Rendered->rank();
    }

    /**
     * Lift the mark, once a newer render has actually happened.
     *
     * Returns false rather than throwing when it is too early — the caller is
     * a UI action and "not yet" is a normal answer, not an exceptional one.
     */
    public function clearStale(): bool
    {
        if (! $this->canClearStale()) {
            return false;
        }

        $this->forceFill([
            'status' => MetadataStatus::Generated,
            'stale_at' => null,
        ])->save();

        return true;
    }

    /**
     * YouTube counts the tags plus the separators between them.
     *
     * @param  array<int, string>  $tags
     */
    public static function charCountFor(array $tags): int
    {
        if ($tags === []) {
            return 0;
        }

        return array_sum(array_map('mb_strlen', $tags)) + count($tags) - 1;
    }

    public function exceedsTagBudget(): bool
    {
        return $this->tags_char_count > self::TAGS_CHAR_BUDGET;
    }

    /**
     * Chapters, derived from the story's acts.
     *
     * Returns what the description needs and nothing more. It does NOT enforce
     * YouTube's rules — first chapter at 00:00, at least three, each at least
     * ten seconds, ascending — because that enforcement belongs where the
     * description is generated and must fail the stage loudly. This is the
     * single place the derivation itself lives.
     *
     * Empty until the render has filled in act timings.
     *
     * @return array<int, array{timestamp: string, title: string, start_ms: int}>
     */
    public function chapters(): array
    {
        return $this->story->acts
            ->filter(fn (Act $act): bool => $act->start_ms !== null)
            ->map(fn (Act $act): array => [
                'timestamp' => $act->chapterTimestamp(),
                'title' => $act->title,
                'start_ms' => $act->start_ms,
            ])
            ->values()
            ->all();
    }
}
