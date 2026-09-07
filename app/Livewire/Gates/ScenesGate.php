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
use App\Enums\Gate;
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
use App\Support\GateVoice;
use App\Support\ImagePromptBuilder;
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

    /**
     * Show each row's frame, or the whole stored prompt. 'frame' | 'full'.
     *
     * View state only. It changes what is on the screen and nothing about what
     * the app does — no dispatch, no gate and no estimate reads it.
     */
    public string $promptMode = 'frame';

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

    /**
     * What the dispatch checks said, when they were asked without dispatching.
     *
     * Empty until `checkReadiness()` runs, and never populated by a real
     * dispatch — the money press reports what it QUEUED, and folding a
     * readiness readout into that would make a run look like a check.
     *
     * @var array<int, array{level: string, message: string}>
     */
    public array $readinessNotes = [];

    public function mount(Story $story): void
    {
        $this->story = $story;
    }

    /**
     * How this page is allowed to talk about its own decisions.
     *
     * The advisory heading was the first sentence here to be made a function of
     * state, and it was made one by hand — passed in at two include sites. Gate
     * 1 then shipped two more instances of the same defect in prose no include
     * site could see. GateVoice is that fix generalised: one mechanism, asked by
     * every gate, checked across every gate in GateLayoutContractTest.
     */
    #[Computed]
    public function voice(): GateVoice
    {
        return GateVoice::for(Gate::Scenes, $this->story->status);
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

    /**
     * Run every dispatch check WITHOUT dispatching, and print what they say.
     *
     * -------------------------------------------------------------------
     * A GUARD WITH NO CALLER IS WORSE THAN NO GUARD
     * -------------------------------------------------------------------
     *
     * `narration:preflight` has asked five free questions since it was written
     * — what is actually bound, is the voice real, does the run fit the
     * allowance, does the aligner import, do the workers agree — and **nothing
     * in the app has ever called it.** A terminal command, on the app built so
     * an operator would not need a terminal, guarding the money button.
     *
     * That is the console-audit shape landing in the worst possible place, and
     * story 23 is what it cost: dispatch, then 257 identical per-scene failures
     * for a null `voice_id` the command would have named in one line.
     *
     * Questions 1-3 now run inside `PreflightAssetDispatch`, so pressing
     * Generate cannot skip them. This button is the other half — the same
     * questions asked for FREE, before committing, which is what the command
     * was for. It dispatches nothing and bills nothing; a refusal is caught and
     * printed rather than thrown.
     *
     * The command keeps its place for `--align`, which actually runs WhisperX
     * against a real scene and is the one question a page should not ask on a
     * render.
     */
    public function checkReadiness(): void
    {
        $this->problem = null;
        $this->notice = null;
        $this->resetErrorBag();

        try {
            // The same Action the money press runs, not a second copy of the
            // questions. A check that agreed with the dispatch only on the day
            // it was written is how one narration came to have three prices.
            $notes = app(PreflightAssetDispatch::class)->handle($this->story);
        } catch (DispatchRefusedException $e) {
            $this->addError('generation', $e->getMessage());

            return;
        } catch (Throwable $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->readinessNotes = $notes;

        if ($notes === []) {
            $this->notice = 'Nothing outstanding to check — this story has no unfinished asset stages.';
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
        } catch (DispatchRefusedException $e) {
            /*
             * THE REFUSAL, AS TEXT ON THE PAGE THAT CAUSED IT.
             *
             * This catch was missing. `alignTimings()` and `draftScenes()` both
             * had it and the money press did not, so every refusal
             * `PreflightAssetDispatch` can raise — stale workers, a dead
             * aligner, a reference sheet in the wrong style — reached the
             * operator as a stack trace on the one screen where the message is
             * the entire point. Each of those refusals is several paragraphs
             * naming the fix, and none of it was being read.
             *
             * It went unnoticed because those three refusals all require a
             * broken machine to fire, and the button is pressed on a working
             * one. The narrator and allowance checks are ordinary states — a
             * new story, a nearly-spent month — so the gap would have started
             * firing immediately.
             */
            $this->confirmingGeneration = false;
            $this->notice = null;
            $this->addError('generation', $e->getMessage());

            return;
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
            $this->voice,
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

    /**
     * The part of an image prompt that is identical on every scene.
     *
     * `scenes.image_prompt` stores the ASSEMBLED prompt — the frame, then the
     * verbatim cast block, then the art style and the constraints — because
     * that is what `ImagePromptBuilder::build()` returns and what DraftScenes
     * saves. Printing all of it once per row means the page repeats the same
     * four hundred words 168 times and buries the one section that differs.
     *
     * So it is stated once, here, and the rows carry the frame. Nothing is
     * hidden: the full stored text is one toggle away and the edit form has
     * always shown it whole.
     *
     * ---------------------------------------------------------------------
     * READ FROM THE PROMPTS, NEVER FROM CONFIG
     * ---------------------------------------------------------------------
     *
     * The obvious implementation reads `scenes.art_style` and reports that.
     * It would be wrong on two of this app's three scripted stories, and
     * wrong in the worst direction — asserting on the money screen that all
     * 168 prompts carry the configured style when not one of them does.
     *
     * `GenerateSceneImage` sends `image_prompt` verbatim; nothing re-appends
     * the style at dispatch. So a story drafted before the look was retuned
     * carries the OLD style in all of its stored prompts for ever, and config
     * describes what the next story would get rather than what this one has.
     * Measured: rent-will 0/168, my-younger-brother 0/186, my-wife 270/270.
     *
     * So the shared block is the longest run of trailing sections that is
     * byte-identical across every prompt in the story — which is the literal
     * meaning of "identical on all 168 prompts" — and config is used only to
     * say whether the two agree.
     *
     * ---------------------------------------------------------------------
     * THIS IS NOT THE REFERENCE-SHEET STALENESS CHECK. NEITHER COVERS THE OTHER.
     * ---------------------------------------------------------------------
     *
     * `Character::referenceStyleState()` and `StyleFingerprint` answer a
     * different question about a different artefact, and that item is already
     * closed. It asks: was this character's reference SHEET drawn in the style
     * configured now. This asks: do this story's stored PROMPTS carry the style
     * configured now.
     *
     * The decisive point is that the remedies are disjoint. Regenerating a
     * character sheet does nothing whatever to the stored prompts, and
     * re-drafting the scenes does not touch the sheets. So a story can sit in
     * any combination of the two, and fixing the one this reports can never
     * fix the one that reports.
     *
     * Reachable in a single step from live data: rent-will has no reference
     * sheets at all AND drifted prompts. Generate its sheets and it has CURRENT
     * sheets and drifted prompts — a story whose faces are drawn in one look
     * and whose scenes are prompted in another, with the sheet check green.
     *
     * And the two are not enforced alike. A stale sheet is a REFUSAL at asset
     * dispatch. This is a report on a page and nothing refuses on it, which is
     * the weaker of the two and is deliberate for now — it is named as an open
     * item in CLAUDE.md rather than left to look covered.
     *
     * @return array{words: int, text: string, scenes: int, matches_config: bool, configured: bool}
     */
    #[Computed]
    public function styleBlock(): array
    {
        $none = ['words' => 0, 'text' => '', 'scenes' => 0, 'matches_config' => false, 'configured' => false];

        $prompts = $this->story->scenes()
            ->whereNotNull('image_prompt')
            ->pluck('image_prompt')
            ->map(fn ($prompt): array => array_values(array_filter(
                // Line endings normalised before splitting. A prompt carrying
                // CRLF splits into ONE section on "\n\n", which does not throw
                // and does not look wrong — the shared block silently reports
                // as absent and the panel disappears. That is the failure this
                // file keeps naming, so it is cheaper to normalise than to
                // trust that nothing ever writes a \r.
                array_map('trim', explode("\n\n", str_replace("\r\n", "\n", (string) $prompt))),
                fn (string $section): bool => $section !== '',
            )))
            ->filter(fn (array $sections): bool => $sections !== [])
            ->values();

        if ($prompts->count() < 2) {
            return $none;
        }

        $first = $prompts->first();
        $shortest = (int) $prompts->map(fn (array $s): int => count($s))->min();
        $shared = 0;

        // Stop one short of the whole prompt: the frame is the part that
        // differs, and a "shared block" that swallowed it would mean every
        // scene had the same picture.
        for ($back = 1; $back <= $shortest - 1; $back++) {
            $section = $first[count($first) - $back];

            $identical = $prompts->every(
                fn (array $sections): bool => ($sections[count($sections) - $back] ?? null) === $section,
            );

            if (! $identical) {
                break;
            }

            $shared = $back;
        }

        if ($shared === 0) {
            return $none;
        }

        $text = implode("\n\n", array_slice($first, -$shared));

        $configured = trim(implode("\n\n", array_filter([
            trim((string) config('scenes.art_style')),
            trim((string) config('scenes.constraints')),
        ])));

        return [
            'words' => count(preg_split('/\s+/u', $text) ?: []),
            'text' => $text,
            'scenes' => $prompts->count(),
            'matches_config' => $configured !== '' && $text === $configured,
            'configured' => $configured !== '',
        ];
    }

    /**
     * Whether this scene has assets that were paid for.
     *
     * A marker, not a price. The per-scene state is known exactly — the still
     * is on disk or it is not — while a per-scene DOLLAR figure is not: the
     * estimate prices narration by character across the whole story, and no
     * image vendor returns a cost on a response anyway. So the row says
     * "editing this costs money", which is the question being asked while
     * scrolling after a reopen, and does not invent a number to say it with.
     */
    public function isPaidFor(Scene $scene): bool
    {
        return $scene->image_path !== null || $scene->sceneAudio->isNotEmpty();
    }

    /**
     * The per-scene half of the prompt — the part that actually differs.
     *
     * Read through `ImagePromptBuilder::frameFrom()` rather than by splitting
     * here, because the sections are joined by that class and the convention
     * for taking them apart is its to state. A second copy of that rule in a
     * Livewire component is a second thing to correct when it changes.
     */
    public function frameOf(Scene $scene): string
    {
        return ImagePromptBuilder::frameFrom((string) $scene->image_prompt);
    }

    public function showFrameOnly(): void
    {
        $this->promptMode = 'frame';
    }

    public function showFullPrompt(): void
    {
        $this->promptMode = 'full';
    }

    public function render(): View
    {
        return view('livewire.gates.scenes-gate', [
            // `characters` and `sceneAudio` are eager-loaded because the row now
            // asks both questions of every scene on the page. Twenty rows each
            // firing two queries is the kind of thing that looks fine on the
            // fixture and is felt on a 270-scene story.
            'scenes' => $this->story->scenes()
                ->with(['act', 'characters', 'sceneAudio'])
                ->paginate(self::PER_PAGE),
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
