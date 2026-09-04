<?php

namespace App\Support;

use App\Enums\OperatorAction;
use App\Enums\RenderJobStatus;
use App\Enums\StoryStatus;
use App\Models\Act;
use App\Models\RenderJob;
use App\Models\Story;

/**
 * What a story is waiting on, and what the one next thing to do about it is.
 *
 * The stories index used to answer half of this — a "Waiting on" column showing
 * the gate, or the words "the pipeline" when the story was between gates. That
 * is the status restated, not an instruction: "the pipeline" is true at
 * `assets_generating` whether 186 jobs are running normally or 181 of them
 * failed four hours ago, and those are the same three words.
 *
 * So this answers three separate questions and never merges them:
 *
 *   - **Who is being waited on** — the operator, a queue, or nobody.
 *   - **What the next action is**, as an OperatorAction where there is one, so
 *     the index and the button on the gate page are reading one predicate. A
 *     gate approval is deliberately NOT an OperatorAction: crossing a gate is
 *     an editorial judgement that has to be made on the page where the thing
 *     being judged is visible, and an index that offered a one-click "approve"
 *     would be a generate-and-upload button assembled out of four smaller ones.
 *   - **Which queue it depends on**, so the row can say the queue is dead
 *     rather than leaving "generating" on screen for four hours.
 *
 * The last one is the point. A story parked at `assets_generating` with no
 * worker on `assets` is indistinguishable, from the outside, from one that is
 * working — and that is the reporting failure this project keeps finding rather
 * than a new one.
 */
final class NextAction
{
    /** Who the story is waiting on. */
    public const OPERATOR = 'operator';

    public const QUEUE = 'queue';

    public const NOBODY = 'nobody';

    private function __construct(
        public readonly Story $story,
        public readonly string $waitingOn,
        public readonly string $summary,
        public readonly ?OperatorAction $action,
        public readonly ?string $queue,
        public readonly string $routeName,
        public readonly string $routeLabel,
        /** @var array<int, string> */
        public readonly array $warnings = [],
    ) {}

    public static function for(Story $story): self
    {
        $warnings = self::warnings($story);
        $text = (string) config('render.queues.text');

        return match ($story->status) {
            StoryStatus::Draft => self::queued(
                $story,
                'The premise is written and nothing else is. The outline and the act scripts come next.',
                OperatorAction::WriteScript,
                $text,
                'stories.outline',
                'Gate 1',
                $warnings,
            ),

            StoryStatus::Outlined => self::unwrittenActs($story) > 0
                ? self::queued(
                    $story,
                    sprintf(
                        '%d of %d act(s) still have no script. The outline is kept; only the empty acts '
                        .'are written.',
                        self::unwrittenActs($story),
                        $story->acts()->count(),
                    ),
                    OperatorAction::WriteScript,
                    $text,
                    'stories.outline',
                    'Gate 1',
                    $warnings,
                )
                : self::operator(
                    $story,
                    'Gate 1. Read the outline and the act scripts, then approve — nothing is cut into '
                    .'scenes until you do.',
                    'stories.outline',
                    'Gate 1',
                    $warnings,
                ),

            StoryStatus::Scripted => self::queued(
                $story,
                'The script is approved and there are no scenes yet. The cast is extracted first, then '
                .'the acts are cut into scenes.',
                OperatorAction::DraftSceneList,
                $text,
                'stories.scenes',
                'Gate 2',
                $warnings,
            ),

            StoryStatus::ScenesDrafted => self::operator(
                $story,
                'Gate 2, the money line. Every narration and image prompt is editable and nothing has '
                .'been bought. Approving authorises the spend; it does not make it.',
                'stories.scenes',
                'Gate 2',
                $warnings,
            ),

            StoryStatus::ScenesApproved => self::operator(
                $story,
                'Gate 2 is approved and nothing has been generated. The spend is a second, separate '
                .'press, and it itemises the cost first.',
                'stories.scenes',
                'Gate 2',
                $warnings,
                OperatorAction::RegenerateAssets,
                (string) config('render.queues.assets'),
            ),

            StoryStatus::AssetsGenerating => self::queued(
                $story,
                self::assetProgress($story),
                OperatorAction::RegenerateAssets,
                (string) config('render.queues.assets'),
                'renders.show',
                'progress',
                $warnings,
            ),

            StoryStatus::AssetsReady => self::operator(
                $story,
                'Every scene has its still, its narration and its word timings. The render is CPU and '
                .'not money, and it is tens of minutes.',
                'stories.preview',
                'Gate 3',
                $warnings,
                OperatorAction::DispatchRender,
                (string) config('render.queues.render'),
            ),

            StoryStatus::Rendering => self::queued(
                $story,
                self::renderProgress($story),
                OperatorAction::CancelRender,
                (string) config('render.queues.render'),
                'renders.show',
                'progress',
                $warnings,
            ),

            StoryStatus::Rendered => self::operator(
                $story,
                'Gate 3. Watch it. Approving moves on to the publish sheet; rejecting sends it back to '
                .'be rendered again.',
                'stories.preview',
                'Gate 3',
                $warnings,
            ),

            StoryStatus::MetadataReady => self::metadataStep($story, $warnings),

            StoryStatus::Published => new self(
                $story,
                self::NOBODY,
                'Published. That is terminal — the file is on YouTube and this app does not reach it.',
                null,
                null,
                'stories.metadata',
                'Gate 4',
                $warnings,
            ),
        };
    }

    /**
     * Whether the queue this story is waiting on can actually do the work.
     *
     * Null when the story is not waiting on a queue. Read straight from
     * WorkerHealth, which reads straight from the registry the dispatch-time
     * refusal reads — one source, three readers, no second opinion.
     *
     * @return array{queue: string, role: string, state: string, live: int, stale: int, oldest_boot: ?string, headline: string}|null
     */
    public function workers(): ?array
    {
        return $this->queue === null ? null : WorkerHealth::forQueue($this->queue);
    }

    /**
     * The label for the one thing to press, or null when the next move is a
     * gate crossing and belongs on the gate's own page.
     */
    public function actionLabel(): ?string
    {
        return $this->action?->label();
    }

    /** Why the action is unavailable from this status, if it is. */
    public function refusal(): ?string
    {
        return $this->action?->refusal($this->story->status);
    }

    // -- construction helpers ------------------------------------------------

    /** @param  array<int, string>  $warnings */
    private static function operator(
        Story $story,
        string $summary,
        string $routeName,
        string $routeLabel,
        array $warnings,
        ?OperatorAction $action = null,
        ?string $queue = null,
    ): self {
        return new self($story, self::OPERATOR, $summary, $action, $queue, $routeName, $routeLabel, $warnings);
    }

    /** @param  array<int, string>  $warnings */
    private static function queued(
        Story $story,
        string $summary,
        ?OperatorAction $action,
        ?string $queue,
        string $routeName,
        string $routeLabel,
        array $warnings,
    ): self {
        return new self($story, self::QUEUE, $summary, $action, $queue, $routeName, $routeLabel, $warnings);
    }

    /** @param  array<int, string>  $warnings */
    private static function metadataStep(Story $story, array $warnings): self
    {
        $sheet = $story->youtubeMetadata()->first();

        if ($sheet === null || $sheet->title_options === null || $sheet->title_options === []) {
            return self::queued(
                $story,
                'Gate 3 is approved and the publish sheet has not been written. Chapters come from the '
                .'act timings the mux wrote, so it can be written now.',
                OperatorAction::WriteMetadata,
                (string) config('render.queues.text'),
                'stories.metadata',
                'Gate 4',
                $warnings,
            );
        }

        return self::operator(
            $story,
            'Gate 4. Five titles are written and none is selected — picking one is the gate. Then the '
            .'checklist, and a human does the upload.',
            'stories.metadata',
            'Gate 4',
            $warnings,
        );
    }

    // -- the facts the summaries are built from ------------------------------

    private static function unwrittenActs(Story $story): int
    {
        return $story->acts()
            ->get()
            ->filter(fn (Act $act): bool => trim((string) $act->script) === '')
            ->count();
    }

    private static function assetProgress(Story $story): string
    {
        $changes = SceneChangeSet::for($story);

        $outstanding = $changes->needsImage->count()
            + $changes->needsNarration->count()
            + $changes->needsTranscription->count();

        return $outstanding === 0
            ? 'Every scene has its assets. The story reconciles to assets_ready when the batch closes.'
            : sprintf(
                '%d asset(s) outstanding across %d scene(s): %d still(s), %d narration(s), %d timing(s).',
                $outstanding,
                $changes->sceneCount,
                $changes->needsImage->count(),
                $changes->needsNarration->count(),
                $changes->needsTranscription->count(),
            );
    }

    private static function renderProgress(Story $story): string
    {
        $clips = RenderJob::query()
            ->where('story_id', $story->id)
            ->whereNotNull('scene_id')
            ->where('status', RenderJobStatus::Succeeded)
            ->count();

        return sprintf(
            '%d of %d scene clip(s) encoded. The concat, subtitle and mux stages are chained off the '
            .'batch completion callback.',
            $clips,
            $story->scenes()->count(),
        );
    }

    /**
     * Things that are true and bad, regardless of where the story is parked.
     *
     * A failed job and a stale heartbeat are the two ways a story sits at a
     * status that reads like progress while nothing is happening. Both were
     * only visible on `/renders/{slug}` before, which is a page an operator
     * opens after already suspecting something.
     *
     * @return array<int, string>
     */
    private static function warnings(Story $story): array
    {
        $warnings = [];

        $jobs = RenderJob::query()->where('story_id', $story->id)->get();

        $failed = $jobs->where('status', RenderJobStatus::Failed)->count();

        if ($failed > 0) {
            $warnings[] = sprintf('%d job(s) failed — see the render progress page.', $failed);
        }

        $stale = $jobs->filter(fn (RenderJob $job): bool => $job->isStale())->count();

        if ($stale > 0) {
            $warnings[] = sprintf(
                '%d job(s) have not reported in. A worker was killed mid-run, or FFmpeg is hung.',
                $stale,
            );
        }

        return $warnings;
    }
}
