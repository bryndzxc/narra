<?php

namespace App\Livewire\Gates;

use App\Actions\DispatchTextStage;
use App\Actions\GenerateActScripts;
use App\Actions\GeneratePremises;
use App\Actions\GenerateOutline;
use App\Actions\ValidateOutlineSpine;
use App\Enums\ActTimeframe;
use App\Enums\CastRole;
use App\Enums\Gate;
use App\Enums\OperatorAction;
use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
use App\Enums\StoryEnding;
use App\Enums\StoryFormat;
use App\Enums\StoryStatus;
use App\Exceptions\DispatchRefusedException;
use App\Exceptions\GateViolationException;
use App\Models\Act;
use App\Models\Chapter;
use App\Models\RenderJob;
use App\Models\Story;
use App\Support\GateVoice;
use App\Support\LocaleGuard;
use App\Support\ModelRoster;
use App\Support\NarrationPace;
use App\Support\OutlineCast;
use App\Support\PremiseIdea;
use App\Support\Providers\PremiseCandidate;
use App\Support\RecentEndings;
use App\Support\RefusedFields;
use App\Support\ScriptSizing;
use App\Support\WorkerHealth;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

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

    /**
     * The intended age range of the cast, editable here for the same reason
     * the spine is: this is the last point at which it is free.
     *
     * It is read once, by the character extraction that runs when the scene
     * draft is dispatched from Gate 2 — after this gate is crossed. So the
     * window to state it is Gate 1, and getting it wrong is recoverable only
     * by reopening this gate and re-extracting, which rewrites every
     * description the scene prompts were built from.
     */
    public string $castAgeProfile = '';

    /**
     * The genre spine, editable here.
     *
     * Gate 1 is the only place these can be fixed cheaply. Every act-generation
     * call reads them off the story, so a vague grievance or a cartoon
     * antagonist here produces 5,500-8,000 words that inherit the problem, and
     * the cost of finding out is the whole pipeline.
     *
     * The last three are the reversal half, added after story 21 was watched
     * back: it escalated to the last act and gave the narrator one scene of
     * power. They are edited here for the same reason as the first four, and
     * one more — the departure act and every search act are written against
     * them, so a departure that gets announced here is announced in the script.
     *
     * `hook` is first and is the same argument at the sharpest point on the
     * curve: it is handed to the act 1 call verbatim, and act 1's first thirty
     * seconds decide whether anybody sees the other thirty-nine minutes. It is
     * also the one spine field whose fix is free at every stage — nothing
     * downstream of Gate 1 is generated from it except act 1.
     *
     * @var array<string, string>
     */
    public array $spine = [
        'hook' => '',
        'narrator_grievance' => '',
        'antagonist_justification' => '',
        // The accomplice's stake and act, and his fall below; the narrator's
        // running thought before the refusal that pays it off. CLAUDE.md 3g.
        'accomplice_motive' => '',
        'accomplice_performance' => '',
        'betrayal_scene' => '',
        'withheld_information' => '',
        'exposure_moment' => '',
        'narrator_at_exposure' => '',
        'departure' => '',
        'reversal_beats' => '',
        'accomplice_fall' => '',
        'running_thought' => '',
        'refusal' => '',
        // Her last chance and a year on, told in her own closing chapter.
        'antagonist_regret' => '',
    ];

    /**
     * The people this story names, editable here because this is where the
     * cast is decided — before any act is bought, now that the first write
     * press stops after the outline.
     *
     * Renaming a row after the acts are written does not rename anyone in the
     * prose; the review says so, by reporting a cast member named in no act.
     *
     * @var array<int, array{name: string, role: string, relationship: string}>
     */
    public array $cast = [];

    /** @var array<int, array{id: int, sequence: int, phase: ?string, phase_label: string, beat_label: string, timeframe: ?string, title: string, summary: string, escalation_beat: string, is_rehook_written: bool, chapters: array<int, array{sequence: int, title: string, has_rehook: bool, first_sentence: int}>}> */
    public array $acts = [];

    public ?string $saved = null;

    public ?string $problem = null;

    /** Whether the operator has seen the bill for the writing and pressed once. */
    public bool $confirmingWrite = false;

    /**
     * The idea premises are written from. Seeded from the last roll's idea,
     * else from the premise field, which is where the new-story form puts an
     * idea. Its own field, so picking a candidate (which replaces the premise)
     * does not also replace the idea the next roll is written from.
     */
    public string $idea = '';

    public bool $confirmingPremises = false;

    /** When this component queued a roll, so the page can say it is waiting for one. */
    public ?string $premisesQueuedAt = null;

    /**
     * The ending, a StoryEnding value. Editable until the outline is written,
     * saved the moment it is picked — it spends nothing — and read-only after,
     * because the outline wrote the fields the chosen ending needs.
     */
    public string $ending = '';

    public function mount(Story $story): void
    {
        $this->story = $story;
        $this->ending = (string) $story->ending?->value;
        $this->premise = (string) $story->premise;
        $this->idea = (string) ($story->premise_candidates['idea'] ?? $story->premise);
        $this->castAgeProfile = (string) $story->cast_age_profile;
        $this->cast = OutlineCast::rows(OutlineCast::members($story->outline_cast));

        foreach (array_keys($this->spine) as $field) {
            $this->spine[$field] = (string) $story->{$field};
        }

        $this->loadActs();
    }

    /**
     * How this page is allowed to talk about its own decisions.
     *
     * Every sentence on a gate page that names an action goes through here, so
     * that "cheaper to fix here" and "judging these is yours" cannot survive
     * onto a story whose outline is read-only. See GateVoice for the three
     * sentences that shipped side by side with a strip contradicting them.
     */
    #[Computed]
    public function voice(): GateVoice
    {
        return GateVoice::for(Gate::Outline, $this->story->status);
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

    /**
     * Every structural finding about this outline, in one list.
     *
     * THE RE-HOOK ADVISORY USED TO BE ITS OWN ALERT, AND IT WAS THE ONLY ONE
     * ON THE PAGE THAT HAD NEVER BEEN RENDERED BY A TEST. It sat below the
     * spine panel — three screens down on a seven-act story — as a bare
     * `.alert.warn` with no `wide`, so it drew at the 96ch cap beside
     * full-width neighbours. `alertsWithoutTheirOwnWidth` would have reported
     * it on sight and never got the chance: the Gate 1 case built one act at
     * sequence 1 and this check exempts act 1, and the travelling fixture wrote
     * a re-hook on both of its acts.
     *
     * The design has it as a bullet inside the structural warnings, at the top
     * of the page, and that is also what fixes the width by construction — a
     * finding in a list cannot have a width of its own to get wrong. Same
     * reasoning as `.alert.wide` and `.measure`: prefer an arrangement where
     * the bad outcome is unreachable over a check that it did not happen.
     *
     * It NAMES THE ACTS, which the standalone alert did not. "2 acts have no
     * re-hook" is a count the operator then has to go and find; "acts 4 and 6"
     * is the same warning at the same volume, already actionable — the
     * refusal-answers-act-3 argument, one advisory down.
     *
     * `actsMissingRehooks()` stays the producer and is untouched. This composes
     * what is already computed; it decides nothing.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function structuralWarnings(): array
    {
        $warnings = $this->spineReview()['warnings'];

        if ($missing = $this->actsMissingRehooks()) {
            $sequences = array_map(
                static fn (array $act): int => (int) $act['sequence'],
                $missing,
            );

            $one = count($sequences) === 1;

            $warnings[] = sprintf(
                '%d %s no re-hook written — %s %s. A 15-second opening hook is not enough over 35 '
                .'minutes. Every act after the first has to open with a line that carries the viewer '
                .'forward, or the retention graph falls off at the act boundary — which is exactly '
                .'where a chapter marker invites them to leave.',
                count($sequences),
                $one ? 'act has' : 'acts have',
                $one ? 'act' : 'acts',
                $this->inWords($sequences),
            );
        }

        return $warnings;
    }

    /**
     * "4 and 6", "4, 6 and 7" — a list a person reads rather than parses.
     *
     * @param  array<int, int>  $sequences
     */
    private function inWords(array $sequences): string
    {
        if (count($sequences) === 1) {
            return (string) $sequences[0];
        }

        $last = array_pop($sequences);

        return implode(', ', $sequences).' and '.$last;
    }

    /**
     * The setting this story is being generated for, by its label.
     *
     * Read-only, because it is chosen once at creation and everything on
     * this page was already written against it. Shown because there is now
     * more than one, and an outline that reads slightly wrong is a different
     * problem depending on which world it was asked for.
     */
    #[Computed]
    public function localeLabel(): string
    {
        $profile = (string) $this->story->locale_profile;

        return app(LocaleGuard::class)->profiles()[$profile] ?? $profile;
    }

    /**
     * Locale terms in the act scripts that are wrong for the setting but not
     * wrong enough to have failed the stage.
     *
     * This existed for two phases and only `story:write` ever printed it, so
     * the one place the warnings could be acted on was a terminal — on an app
     * built so an operator would not need one. It matters more now: a second
     * setting means a second warn list, and the en-CN one is mostly imperial
     * units, which is exactly the leak a model trained on American prose
     * produces without noticing.
     *
     * Warnings, never a block. The deny list already refused everything that
     * has no reading; these all have one, and judging them is the operator's.
     *
     * @return array<int, array{act: int, term: string, context: string}>
     */
    #[Computed]
    public function localeWarnings(): array
    {
        return app(GenerateActScripts::class)->localeWarnings($this->story);
    }

    /**
     * Denied locale terms the outline or an act was KEPT with, for judgement.
     *
     * Before 2026-09-17 these refused the stage, after the call was billed: a
     * story 36 act lost to "car park" cost the act and the run. They are kept
     * now and shown here instead, louder than the warned terms beside them,
     * because a denied term is one the list thinks has no reading — the
     * operator decides whether this one does.
     *
     * @return array<int, array{where: string, act: ?int, editable: bool, term: string, context: string}>
     */
    #[Computed]
    public function localeDenied(): array
    {
        return app(GenerateActScripts::class)->localeDenied($this->story);
    }

    /**
     * The denied terms in an act SCRIPT, which are not a judgement.
     *
     * Kept at Gate 1 since 2026-09-17, and refused in scene frames since
     * before that, decided the same day and never read together: the scene
     * writer draws a frame from the script, so a script term "kept" here is
     * refused at scene drafting after the scene calls are billed. Story 38's
     * act 4 carried "car park" in one sentence and was refused twice, about
     * $0.73. An outline field is edited on this page; a script is not, which
     * is what `editable` already says.
     *
     * @return array<int, array{where: string, act: ?int, editable: bool, term: string, context: string}>
     */
    #[Computed]
    public function localeDeniedInScripts(): array
    {
        return array_values(array_filter($this->localeDenied(), fn (array $hit): bool => ! $hit['editable']));
    }

    /**
     * The genre check.
     *
     * An aggrieved-narrator melodrama fails in ways that look fine in the
     * database — every act has a title and a word count and the video is still
     * unwatchable. Those failures are structural, so they are surfaced here
     * rather than discovered at Gate 3. Nothing blocks: an outline the operator
     * judges to work despite a warning is theirs to approve.
     */
    #[Computed]
    public function spineReview(): array
    {
        return app(ValidateOutlineSpine::class)->handle($this->story);
    }

    /**
     * The rules save() validates against. Public and static so a test can walk
     * every key and prove each one's refusal is visible on the page.
     *
     * THE ACT BOUNDS COME FROM THE MODEL, NOT FROM A LITERAL HERE. The summary
     * rule was a literal 2,000 while the act-script stage — which REPLACES the
     * outline's summary with the writer's own — was returning up to 2,026, so
     * this form refused text the operator never typed, produced by a stage
     * this rule had never been sized against. See Act::SUMMARY_MAX_CHARS for
     * the measurement and the number, and Act::textOverflows() for the guard
     * that now stops the writer returning what this rule would reject.
     *
     * @return array<string, array<int, string>>
     */
    public static function saveRules(): array
    {
        return [
            'premise' => ['required', 'string', 'min:20'],
            'castAgeProfile' => ['nullable', 'string', 'max:500'],
            // A name is how a character is found in every prompt, so it is
            // required and unique in the cast. 60 is two full names' worth.
            'cast.*.name' => ['required', 'string', 'max:60', 'distinct:ignore_case'],
            'cast.*.role' => ['required', 'in:'.implode(',', array_column(CastRole::cases(), 'value'))],
            'cast.*.relationship' => ['nullable', 'string', 'max:300'],
            'spine.hook' => ['nullable', 'string', 'max:2000'],
            'spine.narrator_grievance' => ['nullable', 'string', 'max:2000'],
            'spine.antagonist_justification' => ['nullable', 'string', 'max:2000'],
            'spine.accomplice_motive' => ['nullable', 'string', 'max:2000'],
            'spine.accomplice_performance' => ['nullable', 'string', 'max:2000'],
            'spine.betrayal_scene' => ['nullable', 'string', 'max:2000'],
            'spine.withheld_information' => ['nullable', 'string', 'max:2000'],
            'spine.exposure_moment' => ['nullable', 'string', 'max:2000'],
            'spine.narrator_at_exposure' => ['nullable', 'string', 'max:2000'],
            'spine.departure' => ['nullable', 'string', 'max:2000'],
            'spine.reversal_beats' => ['nullable', 'string', 'max:2000'],
            'spine.accomplice_fall' => ['nullable', 'string', 'max:2000'],
            'spine.running_thought' => ['nullable', 'string', 'max:2000'],
            'spine.refusal' => ['nullable', 'string', 'max:2000'],
            'spine.antagonist_regret' => ['nullable', 'string', 'max:2000'],
            'acts.*.title' => ['required', 'string', 'max:'.Act::TITLE_MAX_CHARS],
            'acts.*.summary' => ['nullable', 'string', 'max:'.Act::SUMMARY_MAX_CHARS],
            'acts.*.escalation_beat' => ['nullable', 'string', 'max:'.Act::ESCALATION_BEAT_MAX_CHARS],
            // Editable, unlike the phase, because the repair for a refused
            // act is to rewrite its summary as present-day and then SAY so —
            // an operator who fixes the text by hand with no way to clear the
            // declaration would have an outline that cannot be approved
            // without regenerating it. `in:` rather than a bare string so a
            // typo is a refusal and not a null the check reads as unknown.
            'acts.*.timeframe' => ['nullable', 'in:'.implode(',', array_column(ActTimeframe::cases(), 'value'))],
        ];
    }

    /**
     * Every validation failure on this page, labelled and anchored, for the
     * gate bar.
     *
     * ---------------------------------------------------------------------
     * WHY THIS EXISTS: A REFUSED SAVE SAID NOTHING, AND THE PRESS WAS AT THE
     * BOTTOM OF THE THIRD SCREEN.
     * ---------------------------------------------------------------------
     *
     * Livewire catches ValidationException, fills the error bag and answers
     * 200. No modal, no log, no exception — the only surface a validation
     * refusal has is an `@error` directive, and the act summary never had one.
     * So story 28's operator pressed Approve, Livewire refused act 4's
     * 2,026-character summary, and the page re-rendered byte-identical to the
     * one before the press. Even a present `@error` would have rendered three
     * screens up beside the field, below the fold of a sticky bar that gave no
     * sign anything had been refused.
     *
     * This is the third refusal path on a gate page and it reaches none of the
     * other two's vocabulary: a capability refusal is a 403 the browser shows,
     * a dispatch refusal lands in `$problem`, and a validation refusal lands
     * ONLY in the error bag. Rendering the bag whole — every key, not the ones
     * a template author remembered — is what makes an invisible refusal
     * unreachable rather than merely unlikely. A new rule in saveRules() is on
     * the page by construction.
     *
     * Labels are built here rather than through Laravel's attribute names
     * because "acts.3.summary" is not a place on the page and "Act 4 —
     * summary" is; the anchor is the field's own id so the line is a link to
     * the thing that was refused.
     *
     * @return array<int, array{key: string, label: string, anchor: string, message: string}>
     */
    #[Computed]
    public function refusedFields(): array
    {
        $spineLabels = array_map(
            static fn (array $field): string => $field['label'],
            $this->spineReview()['spine'],
        );

        return RefusedFields::from($this->getErrorBag(), fn (string $key): array => match (true) {
            $key === 'premise' => ['Premise', 'premise'],
            $key === 'castAgeProfile' => ['Cast age range', 'cast-age'],
            (bool) preg_match('/^cast\.(\d+)\.(name|role|relationship)$/', $key, $c) => [
                sprintf('Cast row %d — %s', (int) $c[1] + 1, $c[2]),
                sprintf('cast-%s-%d', $c[2], (int) $c[1]),
            ],
            str_starts_with($key, 'spine.') => [
                $spineLabels[substr($key, 6)] ?? ucfirst(str_replace('_', ' ', substr($key, 6))),
                'spine-'.substr($key, 6),
            ],
            (bool) preg_match('/^acts\.(\d+)\.(title|summary|escalation_beat|timeframe)$/', $key, $m) => [
                sprintf(
                    'Act %s — %s',
                    $this->acts[(int) $m[1]]['sequence'] ?? ((int) $m[1] + 1),
                    str_replace('_', ' ', $m[2]),
                ),
                sprintf('act-%s-%d', str_replace('escalation_beat', 'beat', $m[2]), (int) $m[1]),
            ],
            default => RefusedFields::plain($key),
        });
    }

    public function save(): void
    {
        $this->authorizeEdit();

        // A stale refusal must not outlive the press it was about: a save that
        // now passes must not keep last time's list on the bar.
        $this->resetErrorBag();
        unset($this->refusedFields);

        $this->validate(self::saveRules(), [
            'acts.*.title.max' => 'An act title doubles as a YouTube chapter title; keep it under '
                .Act::TITLE_MAX_CHARS.' characters.',
            'acts.*.summary.max' => 'This summary is what the next act is written from, and it has a bound of '
                .number_format(Act::SUMMARY_MAX_CHARS).' characters — above anything the writer has ever '
                .'returned. Trim it here, or the story cannot be saved.',
        ]);

        $this->story->update([
            'premise' => $this->premise,
            // Empty stays null rather than becoming an empty string. The
            // extraction prompt tests this field for emptiness to decide
            // whether to state an age range at all, and "" and null must not
            // be two different kinds of nothing.
            'cast_age_profile' => trim($this->castAgeProfile) ?: null,
            // An emptied cast is stored as null, which Gate 1 reads as missing
            // on an outline that was asked for one.
            'outline_cast' => OutlineCast::rows(OutlineCast::members($this->cast)) ?: null,
        ] + $this->spine);

        foreach ($this->acts as $act) {
            Act::query()->whereKey($act['id'])->update([
                'title' => $act['title'],
                'summary' => $act['summary'],
                'escalation_beat' => $act['escalation_beat'],
                'is_rehook_written' => $act['is_rehook_written'],
                // Empty stays null: null is "not asked", and an empty string
                // would be a third kind of nothing the check cannot read.
                'timeframe' => ($act['timeframe'] ?? '') === '' ? null : $act['timeframe'],
            ]);
        }

        // draft -> outlined is an ordinary move, not a gate: the gate is the
        // NEXT step, and it needs the operator to press the other button.
        if ($this->story->status === StoryStatus::Draft) {
            $this->story->transitionTo(StoryStatus::Outlined);
        }

        $this->saved = 'Outline saved.';
        $this->story->refresh();

        // One list, in one place. This was two named properties, and the Gate 2
        // page has already been bitten by exactly that: three call sites
        // unsetting their own hand-written lists, which had drifted apart.
        $this->resetComputed();
    }

    public function addCastMember(): void
    {
        $this->authorizeEdit();

        $this->cast[] = ['name' => '', 'role' => CastRole::NarratorSide->value, 'relationship' => ''];
    }

    public function removeCastMember(int $index): void
    {
        $this->authorizeEdit();

        unset($this->cast[$index]);
        $this->cast = array_values($this->cast);
    }

    public function approve(): void
    {
        $this->save();

        // The first write press now stops after the outline, so an outline
        // with no act scripts is the ORDINARY state between two presses, not
        // the aftermath of a failed run. Approving there moves the story to
        // `scripted`, where writing is refused — a story past Gate 1 with
        // nothing written and no button to write it.
        if ($unwritten = $this->unwrittenActs()) {
            $this->problem = sprintf(
                'Gate 1 not crossed: act(s) %s have no script. Write them first — the approval is a '
                .'judgement about the outline AND the acts written against it.',
                implode(', ', $unwritten),
            );

            return;
        }

        $this->story->approveGate(Gate::Outline);
        $this->story->refresh();
        $this->resetComputed();

        $this->saved = 'Gate 1 approved. The scenes are cut next, from the Gate 2 page — a separate '
            .'press, because that is where the money line is.';
    }

    /**
     * Back through the gate. Legal, and deliberately explicit: the scripts
     * written against this outline do not disappear, and regenerating them
     * costs money from Phase 2.
     */
    // -- Writing the outline and the act scripts -----------------------------

    /**
     * The queue the writing runs on, beside the button that dispatches to it.
     *
     * `text` spent two phases in config, in the setup docs and in the NSSM
     * instructions receiving nothing at all — an operator following those
     * instructions ran a worker that could never get a job. It now carries the
     * stage that starts every video, and an absent worker on it is a warning
     * rather than a refusal, so "queued" and "queued into nothing" would
     * otherwise read identically.
     *
     * @return array{queue: string, role: string, state: string, live: int, stale: int, oldest_boot: ?string, headline: string}
     */
    #[Computed]
    public function workers(): array
    {
        return WorkerHealth::forQueue((string) config('render.queues.text'));
    }

    #[Computed]
    public function canWrite(): bool
    {
        return OperatorAction::WriteScript->permittedAt($this->story->status);
    }

    /** Rendered on the page, never swallowed. */
    #[Computed]
    public function writeRefusal(): ?string
    {
        return OperatorAction::WriteScript->refusalReason($this->story->status);
    }

    /**
     * Acts with no script yet.
     *
     * This is what makes the button a resume rather than a rewrite. Act scripts
     * are six sequential Opus calls and a run that dies on act 4 has already
     * billed three — re-running must write the missing acts and keep the ones
     * that landed, or a partial failure costs the whole story again.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function unwrittenActs(): array
    {
        return $this->story->acts()
            ->orderBy('sequence')
            ->get()
            ->filter(fn (Act $act): bool => trim((string) $act->script) === '')
            ->pluck('sequence')
            ->all();
    }

    /**
     * What the next press spends, and on what.
     *
     * @return array{calls: int, outline: bool, acts: int, roster: array<int, string>}
     */
    #[Computed]
    public function writeEstimate(): array
    {
        $total = $this->story->acts()->count();
        $outline = $total === 0;
        // The outline press buys the outline and nothing else: its cast and
        // spine are reviewed here before a single act is paid for. It used to
        // queue every act behind the outline in the same press, so the one
        // decision this page exists for was made after the money was spent.
        $acts = $outline ? 0 : count($this->unwrittenActs());

        return [
            'calls' => ($outline ? 1 : 0) + $acts,
            'outline' => $outline,
            'acts' => $acts,
            'roster' => app(ModelRoster::class)->lines(ModelRoster::SCRIPT_OPERATIONS),
        ];
    }

    /**
     * The reading rate this story's script is sized against, and what it buys.
     *
     * ---------------------------------------------------------------------
     * THE GAP THIS CLOSES, WHICH THIS PASS CREATED
     * ---------------------------------------------------------------------
     *
     * `targetWordsPerAct()` moved from the fallback 160 to the measured 197, and
     * `sized_against_wpm` freezes whatever a story was written to. So story 9
     * has a 5,600-word target and a story written today has 6,895, and until
     * now nothing on any page said why. Two stories, two budgets, no
     * explanation — which is the shape this file keeps recording from the other
     * side: a figure that is right and unexplained is read as a figure that is
     * wrong.
     *
     * Gate 1 is where it belongs because Gate 1 is where the target is DECIDED.
     * The money panel above quotes the bill for a run; this says what the run
     * will produce and at what rate, before the money is spent.
     *
     * ---------------------------------------------------------------------
     * THREE STATES, AND THE THIRD IS THE ONE THAT MATTERS
     * ---------------------------------------------------------------------
     *
     *   RECORDED     the rate is frozen. Show it, its provenance, the target it
     *                produced, and how the written script compares.
     *   PROSPECTIVE  nothing is frozen and a run can still be made. Show what
     *                that run WOULD fix, labelled as not yet fixed.
     *   UNKNOWN      a script exists and no rate was recorded for it.
     *
     * The first version of this had two states and elided the third, which was
     * wrong in exactly the way the column exists to prevent. Null means unknown,
     * and hiding unknown is how absence comes to read as agreement — the defect
     * this project has now recorded eleven times. A script whose target is not
     * on record is a fact about that script, and the page says it.
     *
     * **UNKNOWN prints no target, and that is the point rather than a gap.**
     * Computing one from today's rate and setting it beside the written word
     * count would compare a script against a budget it never had — which is the
     * precise false comparison `sized_against_wpm` was added to make
     * impossible. A number that cannot be stood behind does not get printed;
     * the same rule the Gate 4 banner's date lost its figure to.
     *
     * `wpmFor()` reads the frozen value and never writes. Freezing is the
     * generator's job — a page that recorded provenance as a side effect of
     * being looked at would be writing history by being read.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function sizing(): array
    {
        $story = $this->story;

        $written = $story->acts()->get()->sum(
            fn (Act $act): int => str_word_count((string) $act->script)
        );

        $state = match (true) {
            $story->sized_against_wpm !== null => 'recorded',
            $this->canWrite() => 'prospective',
            default => 'unknown',
        };

        $acts = $story->acts()->count() ?: GenerateOutline::defaultActCountFor($story);

        // Withheld on UNKNOWN rather than computed and hidden by the template:
        // a figure that exists in the payload is a figure some later surface
        // will print.
        $target = $state === 'unknown' ? null : ScriptSizing::targetWords($story);

        return [
            'state' => $state,
            // Only meaningful where a rate is claimed. Null on UNKNOWN for the
            // same reason as the target.
            'wpm' => $state === 'unknown' ? null : ScriptSizing::wpmFor($story),
            'measured' => NarrationPace::isMeasured($story->voice_id, $story->locale_profile),
            'measured_on' => NarrationPace::measuredOn($story->voice_id, $story->locale_profile),
            'voice' => NarrationPace::voiceName($story->voice_id) ?? $story->voice_id,
            'acts' => $acts,
            'target' => $target,
            'per_act' => $target === null ? null : ScriptSizing::targetWordsPerAct($story, $acts),
            'written' => $written,
            // The runtime the words on the page imply, or the runtime the target
            // implies where nothing is written yet. Both at the narrator's
            // measured rate, never at the sizing rate — ScriptSizing::minutesFor().
            'minutes' => ScriptSizing::minutesFor($story, $written > 0 ? $written : (int) $target),
            'in_window' => ScriptSizing::withinWindow(
                $story,
                ScriptSizing::minutesFor($story, $written > 0 ? $written : (int) $target)
            ),

            // THE SECOND NUMBER, and it is here because the first one is a
            // design point rather than a forecast. The target says what the
            // script is asked for; the writer returns ~1,100 words an act
            // whatever it is asked for, so a page showing only the target's
            // runtime shows the runtime of a script nobody is going to get.
            //
            // Both, labelled, rather than one replacing the other: a figure and
            // its provenance travel together or the next reader inherits a
            // number with no way to weigh it. Withheld once a script exists —
            // by then `written` is the measurement and a projection beside it
            // would be a guess competing with a fact.
            'projected' => $written > 0 ? null : [
                'words' => ScriptSizing::projectedWords($acts),
                'per_act' => ScriptSizing::naturalActWords(),
                'minutes' => ScriptSizing::projectedMinutes($story, $acts),
                'in_window' => ScriptSizing::withinWindow($story, ScriptSizing::projectedMinutes($story, $acts)),
                'measured_on' => ScriptSizing::naturalActWordsMeasuredOn(),
                'slope' => ScriptSizing::targetResponseSlope(),
            ],
        ];
    }

    /**
     * Whether there is anything worth saying about the sizing at all.
     *
     * Three of the four combinations have something: a recorded rate, a run
     * that would fix one, or a written script with no rate on record. The
     * fourth — nothing written, nothing writable — would say "nothing was
     * sized, and you cannot size it", which is noise dressed as information.
     *
     * The group elides on that, rather than the blade carrying a condition. It
     * is a real occurring case and not a hypothetical: `sample-story` is parked
     * at `rendered` permanently, with acts imported from a Phase 0 fixture and
     * no scripts in them.
     */
    #[Computed]
    public function hasSizingToShow(): bool
    {
        return $this->story->sized_against_wpm !== null
            || $this->canWrite()
            || $this->story->acts()->whereNotNull('script')->exists();
    }

    public function askToWrite(): void
    {
        $this->problem = null;

        // Said before the bill rather than after the press: the dispatch
        // refuses the same thing, but a bill shown for an outline that will not
        // be queued is a page claiming a spend it cannot make.
        if ($this->canChooseEnding() && $this->story->ending === null) {
            $this->problem = GenerateOutline::NO_ENDING;

            return;
        }

        $this->confirmingWrite = true;
    }

    public function cancelWrite(): void
    {
        $this->confirmingWrite = false;
    }

    /**
     * Queue the outline and the act scripts.
     *
     * The same Action the command reaches, so the capability predicate and the
     * worker check are one implementation. Only the unwritten acts are named,
     * which makes this idempotent in the way that matters: pressing it twice
     * after a complete run queues nothing to bill.
     */
    public function write(): void
    {
        abort_unless($this->canWrite(), 403, (string) OperatorAction::WriteScript->refusal($this->story->status));

        $this->confirmingWrite = false;
        $this->problem = null;

        $missing = $this->unwrittenActs();
        $outlineFirst = $this->story->acts()->count() === 0;

        try {
            $result = app(DispatchTextStage::class)->writeScript(
                story: $this->story,
                // A partial resume names its acts. A first run names none,
                // because the outline has to be written before there are any.
                actsOnly: $outlineFirst ? [] : $missing,
                // And a first run stops after the outline, so the cast is read
                // before any act is written against it.
                outlineOnly: $outlineFirst,
            );
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

        $this->saved = $outlineFirst
            ? sprintf(
                'Queued on the "%s" queue: the outline only. Read its cast and spine here when it lands; '
                .'the acts are a second press.',
                $result['queue'],
            )
            : sprintf(
                'Queued on the "%s" queue. %d act(s) with no script; everything already written is kept. '
                .'Each act is written knowing the ones before it, so they run in order — the page shows '
                .'them as the rows land.',
                $result['queue'],
                count($missing),
            );

        if ($warnings !== []) {
            $this->problem = implode(' ', $warnings);
        }
    }

    private function resetComputed(): void
    {
        unset(
            $this->editable,
            $this->canApprove,
            $this->voice,
            $this->actsMissingRehooks,
            $this->spineReview,
            // Both locale reads, so a save that edits the denied term out of a
            // spine field clears its alert in the same request.
            $this->localeDenied,
            $this->localeDeniedInScripts,
            $this->localeWarnings,
            $this->canReopen,
            $this->reopenRefusal,
            $this->workers,
            $this->canWrite,
            $this->writeRefusal,
            $this->unwrittenActs,
            $this->writeEstimate,
            $this->canChooseEnding,
        );
    }

    // -----------------------------------------------------------------------
    // Failures on this page's own stages, and truncations that outlive them.
    // -----------------------------------------------------------------------

    /**
     * The outline or act-script run that failed last, if its row still says so.
     *
     * The page that decides whether to press Write again said nothing about the
     * last press failing: the row was on the progress page only (the
     * "Gate 1's retry EXISTS. What is missing is the failure" entry). A re-run
     * resets the row, so this shows a failure for exactly as long as it is the
     * stage's latest word — which is why truncations are ALSO read from the
     * ledger below.
     *
     * @return array<int, array{stage: string, error: string, at: ?string, remedy: \App\Support\Remedy}>
     */
    #[Computed]
    public function stageFailures(): array
    {
        return RenderJob::query()
            ->where('story_id', $this->story->id)
            ->whereIn('stage', [RenderStage::Outline, RenderStage::ActScripts])
            ->whereNull('scene_id')
            ->where('status', RenderJobStatus::Failed)
            ->get()
            ->map(fn (RenderJob $row): array => [
                'stage' => $row->stage->label(),
                'error' => (string) $row->error,
                'at' => $row->updated_at?->toDateTimeString(),
                'remedy' => \App\Support\FailureRemedy::for(
                    $row->failure_kind ?? \App\Enums\FailureKind::Unclassified,
                    (array) $row->failure_facts,
                    $this->story,
                    $row->stage->value,
                ),
            ])
            ->all();
    }

    /**
     * Every outline call on this story that stopped at its ceiling.
     *
     * STANDING POSITION (CLAUDE.md, the ceiling entry dated 2026-09-19): the
     * outline ceiling is NOT raised. The text grows ~273 tokens per spine field
     * and was ~4 average fields from the medium-effort reasoning peak on story
     * 37; the lever is effort `low`, unmeasured. So a truncation is information
     * and must be seen — and the job row that recorded it is reset by the next
     * run, so it is read from the ledger, which nothing deletes. Only calls
     * whose cost row carries `stop_reason` (written since 2026-09-19) can
     * answer; older truncations are the three in CLAUDE.md.
     *
     * @return array<int, array{at: string, output: int, effort: ?string, usd: string}>
     */
    #[Computed]
    public function outlineTruncations(): array
    {
        return $this->story->costEntries()
            ->where('operation', 'generate_outline')
            ->where('detail->stop_reason', 'max_tokens')
            ->orderBy('id')
            ->get()
            ->map(fn ($row): array => [
                'at' => (string) $row->created_at?->toDateTimeString(),
                'output' => (int) ($row->detail['output_tokens'] ?? 0),
                'effort' => $row->detail['effort'] ?? null,
                'usd' => number_format((float) $row->usd_cost, 4),
            ])
            ->all();
    }

    // -----------------------------------------------------------------------
    // Premises from an idea. Draft only, single narrative only, no outline yet.
    // -----------------------------------------------------------------------

    /** The capability, plus the premise's own conditions (GeneratePremises::assertReady). */
    #[Computed]
    public function canWritePremises(): bool
    {
        return OperatorAction::WritePremises->permittedAt($this->story->status)
            && $this->story->format === StoryFormat::Single
            && ! $this->story->acts()->exists();
    }

    /** Said, not swallowed: why an anthology has no premise panel. */
    #[Computed]
    public function premiseRefusal(): ?string
    {
        return OperatorAction::WritePremises->permittedAt($this->story->status)
            && $this->story->format === StoryFormat::Anthology
            ? 'Premises are written from an idea for a single narrative only. An anthology premise is a '
                .'different shape that nothing here has been built or checked for.'
            : null;
    }

    /**
     * The latest roll, with every candidate's checks run NOW from its stored
     * fields. Nothing about a check is stored, so a check changed since the
     * roll reports on it in its current terms.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function premiseRoll(): ?array
    {
        $set = $this->story->premise_candidates;

        if (! is_array($set) || ($set['candidates'] ?? []) === []) {
            return null;
        }

        $validator = app(ValidateOutlineSpine::class);
        $candidates = [];

        foreach ($set['candidates'] as $index => $row) {
            $candidate = PremiseCandidate::fromRow((array) $row);

            $candidates[] = [
                'index' => $index,
                'candidate' => $candidate,
                'checks' => $validator->premiseChecks($this->story, $candidate),
                'chosen' => trim((string) $this->story->premise) === $candidate->premise,
            ];
        }

        $markers = PremiseIdea::revengeMarkers((string) ($set['idea'] ?? ''));

        return [
            'idea' => (string) ($set['idea'] ?? ''),
            'generated_at' => $set['generated_at'] ?? null,
            'requested' => (int) ($set['requested'] ?? GeneratePremises::COUNT),
            'revenge_shaped' => (bool) ($set['idea_was_revenge_shaped'] ?? false),
            'translation' => trim((string) ($set['translation'] ?? '')),
            // The second reading of the idea, from its own words. Shown only
            // when the generator did not report a translation itself.
            'revenge_markers' => $markers,
            'candidates' => $candidates,
        ];
    }

    /**
     * Where the latest roll is: idle, queued (dispatched here, no row yet —
     * a queued job writes no row until it starts), running, or failed with
     * its error and the remedy the progress page would give.
     *
     * @return array{state: string, error: ?string, remedy: ?\App\Support\Remedy}
     */
    #[Computed]
    public function premiseState(): array
    {
        $row = RenderJob::query()
            ->where('story_id', $this->story->id)
            ->where('stage', RenderStage::Premises)
            ->latest('id')
            ->first();

        $generatedAt = $this->story->premise_candidates['generated_at'] ?? null;
        $queuedAt = $this->premisesQueuedAt;

        if ($row !== null && ! $row->status->isFinished()) {
            return ['state' => 'running', 'error' => null, 'remedy' => null];
        }

        if ($row !== null && $row->status === RenderJobStatus::Failed
            && ($generatedAt === null || $row->updated_at->greaterThan($generatedAt))) {
            return [
                'state' => 'failed',
                'error' => (string) $row->error,
                'remedy' => \App\Support\FailureRemedy::for(
                    $row->failure_kind ?? \App\Enums\FailureKind::Unclassified,
                    (array) $row->failure_facts,
                    $this->story,
                    $row->stage->value,
                ),
            ];
        }

        if ($queuedAt !== null && ($generatedAt === null || $generatedAt < $queuedAt)) {
            return ['state' => 'queued', 'error' => null, 'remedy' => null];
        }

        return ['state' => 'idle', 'error' => null, 'remedy' => null];
    }

    public function askToWritePremises(): void
    {
        $this->problem = null;
        $this->validate(['idea' => ['required', 'string', 'min:10']], [
            'idea.min' => 'A line is enough, but it needs to be a line: who, what they did, and where.',
        ]);

        $this->confirmingPremises = true;
    }

    public function cancelPremises(): void
    {
        $this->confirmingPremises = false;
    }

    /** Queue one roll. The same Action the capability names, through the dispatcher. */
    public function writePremises(): void
    {
        abort_unless($this->canWritePremises(), 403, (string) OperatorAction::WritePremises->refusal($this->story->status));

        $this->confirmingPremises = false;
        $this->problem = null;

        try {
            $result = app(DispatchTextStage::class)->writePremises($this->story, $this->idea);
        } catch (Throwable $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->premisesQueuedAt = now()->toIso8601String();
        unset($this->premiseState, $this->premiseRoll);

        $this->saved = sprintf(
            'Queued on the "%s" queue: one call, three premises. This panel checks for them every few '
            .'seconds; the checks run on each before it is shown.',
            $result['queue'],
        );

        $warnings = array_column(array_filter($result['notes'], fn (array $n): bool => $n['level'] !== 'ok'), 'message');

        if ($warnings !== []) {
            $this->problem = implode(' ', $warnings);
        }
    }

    /**
     * Use a candidate as the premise. Writes the premise column and nothing
     * else: `save()` moves a draft to "outlined", which would end the premise
     * panel before the outline exists. The text is the record — the stored
     * decision is the artifact the outline reads.
     */
    public function usePremise(int $index): void
    {
        abort_unless($this->canWritePremises(), 403, (string) OperatorAction::WritePremises->refusal($this->story->status));

        $row = $this->story->premise_candidates['candidates'][$index] ?? null;
        abort_if(! is_array($row), 404);

        $premise = PremiseCandidate::fromRow($row)->premise;

        $this->story->update(['premise' => $premise]);
        $this->premise = $premise;
        $this->story->refresh();
        unset($this->premiseRoll);

        $this->saved = sprintf(
            'Premise %d is now this story\'s premise. Edit it below if you want; "Write the outline" is the next press.',
            $index + 1,
        );
    }

    /**
     * Whether the ending can still be chosen: a single narrative whose outline
     * has not been written. After the outline it is read-only — the outline
     * asked for the fields that ending needs, and changing it means writing
     * the outline again, which is a billed press and not a radio button.
     */
    #[Computed]
    public function canChooseEnding(): bool
    {
        return $this->story->format === StoryFormat::Single
            && in_array($this->story->status, [StoryStatus::Draft, StoryStatus::Outlined], true)
            && ! $this->story->acts()->exists();
    }

    /**
     * How the last few videos ended, this one excluded. See RecentEndings.
     *
     * @return array{rows: array<int, array<string, mixed>>, streak: ?array{ending: StoryEnding, count: int}}
     */
    #[Computed]
    public function recentEndings(): array
    {
        $rows = RecentEndings::last(RecentEndings::SHOWN, $this->story->id);

        return ['rows' => $rows, 'streak' => RecentEndings::streak($rows)];
    }

    /** Livewire's hook for `wire:model.live="ending"`: saved as it is picked. */
    public function updatedEnding(string $value): void
    {
        abort_unless(
            $this->canChooseEnding(),
            403,
            'The ending is fixed once the outline is written: the outline wrote the fields it needs.',
        );

        $ending = StoryEnding::tryFrom($value);
        abort_if($ending === null, 422);

        $this->story->update(['ending' => $ending]);
        $this->story->refresh();

        $this->saved = sprintf('Ending set: %s. Nothing was billed.', $ending->label());
    }

    #[Computed]
    public function canReopen(): bool
    {
        return OperatorAction::ReopenOutlineGate->permittedAt($this->story->status);
    }

    /** Why not, for the operator. Null when it is offered. */
    #[Computed]
    public function reopenRefusal(): ?string
    {
        return OperatorAction::ReopenOutlineGate->refusalReason($this->story->status);
    }

    public function reopen(): void
    {
        // The guard the blade was deciding for itself. It showed this button
        // whenever the outline was not editable — which is nine statuses — and
        // `scripted -> outlined` is legal from exactly one of them. Pressing it
        // anywhere else was an illegal-transition error on a page that had just
        // offered the move.
        abort_unless(
            $this->canReopen(),
            403,
            (string) OperatorAction::ReopenOutlineGate->refusal($this->story->status),
        );

        $this->story->transitionTo(StoryStatus::Outlined);
        $this->story->refresh();
        $this->resetComputed();

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
            // Read-only on this page. The phase is the act structure, not an
            // act's content: moving one act into another phase without moving
            // the ones around it produces an outline with two departures or
            // none, and the fix for a wrong structure is to re-generate the
            // outline rather than to edit a dropdown.
            'phase' => $act->phase?->value,
            'phase_label' => $act->phase?->label() ?? '',
            'beat_label' => $act->phase?->beatLabel()
                ?? 'Escalation beat — what this act costs the narrator',
            // The outline writer's declaration of WHEN the act is set. Null
            // on every act outlined before the question was asked.
            'timeframe' => $act->timeframe?->value,
            'title' => (string) $act->title,
            'summary' => (string) $act->summary,
            'escalation_beat' => (string) $act->escalation_beat,
            'is_rehook_written' => (bool) $act->is_rehook_written,
            // The chapters the act was written as, read-only here: they are
            // the writer's cut of its own script, and the repair for a bad
            // one is to rewrite the act. Empty on an act written before
            // chapters existed, and the card says nothing then rather than
            // showing an empty list.
            'chapters' => $act->chapters->map(fn (Chapter $chapter): array => [
                'sequence' => $chapter->sequence,
                'title' => (string) $chapter->title,
                'has_rehook' => $chapter->hasRehook(),
                'point_of_view' => $chapter->isPointOfView() ? (string) $chapter->point_of_view : null,
                'first_sentence' => $chapter->first_sentence,
            ])->all(),
        ])->all();
    }

    private function authorizeEdit(): void
    {
        abort_unless($this->editable(), 403, 'The outline is locked once Gate 1 has been approved.');
    }
}
