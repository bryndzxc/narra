<?php

namespace App\Livewire\Gates;

use App\Actions\ApproveScenesGate;
use App\Actions\DispatchAssetGeneration;
use App\Actions\DispatchTextStage;
use App\Actions\EstimateSceneAssets;
use App\Actions\PreflightAssetDispatch;
use App\Actions\ReorderScenes;
use App\Actions\ValidateCharacterSheets;
use App\Actions\ValidateSceneDrafts;
use App\Enums\MotionPreset;
use App\Enums\OperatorAction;
use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
use App\Enums\SceneStatus;
use App\Enums\StoryStatus;
use App\Exceptions\DispatchRefusedException;
use App\Exceptions\GateViolationException;
use App\Exceptions\MissingCharacterReferenceException;
use App\Livewire\Concerns\PaginatesWithProjectTheme;
use App\Models\Character;
use App\Models\RenderJob;
use App\Models\Scene;
use App\Models\Story;
use App\Support\CharacterTextGuard;
use App\Support\SceneAssetEstimate;
use App\Support\SceneChangeSet;
use App\Support\WorkerHealth;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

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
    use PaginatesWithProjectTheme;
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

    /**
     * Separate from $confirmingApproval, and the separation is the point.
     *
     * Approving scenes is a quality decision and generating assets is a money
     * decision, so they are two buttons with two confirmations rather than one
     * click that quietly does both. The other half of the reason is retry: a
     * spend that is not a gate crossing can be pressed again to redo five
     * failed stills, where re-crossing the gate would put the other 181 at risk
     * to fix them.
     */
    public bool $confirmingGeneration = false;

    /**
     * The two presses this page grew, and one shared problem line.
     *
     * `notice` stays what it was — the thing that went right. A refusal is not
     * a notice: the Gate 2 page has already had a panel vanish rather than
     * explain itself, and a refused dispatch reported in the same green box as
     * a successful one would be the same mistake wearing a different colour.
     */
    public bool $confirmingDraft = false;

    public bool $confirmingAlignment = false;

    public ?string $problem = null;

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

    /**
     * Whether the spend button is live.
     *
     * Keyed on the status predicate that already defines the money line rather
     * than on `=== ScenesApproved`, so the button survives its own successes: a
     * story parked at `assets_generating` with five failures, or sitting at
     * `assets_ready` when the operator spots a bad still, can both be pressed
     * again. That is the retry path, and it exists precisely so that fixing
     * five scenes never requires reopening Gate 2 and risking the other 181.
     *
     * `rendering` is excluded, and only that. A clip batch is in flight and
     * regenerating a still out from under it would have the render encode one
     * image while the row names another.
     */
    #[Computed]
    public function canGenerateAssets(): bool
    {
        return OperatorAction::RegenerateAssets->permittedAt($this->story->status);
    }

    /**
     * Why the button is not offered, for the operator standing in front of it.
     *
     * The same sentence `assets:generate` prints. When the page and the command
     * phrased this separately they also DECIDED it separately, and drifted: the
     * button correctly refused past `rendered` while the command accepted any
     * status and failed at the transition.
     */
    #[Computed]
    public function assetGenerationRefusal(): ?string
    {
        return OperatorAction::RegenerateAssets->refusalReason($this->story->status);
    }

    /**
     * What generating the outstanding assets is about to cost, itemised.
     *
     * The page said "186 image(s) and 186 narration(s) will be generated" for a
     * long time without ever naming a figure or a mechanism. This is the
     * number, from the same rate cards the providers price themselves against,
     * over the same outstanding set the dispatcher will actually queue.
     */
    #[Computed]
    public function assetEstimate(): SceneAssetEstimate
    {
        return app(EstimateSceneAssets::class)->handle($this->story);
    }

    /**
     * Scenes whose asset generation failed, with the provider's reason.
     *
     * Three failures out of 186 must not fail the video, but they must not
     * vanish either — so they are listed here, on the page with the button that
     * retries them, rather than only on the render progress page.
     *
     * @return Collection<int, array{sequence: int, stage: string, error: string}>
     */
    #[Computed]
    public function failedScenes(): Collection
    {
        $failed = $this->story->scenes()
            ->where('status', SceneStatus::Failed)
            ->orderBy('sequence')
            ->get(['id', 'sequence']);

        if ($failed->isEmpty()) {
            return collect();
        }

        // The error text lives on render_jobs, which is where every stage
        // records what happened. Latest row per scene per stage; at three
        // stages and a handful of failures this is a small set.
        $errors = RenderJob::query()
            ->where('story_id', $this->story->id)
            ->whereIn('stage', [RenderStage::Images, RenderStage::SceneNarration, RenderStage::SceneTimings])
            ->where('status', RenderJobStatus::Failed)
            ->whereIn('scene_id', $failed->modelKeys())
            ->orderByDesc('finished_at')
            ->get(['scene_id', 'stage', 'error']);

        return $failed->map(function (Scene $scene) use ($errors): array {
            $job = $errors->firstWhere('scene_id', $scene->id);

            return [
                'sequence' => (int) $scene->sequence,
                'stage' => $job?->stage->label() ?? 'incomplete',
                'error' => $job?->error !== null
                    ? Str::limit((string) $job->error, 300)
                    : 'No provider error recorded — the scene is missing at least one of its three assets.',
            ];
        })->values();
    }

    /**
     * Whether every character who appears in a frame has an approved face.
     *
     * Surfaced here as well as enforced in ApproveScenesGate, because a refusal
     * the operator meets only when they press the button is a refusal they were
     * ambushed by. Same Action behind both, so what the page promises and what
     * the gate enforces cannot drift apart.
     *
     * @return array{ready: bool, missing: Collection<int, Character>, scenes_blocked: int, unused: Collection<int, Character>}
     */
    #[Computed]
    public function castState(): array
    {
        return app(ValidateCharacterSheets::class)->handle($this->story);
    }

    #[Computed]
    public function castReady(): bool
    {
        return $this->castState()['ready'];
    }

    /** @return Collection<int, Character> */
    #[Computed]
    public function castMissing(): Collection
    {
        return $this->castState()['missing'];
    }

    /**
     * What is wrong with these scenes, in the ways an operator scrolling 200 of
     * them will not catch.
     *
     * The structural checks live in ValidateSceneDrafts — image prompts that
     * restate their narration, scenes too short for the camera move to
     * complete, one motion preset used everywhere. This method adds the few
     * that are about completeness rather than quality.
     */
    #[Computed]
    public function warnings(): array
    {
        $warnings = app(ValidateSceneDrafts::class)->handle($this->story)['warnings'];

        $missingPrompt = $this->story->scenes()
            ->where(fn ($q) => $q->whereNull('image_prompt')->orWhere('image_prompt', ''))
            ->count();

        if ($missingPrompt > 0) {
            $warnings[] = "{$missingPrompt} scene(s) have no image prompt.";
        }

        // Stored character text, checked here as well as refused at extraction.
        // A story drafted before a guard existed has the defect baked into every
        // prompt its character appears in, and throwing at extraction cannot
        // reach data that is already on disk. This is the last screen before
        // those prompts are bought.
        //
        // Both fields, because for two phases this checked one of them while the
        // other carried the same defects in production.
        $guard = app(CharacterTextGuard::class);

        foreach ($this->story->characters()->withCount('scenes')->get() as $character) {
            foreach ([
                ['style_notes', $character->style_notes, $guard->violations($character->style_notes)],
                ['description', $character->description, $guard->descriptionViolations($character->description)],
            ] as [$field, $value, $violations]) {
                if ($violations === []) {
                    continue;
                }

                $warnings[] = sprintf(
                    '%s has a %s that will apply to all %d of their scenes (%s): "%s". It is pasted '
                    .'unchanged into every prompt they appear in, so a prop in it is a prop in every '
                    .'frame and a gait in it is a stride in every frame. Re-extract the cast and '
                    .'re-draft to clear it — both free.',
                    $character->name,
                    $field,
                    $character->scenes_count,
                    implode('; ', $violations),
                    trim((string) $value),
                );
            }

            // Advisories, which are a different thing from the above and are
            // deliberately not refused anywhere. Headwear is real clothing and
            // a script can legitimately call for it — but it replaces the hair
            // silhouette the whole cast is told apart by, so it is put in front
            // of the operator rather than decided for them. A soft rule that is
            // only written in a prompt is a rule with no reader.
            foreach ([
                ['style_notes', $character->style_notes],
                ['description', $character->description],
            ] as [$field, $value]) {
                foreach ($guard->advisories($value) as $advisory) {
                    $warnings[] = sprintf(
                        '%s has %s in their %s, applied to all %d of their scenes: "%s". Keep it if '
                        .'the script needs it; otherwise a re-extraction will drop it.',
                        $character->name,
                        $advisory,
                        $field,
                        $character->scenes_count,
                        trim((string) $value),
                    );
                }
            }
        }

        // The reference sheets, and whether they were drawn in the style this
        // story would now be generated in. See Character::referenceStyleState().
        foreach ($this->story->characters()->get() as $character) {
            $state = $character->referenceStyleState();

            if ($state === Character::STYLE_CURRENT || $state === Character::STYLE_NONE) {
                continue;
            }

            $warnings[] = $state === Character::STYLE_STALE
                ? sprintf(
                    '%s\'s reference sheet was generated under a different art style than the one '
                    .'configured now. Every still they appear in is conditioned on that face, so the '
                    .'story would come back in two looks. Regenerate their sheet on the characters '
                    .'page before generating assets.',
                    $character->name,
                )
                : sprintf(
                    '%s\'s reference sheet predates style tracking, so the app cannot say which look '
                    .'it was drawn in. Unknown is not the same as fine — check it on the characters '
                    .'page, and regenerate if it does not match the current style.',
                    $character->name,
                );
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

        try {
            $changes = app(ApproveScenesGate::class)->handle($this->story);
        } catch (MissingCharacterReferenceException $e) {
            // Shown, not thrown at the operator. The gate is refusing for a
            // reason they can act on from the page they are already on, and a
            // stack trace is not that.
            $this->confirmingApproval = false;
            $this->notice = null;
            $this->addError('approval', $e->getMessage());

            return;
        }

        $this->story->refresh();
        $this->resetComputed();

        $this->confirmingApproval = false;
        // Authorised, not started, and the wording has to say so. This line
        // used to read "186 image(s) and 186 narration(s) will be generated" —
        // future tense with no subject, next to a button that dispatched
        // nothing. Approving unlocks the spend; a second, separate press makes
        // it, with the bill on screen first.
        $this->notice = $changes->billsAnything()
            ? sprintf(
                'Gate 2 approved. %d image(s), %d narration(s) and %d transcription(s) are now authorised '
                .'— and none of them have been generated. Nothing bills until you press "Generate assets" '
                .'below, which itemises the cost first. %d scene(s) keep what they already had.',
                $changes->needsImage->count(),
                $changes->needsNarration->count(),
                $changes->needsTranscription->count(),
                $changes->preserved()
            )
            : 'Gate 2 approved. Nothing changed, so nothing is regenerated and nothing is billed.';
    }

    // -- Drafting the scenes (free of gates, not free of money) --------------

    /**
     * The queue the scene draft runs on.
     *
     * @return array{queue: string, role: string, state: string, live: int, stale: int, oldest_boot: ?string, headline: string}
     */
    #[Computed]
    public function textWorkers(): array
    {
        return WorkerHealth::forQueue((string) config('render.queues.text'));
    }

    #[Computed]
    public function canDraftScenes(): bool
    {
        return OperatorAction::DraftSceneList->permittedAt($this->story->status);
    }

    /** Rendered on the page, never swallowed. */
    #[Computed]
    public function draftRefusal(): ?string
    {
        return OperatorAction::DraftSceneList->refusalReason($this->story->status);
    }

    public function askToDraft(): void
    {
        $this->problem = null;
        $this->confirmingDraft = true;
    }

    public function cancelDraft(): void
    {
        $this->confirmingDraft = false;
    }

    /**
     * Queue the cast extraction and the scene draft.
     *
     * `story:scenes` held the only copy of this, so a story that had passed
     * Gate 1 in the browser could only be cut into scenes from a terminal — and
     * the Gate 2 page it fills showed an empty list until somebody did.
     *
     * A re-draft is a rebuild and says so in the confirmation. By the time
     * anyone presses this twice the scenes carry operator edits, and that is a
     * thing to be told rather than to discover.
     */
    public function draftScenes(bool $rebuild = false): void
    {
        abort_unless(
            $this->canDraftScenes(),
            403,
            (string) OperatorAction::DraftSceneList->refusal($this->story->status),
        );

        $this->confirmingDraft = false;
        $this->problem = null;

        try {
            $result = app(DispatchTextStage::class)->draftScenes($this->story, rebuild: $rebuild);
        } catch (DispatchRefusedException|GateViolationException $e) {
            $this->problem = $e->getMessage();

            return;
        } catch (Throwable $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->resetComputed();

        $warnings = array_column(
            array_filter($result['notes'], fn (array $n): bool => $n['level'] !== 'ok'),
            'message',
        );

        $this->notice = sprintf(
            'Queued on the "%s" queue: the cast first, then the acts are cut into scenes. Cast first '
            .'because a character description is pasted verbatim into every image prompt, and a scene '
            .'drafted before the cast exists has to invent one. This page is safe to leave.',
            $result['queue'],
        );

        if ($warnings !== []) {
            $this->problem = implode(' ', $warnings);
        }
    }

    // -- Word timings, and only word timings ---------------------------------

    /**
     * Scenes with audio and no usable timings.
     *
     * Computed the way DispatchAssetGeneration::dispatchTimings() computes it,
     * so the number on the button is the number dispatched.
     */
    #[Computed]
    public function pendingTimings(): int
    {
        return $this->changes()->needsTranscription
            ->filter(fn (Scene $scene): bool => $scene->sceneAudio->contains(
                fn ($audio): bool => $audio->audio_path !== null
            ))
            ->count();
    }

    #[Computed]
    public function canAlignTimings(): bool
    {
        return OperatorAction::AlignTimings->permittedAt($this->story->status);
    }

    #[Computed]
    public function alignRefusal(): ?string
    {
        return OperatorAction::AlignTimings->refusalReason($this->story->status);
    }

    /**
     * What "Generate assets" would ALSO do, stated before the operator picks.
     *
     * Not a footnote. `needsTranscription` and `needsNarration` overlap heavily
     * — narration provenance moving stales both — so on the story this was
     * written for, retrying 181 failed alignments through the asset button
     * would have re-billed 69 narrations. The two buttons look alike and differ
     * by a month of TTS credits.
     */
    #[Computed]
    public function narrationsTheAssetButtonWouldRebill(): int
    {
        return $this->changes()->needsNarration->count();
    }

    public function askToAlign(): void
    {
        $this->problem = null;
        $this->confirmingAlignment = true;
    }

    public function cancelAlignment(): void
    {
        $this->confirmingAlignment = false;
    }

    /**
     * Re-run alignment. Free, and structurally incapable of billing.
     *
     * This does NOT go through DispatchAssetGeneration::handle(). It calls the
     * timings-only dispatcher, which can construct exactly one job class — so
     * "this will not bill TTS" is a property of what the code can reach rather
     * than an assertion that it did not, which is the distinction this project
     * keeps paying to learn.
     */
    public function alignTimings(): void
    {
        abort_unless(
            $this->canAlignTimings(),
            403,
            (string) OperatorAction::AlignTimings->refusal($this->story->status),
        );

        $this->confirmingAlignment = false;
        $this->problem = null;

        $pending = $this->pendingTimings();

        try {
            // The same preflight the paid path runs. The free stage is exactly
            // the one that failed 181 times in a row, one job at a time, with
            // nothing having asked first whether the interpreter could import
            // the module.
            $notes = app(PreflightAssetDispatch::class)->handle($this->story);
            $batchId = DispatchAssetGeneration::dispatchTimings($this->story->id);
        } catch (DispatchRefusedException $e) {
            $this->problem = $e->getMessage();

            return;
        } catch (Throwable $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->resetComputed();

        $this->notice = $batchId === null
            ? 'Nothing to align — every scene with audio already has usable word timings. Nothing was '
                .'queued and nothing was billed.'
            : sprintf(
                '%d alignment job(s) queued on the "%s" queue. Local and free: no TTS call is reachable '
                .'from this button.',
                $pending,
                config('render.queues.assets'),
            );

        $warnings = array_column(
            array_filter($notes, fn (array $n): bool => $n['level'] !== 'ok'),
            'message',
        );

        if ($warnings !== []) {
            $this->problem = implode(' ', $warnings);
        }
    }

    public function askToGenerate(): void
    {
        $this->confirmingGeneration = true;
    }

    public function cancelGeneration(): void
    {
        $this->confirmingGeneration = false;
    }

    /**
     * Queue the paid asset stages. The money press.
     *
     * Deliberately not a gate crossing: nothing here touches gate state, so it
     * can be pressed as many times as it takes. A run that leaves five scenes
     * broken parks the story at `assets_generating`, lists them below, and this
     * same button re-dispatches those five and nothing else.
     */
    public function generateAssets(): void
    {
        abort_unless($this->canGenerateAssets(), 403, 'This story is not in a state where paid assets may be generated.');

        try {
            $result = app(DispatchAssetGeneration::class)->handle($this->story);
        } catch (MissingCharacterReferenceException $e) {
            // The reference rule, reaching the operator as text on the page
            // that fixes it rather than as a stack trace. Gate 2 refuses to
            // open while a character in a frame has no face, so arriving here
            // means one was deleted or the provider was swapped since.
            $this->confirmingGeneration = false;
            $this->notice = null;
            $this->addError('generation', $e->getMessage());

            return;
        }

        $this->story->refresh();
        $this->resetComputed();

        $this->confirmingGeneration = false;
        $this->notice = $result['dispatched'] === 0
            ? 'Nothing outstanding — every scene already has its still, narration and word timings. '
                .'Nothing was queued and nothing was billed.'
            : sprintf(
                '%d job(s) queued on the assets queue: %d still(s), %d narration(s), %d transcription(s). '
                .'Word timings run as a second batch once the narration finishes. Watch it on the render '
                .'progress page; this page is safe to leave.',
                $result['dispatched'],
                $result['images'],
                $result['narrations'],
                $result['transcriptions'],
            );
    }

    /**
     * Every #[Computed] this page caches, dropped in one place.
     *
     * They were unset by hand at three call sites and the lists had already
     * drifted apart — reopen forgot two of them. A stale computed on this page
     * is a cost figure that does not match what the button will spend.
     */
    private function resetComputed(): void
    {
        unset(
            $this->changes,
            $this->costPreview,
            $this->assetEstimate,
            $this->failedScenes,
            $this->textWorkers,
            $this->canDraftScenes,
            $this->draftRefusal,
            $this->pendingTimings,
            $this->canAlignTimings,
            $this->alignRefusal,
            $this->narrationsTheAssetButtonWouldRebill,
            $this->canGenerateAssets,
            $this->canReopen,
            $this->editable,
            $this->canApprove,
            $this->castState,
            $this->castReady,
            $this->castMissing,
            $this->warnings,
        );
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
        $this->resetComputed();

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
