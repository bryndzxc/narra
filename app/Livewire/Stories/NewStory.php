<?php

namespace App\Livewire\Stories;

use App\Actions\CreateStory;
use App\Actions\DispatchTextStage;
use App\Enums\OperatorAction;
use App\Enums\StoryFormat;
use App\Exceptions\DispatchRefusedException;
use App\Models\Story;
use App\Support\LocaleGuard;
use App\Support\ModelRoster;
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
        $acts = $this->acts ?? ($this->format === 'anthology' ? 5 : 6);

        return [
            // One outline call, then one per act. Sequential, each fed the
            // summaries of the acts before it — a one-shot generator at this
            // length produces drift and contradictions.
            'calls' => $acts + 1,
            'acts' => $acts,
            'roster' => app(ModelRoster::class)->lines(ModelRoster::SCRIPT_OPERATIONS),
            'provider' => (string) config('providers.script_writer'),
        ];
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
            );
        } catch (Throwable $e) {
            $this->confirming = false;
            $this->problem = $e->getMessage();

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
            'existing' => Story::query()->count(),
        ]);
    }
}
