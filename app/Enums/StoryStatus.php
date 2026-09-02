<?php

namespace App\Enums;

/**
 * The lifecycle of a story, and the only definition of which moves are legal.
 *
 * This is not a UI concern. The four gates are the product's central promise —
 * an operator makes a real editorial decision at each one — and the one below
 * Gate 2 is also a money invariant: no paid asset generation may begin before
 * `scenes_approved`. A rule that lives only in a Livewire component is a rule
 * that a queued job, an Artisan command or a Phase 2 provider can walk straight
 * past, so it lives here and is enforced on the model.
 *
 * Backwards moves are deliberately permitted where a re-run is a real operator
 * action — reopening Gate 2 to edit scenes, re-rendering after watching the
 * preview. They are permitted, not silent: every transition is an explicit
 * call, because re-running a stage costs money.
 */
enum StoryStatus: string
{
    case Draft = 'draft';
    case Outlined = 'outlined';
    case Scripted = 'scripted';
    case ScenesDrafted = 'scenes_drafted';
    case ScenesApproved = 'scenes_approved';
    case AssetsGenerating = 'assets_generating';
    case AssetsReady = 'assets_ready';
    case Rendering = 'rendering';
    case Rendered = 'rendered';
    case MetadataReady = 'metadata_ready';
    case Published = 'published';

    /**
     * Every legal move, keyed by origin.
     *
     * A status absent from a list is unreachable from that origin — there is no
     * "skip to published", which is the whole point.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Outlined],

            // Gate 1. The operator approves the act outline before 7,000 words
            // of script get written against it.
            self::Outlined => [self::Scripted],

            self::Scripted => [self::ScenesDrafted, self::Outlined],

            // Gate 2. The last free moment: everything past here bills.
            self::ScenesDrafted => [self::ScenesApproved, self::Scripted],

            // Reopening Gate 2 is legal and sometimes necessary — the operator
            // spotted a bad scene. It has to be an explicit act, because assets
            // already paid for may be discarded by what follows.
            self::ScenesApproved => [self::AssetsGenerating, self::ScenesDrafted],

            self::AssetsGenerating => [self::AssetsReady, self::ScenesApproved, self::ScenesDrafted],

            self::AssetsReady => [self::Rendering, self::AssetsGenerating, self::ScenesDrafted],

            // A failed render drops back to assets_ready rather than stranding
            // the story in `rendering` forever.
            //
            // No reopen edge, deliberately: a batch of up to 250 clip jobs is
            // in flight, and reopening would let the operator delete scenes the
            // running jobs are mid-encode on. `render:cancel` first — it lands
            // on assets_ready, which does reopen.
            self::Rendering => [self::Rendered, self::AssetsReady],

            // Gate 3. The operator watches the render. Approving moves on to
            // metadata; rejecting sends it back to be rendered again.
            //
            // The reopen edge is what makes "I watched it and scene 147 is
            // wrong" a fixable outcome rather than a dead end. It is one hop
            // rather than a walk back through `rendering` and
            // `assets_generating`, because both of those mean "jobs are in
            // flight" and passing through them would lie to the progress page.
            //
            // `assets_generating` is on this list because replacing an asset on
            // a story that has already rendered is a SPEND, not a gate
            // crossing. The spec makes that a non-negotiable, and the reason is
            // this exact move: routing it through Gate 2 would mean reopening
            // the gate to fix narration, which puts all 150-250 paid stills
            // back in play to correct the audio. It also drops the story below
            // `rendered`, which is correct — a Gate 3 approval given to a
            // silent render says nothing about the narrated one, and the
            // approval IS this transition, so losing it is automatic.
            self::Rendered => [self::MetadataReady, self::Rendering, self::ScenesDrafted, self::AssetsGenerating],

            // Gate 4. Metadata can be regenerated as often as the operator
            // likes — it is text, it is free — and publishing is manual.
            //
            // The same two edges as `rendered`, directly rather than through
            // it. Both are reachable in two hops via `rendered` already, but
            // nothing performed that walk, and a caller doing it implicitly
            // would be a status change as a side effect of another status
            // change — which is exactly what this table exists to prevent.
            self::MetadataReady => [
                self::Published,
                self::Rendered,
                self::ScenesDrafted,
                self::AssetsGenerating,
                self::Rendering,
            ],

            // Terminal. The file is uploaded by a human, outside this app.
            self::Published => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    /**
     * Whether Gate 2 may be reopened from here.
     *
     * One predicate, three callers: the button's visibility, the model guard,
     * and the transition table. They were three different expressions before,
     * which is how the page came to offer a move the machine had never been
     * told about.
     *
     * True from `scenes_approved` onward, because that is where paid assets
     * start existing and therefore where "go back and fix a scene" starts being
     * a decision with a bill attached. False at `rendering` (a clip batch is in
     * flight — cancel it first) and false at `published`, which is terminal.
     */
    public function canReopenScenesGate(): bool
    {
        return match ($this) {
            self::ScenesApproved,
            self::AssetsGenerating,
            self::AssetsReady,
            self::Rendered,
            self::MetadataReady => true,
            default => false,
        };
    }

    /**
     * Why a reopen is refused from here, phrased for the operator.
     *
     * Null when it is allowed. A bare "illegal transition" is true and useless;
     * both refusals have a specific next action behind them.
     */
    public function reopenRefusalReason(): ?string
    {
        return match (true) {
            $this->canReopenScenesGate() => null,

            $this === self::Rendering => 'a render batch is in flight. Cancel it first '
                .'(php artisan render:cancel <story>) — that lands on assets_ready, which can reopen.',

            $this === self::Published => 'the story is published. That is terminal: the file is on '
                .'YouTube and this app does not reach it.',

            $this->rank() < self::ScenesApproved->rank() => 'Gate 2 has not been approved yet, so there '
                .'is nothing to reopen.',

            default => 'Gate 2 cannot be reopened from this status.',
        };
    }

    /**
     * The gate an operator has to pass through to make this exact move, if any.
     *
     * Only the forward crossing is a gate. Going back is a reopen, not an
     * approval, and needs no gate ceremony — the ceremony is on the way in.
     */
    public function gateFor(self $status): ?Gate
    {
        return match (true) {
            $this === self::Outlined && $status === self::Scripted => Gate::Outline,
            $this === self::ScenesDrafted && $status === self::ScenesApproved => Gate::Scenes,
            $this === self::Rendered && $status === self::MetadataReady => Gate::Preview,
            $this === self::MetadataReady && $status === self::Published => Gate::Metadata,
            default => null,
        };
    }

    /**
     * Whether paid asset generation is permitted at this status.
     *
     * Gate 2 is the money line. Images are roughly 70% of the cost of a video
     * and there are 150-250 of them, so "generate first, review later" is not a
     * recoverable mistake.
     */
    public function allowsPaidAssets(): bool
    {
        return $this->rank() >= self::ScenesApproved->rank();
    }

    /**
     * Whether character reference sheets may be generated at this status.
     *
     * One status earlier than paid assets, and that gap is the whole feature.
     * The operator picks a face while standing at Gate 2, before the 199 stills
     * that will be conditioned on it are authorised — which is the only order
     * in which picking it is useful. See CostCategory::Reference for why this
     * sharpens the Gate 2 rule rather than punching through it.
     *
     * `>=` rather than `===` so a story that has already crossed Gate 2 can
     * still re-generate a sheet: a face that turns out wrong at scene 90 is
     * exactly when you most need to be able to fix it, and by then the story is
     * well past `scenes_drafted`.
     */
    public function allowsReferenceSpend(): bool
    {
        return $this->rank() >= self::ScenesDrafted->rank();
    }

    /**
     * Position in the lifecycle. Comparison only — never persisted, so cases
     * can be reordered without a migration.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Draft => 0,
            self::Outlined => 1,
            self::Scripted => 2,
            self::ScenesDrafted => 3,
            self::ScenesApproved => 4,
            self::AssetsGenerating => 5,
            self::AssetsReady => 6,
            self::Rendering => 7,
            self::Rendered => 8,
            self::MetadataReady => 9,
            self::Published => 10,
        };
    }

    /**
     * The gate currently waiting on the operator, if the story is parked at one.
     */
    public function awaitingGate(): ?Gate
    {
        return match ($this) {
            self::Outlined => Gate::Outline,
            self::ScenesDrafted => Gate::Scenes,
            self::Rendered => Gate::Preview,
            self::MetadataReady => Gate::Metadata,
            default => null,
        };
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
