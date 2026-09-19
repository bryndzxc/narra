<?php

namespace App\Livewire\Stories;

use App\Actions\CreateStory;
use App\Actions\DispatchTextStage;
use App\Actions\GenerateOutline;
use App\Enums\OperatorAction;
use App\Enums\StoryEnding;
use App\Enums\StoryFormat;
use App\Exceptions\DispatchRefusedException;
use App\Models\Story;
use App\Support\LocaleGuard;
use App\Support\ModelRoster;
use App\Support\NarrationPace;
use App\Support\NarratorVoice;
use App\Support\RecentEndings;
use App\Support\ScriptSizing;
use App\Support\WorkerHealth;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * The front door. Premise in, outline and act scripts queued.
 *
 * There was no way to start a video from this app at all until now: `story:write
 * --premise` held the only copy of story creation, so the tool built so an
 * operator would not need a terminal required one to begin.
 *
 * **What this deliberately does not do.** It does not run through to scenes.
 * Gate 1 sits between the act scripts and the scene draft, and a "new story"
 * button that carried on past it would cross a gate on the operator's behalf —
 * which is the one thing this app does not do, at any scale. The scene draft is
 * its own press, on the Gate 2 page, once Gate 1 has actually been approved.
 *
 * It also does not spend anything on submit without saying what. Seven billed
 * calls against Opus is not a form submission, so the estimate and the model
 * roster are on screen before the button, and the button says what it costs.
 */
class NewStory extends Component
{
    public string $premise = '';

    public string $title = '';

    /**
     * The intended age range of the cast, optional, in the operator's words.
     *
     * Here rather than at Gate 2 because it is a casting decision and it is
     * made with the premise. It is read much later — the character
     * extraction prompt is its only consumer — but the moment to state it
     * is while the story is still an idea, which is also the moment it
     * costs nothing.
     *
     * Blank is a real answer and stays blank. The extractor then reads ages
     * out of the script as it always has, and no default is invented here:
     * a placeholder age range would be the app casting the video.
     */
    public string $castAgeProfile = '';

    public string $format = 'single';

    /**
     * Which world the story is set in.
     *
     * Chosen here and nowhere else, and that is deliberate rather than a
     * gap. Pressing the button below queues the outline immediately, and
     * every call after it — the acts, then the cast extraction — reads this
     * off the story. There is no later point at which changing it leaves a
     * story consistent: it would mean acts written in one setting and a cast
     * extracted for another, with nothing on the page saying which was
     * which. Getting it wrong here costs one story; getting it wrong halfway
     * costs a story that looks finished.
     */
    public string $localeProfile = '';

    public ?int $acts = null;

    /**
     * Who narrates: 'male' or 'female'. Required, and no default.
     *
     * It picks the voice from the channel's one-voice-per-narrator-gender
     * table (NarratorVoice). A default would be the trap story 33 was caught
     * in by a person reading it: a woman's story created on the male voice,
     * right only if somebody remembered `voices:list --set` before narration.
     */
    public string $narratorGender = '';

    /**
     * Which ending the last chapter is: a StoryEnding value. Required on a
     * single narrative and no default, for the narrator's reason — a default
     * is the per-story pick in disguise, and a model left to pick converges.
     * The last few videos' endings are printed beside it (RecentEndings).
     */
    public string $ending = '';

    /**
     * What the text box holds: a finished premise, or an idea to write
     * premises from. An idea creates the draft and queues NOTHING — the
     * premises are a separate, billed press on Gate 1, and the outline is the
     * one after that. Single narratives only.
     */
    public string $startFrom = 'premise';

    public ?string $problem = null;

    /** @var array<int, string> */
    public array $warnings = [];

    /**
     * Whether the operator has seen the spend and pressed once already.
     *
     * The same two-press shape as every other money button in this app. A
     * command asks `confirm()`; a page cannot, so it asks by rendering the bill
     * and waiting for a second press.
     */
    public bool $confirming = false;

    protected function rules(): array
    {
        return [
            // No maximum. A premise is the operator's editorial input and this
            // app does not have an opinion about how long an idea is.
            'premise' => ['required', 'string', 'min:20'],
            'title' => ['nullable', 'string', 'max:255'],
            'castAgeProfile' => ['nullable', 'string', 'max:500'],
            'format' => ['required', 'in:single,anthology'],
            // Validated against the config keys rather than a written-out
            // list, so a third profile is selectable the moment it exists.
            'localeProfile' => ['required', 'string', Rule::in(array_keys($this->locales()))],
            'acts' => ['nullable', 'integer', 'min:3', 'max:8'],
            'narratorGender' => ['required', Rule::in(NarratorVoice::GENDERS)],
            'ending' => [$this->format === 'anthology' ? 'nullable' : 'required', Rule::in(array_column(StoryEnding::cases(), 'value'))],
            'startFrom' => ['required', Rule::in($this->format === 'anthology' ? ['premise'] : ['premise', 'idea'])],
        ];
    }

    protected function messages(): array
    {
        return [
            'premise.required' => 'A premise is required. It is the editorial input and there is no '
                .'default for it — the app does not invent what the video is about.',
            'premise.min' => 'That premise is too short to write 6,000 words against. A sentence or two '
                .'of situation, and what goes wrong.',
            'acts.min' => 'Fewer than 3 acts cannot make a legal YouTube chapter list.',
            'acts.max' => 'More than 8 acts at this runtime makes chapters too short to be useful.',
            'narratorGender.required' => 'Say who narrates. It picks the voice, and there is no default.',
            'ending.required' => 'Choose how the video ends. The outline writes what that ending needs, '
                .'and there is no default.',
            'startFrom.in' => 'Premises are written from an idea for a single narrative only.',
        ];
    }

    /**
     * Whether the `text` queue can actually do this work.
     *
     * Rendered next to the button rather than on a separate page, and that
     * placement is the whole point. This console replaces three terminal
     * windows, and those windows were the only place worker liveness was
     * visible. An absent worker is not a refusal — the job queues and waits,
     * nothing is lost — so without this line "queued" and "queued into nothing"
     * look identical, which is absence read as agreement on the page the
     * operator watches instead of the pipeline.
     *
     * @return array{queue: string, role: string, state: string, live: int, stale: int, oldest_boot: ?string, headline: string}
     */
    #[Computed]
    public function workers(): array
    {
        return WorkerHealth::forQueue((string) config('render.queues.text'));
    }

    /**
     * How many billed calls this is about to make, and against what.
     *
     * @return array{calls: int, acts: int, roster: array<int, string>, provider: string}
     */
    #[Computed]
    public function estimate(): array
    {
        // From the Action, not from a copy of its numbers. This line read
        // `($this->format === 'anthology' ? 5 : 6)` and the Action said 7 for a
        // single, so this money screen quoted 7 calls for a run that made 8.
        $acts = $this->acts ?? GenerateOutline::defaultActCountForFormat(
            StoryFormat::from($this->format)
        );

        // THE ACT COUNT IS THE LEVER, AND IT IS CHOSEN ON THIS FORM.
        //
        // This screen quoted calls and dollars and no runtime at all, which was
        // survivable while the word target was believed to govern length. It
        // does not: the fitted response is +0.30, so an act comes back at ~1,100
        // words whatever it was asked for and the act count is what multiplies
        // it. An operator moving this field from 6 to 8 was moving the runtime
        // by nine minutes with nothing on screen saying so.
        //
        // Both numbers, for the reason Gate 1 shows both: the target's runtime
        // is the design point and the projection is what the writer actually
        // returns, and a figure without its provenance is one the next reader
        // cannot weigh.
        $window = Story::defaultDurationWindow();
        $wpm = NarrationPace::bestKnownWpm(null, $this->localeProfile);

        $targetWords = (int) round(($window['min'] + $window['max']) / 2 * $wpm);
        $projectedWords = ScriptSizing::projectedWords($acts);

        return [
            // One outline call, then one per act. Sequential, each fed the
            // summaries of the acts before it — a one-shot generator at this
            // length produces drift and contradictions.
            'calls' => $acts + 1,
            'acts' => $acts,
            'roster' => app(ModelRoster::class)->lines(ModelRoster::SCRIPT_OPERATIONS),
            'provider' => (string) config('providers.script_writer'),

            'window' => $window,
            'wpm' => $wpm,
            'target_words' => $targetWords,
            'target_minutes' => $targetWords / max(1, $wpm),
            'target_in_window' => self::inside($targetWords / max(1, $wpm), $window),
            'projected_words' => $projectedWords,
            'projected_per_act' => ScriptSizing::naturalActWords(),
            'projected_minutes' => $projectedWords / max(1, $wpm),
            'projected_in_window' => self::inside($projectedWords / max(1, $wpm), $window),
            'projected_measured_on' => ScriptSizing::naturalActWordsMeasuredOn(),
            'slope' => ScriptSizing::targetResponseSlope(),
        ];
    }

    /** @param  array{min: int, max: int}  $window */
    private static function inside(float $minutes, array $window): bool
    {
        return $minutes >= $window['min'] && $minutes <= $window['max'];
    }

    /**
     * How the last few videos ended, for the picker. See RecentEndings.
     *
     * @return array{rows: array<int, array<string, mixed>>, streak: ?array{ending: StoryEnding, count: int}}
     */
    #[Computed]
    public function recentEndings(): array
    {
        $rows = RecentEndings::last();

        return ['rows' => $rows, 'streak' => RecentEndings::streak($rows)];
    }

    /**
     * Every locale profile that exists, from the one place they are defined.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function locales(): array
    {
        return app(LocaleGuard::class)->profiles();
    }

    /**
     * The narrator this story will be created with, said where it is decided.
     *
     * -------------------------------------------------------------------
     * WHY A READOUT AND NOT A PICKER
     * -------------------------------------------------------------------
     *
     * A channel keeps ONE narrator across every video, which is the whole
     * reason `voice_id` is stored per story rather than read from config at
     * synthesis time. A dropdown here would invite a per-story choice on the
     * one axis that is supposed to be constant, and the operator would be
     * choosing before there is a script to choose for. `voices:list --set` is
     * the deliberate move, and it validates against the account.
     *
     * **But a default written silently is a value nobody chose and nobody can
     * find later**, which is how `narrator-us-01` — a string the fake
     * synthesizer invented — sat on every story in the database for a phase
     * with no screen anywhere disagreeing with it. So the value is printed at
     * the moment of creation, with what the app knows about it.
     *
     * -------------------------------------------------------------------
     * NO NETWORK CALL, AND THE LIMIT THAT IMPOSES IS STATED RATHER THAN HIDDEN
     * -------------------------------------------------------------------
     *
     * `NarrationPace::voiceName()` reads `render.narration.voices`, which is
     * this app's own record of narrators it has MEASURED. It cannot answer
     * whether the id is on the vendor account — only the vendor can, and
     * putting a provider call behind a form render would make story creation
     * fail when ElevenLabs is slow.
     *
     * So an unrecognised id is reported as unrecognised — never as fine, and
     * never as absent. It means one of two things and the readout says both:
     * a real voice this app has not measured, or an id that is not a voice at
     * all. `PreflightAssetDispatch` is what separates them, against the real
     * account list, before anything is queued.
     *
     * @return array{voice_id: ?string, name: ?string, measured: bool, wpm: int}
     */
    #[Computed]
    public function narrator(): array
    {
        // The chosen narrator's voice, from the channel's table. Before a
        // choice is made there is no voice to describe, and the readout says
        // so rather than describing the default as though it were chosen.
        $voiceId = in_array($this->narratorGender, NarratorVoice::GENDERS, true)
            ? NarratorVoice::voiceFor($this->narratorGender)
            : null;

        return [
            'voice_id' => $voiceId,
            'name' => NarrationPace::voiceName($voiceId),
            // Against THIS story's setting, which the picker above can change.
            // A voice measured on en-US and not on en-CN is measured for one
            // of the two stories this form can make, and saying "measured"
            // flatly would be true of the voice and false of the run.
            'measured' => NarrationPace::isMeasured($voiceId, $this->localeProfile),
            'wpm' => NarrationPace::bestKnownWpm($voiceId, $this->localeProfile),
        ];
    }

    public function mount(): void
    {
        // Pre-selected to the house setting rather than left blank. A
        // required field with no default is a form that refuses to submit
        // until the operator answers a question most stories do not have.
        $this->localeProfile = (string) config('locale.default');
    }

    public function askToCreate(): void
    {
        $this->problem = null;
        $this->validate();

        $this->confirming = true;
    }

    public function cancel(): void
    {
        $this->confirming = false;
    }

    /**
     * Create the row, then queue the writing.
     *
     * The story is created before the dispatch and stays created if the
     * dispatch is refused. That is deliberate: a refused dispatch means the
     * workers are stale, which is a thing the operator fixes in thirty seconds
     * and retries — and discarding their premise to keep the database tidy
     * would make the tidiest possible version of losing their work.
     */
    public function create(): void
    {
        $this->validate();

        $this->problem = null;
        $this->warnings = [];

        try {
            $story = app(CreateStory::class)->handle(
                premise: $this->premise,
                title: $this->title,
                format: StoryFormat::from($this->format),
                castAgeProfile: $this->castAgeProfile,
                localeProfile: $this->localeProfile,
                narrator: $this->narratorGender,
                ending: StoryEnding::tryFrom($this->ending),
            );
        } catch (Throwable $e) {
            $this->confirming = false;
            $this->problem = $e->getMessage();

            return;
        }

        // An idea queues nothing. The premises are their own billed press on
        // Gate 1, which is where they are read and picked; creating the draft
        // is not a spending decision and does not become one here.
        if ($this->startFrom === 'idea') {
            session()->flash('notice', sprintf(
                '"%s" created as a draft from your idea. Nothing was queued. Write premises from it '
                .'below; the outline is the press after you pick one.',
                $story->title,
            ));

            $this->redirectRoute('stories.outline', $story, navigate: true);

            return;
        }

        try {
            $result = app(DispatchTextStage::class)->writeScript($story, actCount: $this->acts);
        } catch (DispatchRefusedException $e) {
            // The story exists and nothing was queued. Say both, and send them
            // to it — the retry lives on the Gate 1 page.
            $this->confirming = false;
            $this->problem = $e->getMessage()."\n\nThe story was created and nothing was queued. "
                .'Restart the workers and press "'.OperatorAction::WriteScript->label().'" on Gate 1.';

            $this->redirectRoute('stories.outline', $story, navigate: true);

            return;
        } catch (Throwable $e) {
            $this->confirming = false;
            $this->problem = $e->getMessage();

            return;
        }

        session()->flash('notice', sprintf(
            '"%s" created and queued on the "%s" queue: one outline call, then %d act(s), sequential. '
            .'This page is safe to leave — progress is on the render page.%s',
            $story->title,
            $result['queue'],
            $this->estimate()['acts'],
            $this->warningSuffix($result['notes']),
        ));

        $this->redirectRoute('stories.outline', $story, navigate: true);
    }

    /**
     * An absent worker, appended to the success notice rather than swallowed.
     *
     * @param  array<int, array{level: string, message: string}>  $notes
     */
    private function warningSuffix(array $notes): string
    {
        $warnings = array_column(
            array_filter($notes, fn (array $n): bool => $n['level'] !== 'ok'),
            'message',
        );

        return $warnings === [] ? '' : ' — '.implode(' ', $warnings);
    }

    public function render(): View
    {
        return view('livewire.stories.new-story', [
            'formats' => StoryFormat::cases(),
            'endings' => StoryEnding::cases(),
            'existing' => Story::query()->count(),
        ]);
    }
}
