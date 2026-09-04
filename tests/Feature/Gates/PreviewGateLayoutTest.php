<?php

namespace Tests\Feature\Gates;

use App\Enums\RenderJobStatus;
use App\Enums\RenderStage;
use App\Enums\StoryStatus;
use App\Livewire\Gates\PreviewGate;
use App\Models\Act;
use App\Models\RenderJob;
use App\Enums\MotionPreset;
use App\Models\Scene;
use App\Models\Story;
use App\Support\RenderWorkspace;
use Faker\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\PageProbe;
use Tests\TestCase;

/**
 * Gate 3's body, specified BEFORE it is rebuilt.
 *
 * ---------------------------------------------------------------------------
 * WHAT THE DESIGN DOES NOT SAY, WHICH IS WHERE THESE COME FROM
 * ---------------------------------------------------------------------------
 *
 * The Gate 3 mock declares two states, `ready` and `running`. Gate 2's mock
 * declares three, and the third is `locked`. So Gate 3 was drawn with no
 * settled state at all — which is the state most stories are in, and the exact
 * shape of the defect Gate 1 and Gate 2 both shipped.
 *
 * The three cases in GateLayoutContractTest are the whole-page questions and
 * travel across every gate. These are the ones specific to this page, and every
 * one of them is written from a state the mock does not draw but the database
 * actually contains:
 *
 *   sample-story   rendered   2:42    27 minutes UNDER the window, forever
 *   story 9        published  29:38   22 seconds under — ships that way on
 *                                     purpose, and the record stays honest
 *   story 21       published  40:36   36 seconds OVER
 *
 * Not one story in this database is inside the target window, and `IN WINDOW`
 * is the only window state the mock draws. A page whose only specified state is
 * the one that never occurs is the busy-case defect with the arrow reversed.
 */
class PreviewGateLayoutTest extends TestCase
{
    use RefreshDatabase;

    // -- The state the mock does not draw ------------------------------------

    /**
     * Past Gate 3, the decision collapses — it does not render disabled.
     *
     * `metadata_ready` and `published` are the two statuses two of this
     * project's three rendered stories sit at. The approve button and the
     * send-back button are not decisions there, and a page that keeps the room
     * they need is the layout-as-constant defect a third time.
     */
    public function test_gate_three_collapses_once_the_render_is_behind_the_story(): void
    {
        foreach ([StoryStatus::MetadataReady, StoryStatus::Published] as $status) {
            $story = $this->renderedStory($status);

            $html = Livewire::test(PreviewGate::class, ['story' => $story])->html();

            $this->assertStringContainsString(
                'class="strip"',
                $html,
                sprintf(
                    'Gate 3 at "%s" has no decision left. What is not actionable collapses to a '
                    .'line, the way the dashboard, Gate 1 and Gate 2 all do.',
                    $status->value,
                ),
            );

            // The player is NOT dropped. Watching a finished render is still
            // useful after the gate — only the decision goes.
            $this->assertStringContainsString(
                '<video',
                $html,
                'Collapsing the decision must not take the finished video with it.',
            );
        }
    }

    // -- The window bar ------------------------------------------------------

    /**
     * The runtime marker stays on its own scale, and names the distance.
     *
     * The mock hardcodes the axis — 30 min at 20%, 40 min at 67%, which is a
     * scale of roughly 25.7 to 47 minutes. sample-story runs 2:42 and lands at
     * about MINUS 108%: off the element entirely, on a story that is parked at
     * `rendered` permanently and is therefore the one most often looked at.
     *
     * So the axis is derived from the story's own window and the marker is
     * clamped — and because a clamped marker is a lie on its own, the page says
     * how far outside it actually is. "27 min under" and "36 s over" are
     * different facts and must read differently.
     */
    public function test_the_window_marker_is_clamped_and_the_distance_is_named(): void
    {
        $cases = [
            'far under' => [162_000, 'under'],   // 2:42, the fixture
            'just under' => [1_778_000, 'under'], // 29:38, story 9
            'just over' => [2_436_000, 'over'],   // 40:36, story 21
            'inside' => [2_100_000, null],        // 35:00
        ];

        foreach ($cases as $label => [$durationMs, $direction]) {
            $story = $this->renderedStory(StoryStatus::Rendered, $durationMs);

            $html = Livewire::test(PreviewGate::class, ['story' => $story])->html();

            foreach ($this->markerPercents($html) as $percent) {
                $this->assertGreaterThanOrEqual(
                    0.0,
                    $percent,
                    "The {$label} runtime puts a marker off the left of its own scale.",
                );
                $this->assertLessThanOrEqual(
                    100.0,
                    $percent,
                    "The {$label} runtime puts a marker off the right of its own scale.",
                );
            }

            if ($direction === null) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/\b'.$direction.'\b/i',
                $html,
                "A {$label} runtime must SAY it is {$direction}. A clamped marker with no number "
                .'beside it reports 27 minutes out and 36 seconds out as the same picture.',
            );
        }
    }

    // -- The three states of the artifact ------------------------------------

    /**
     * `rendered` with no file is its own state, and not "still encoding".
     *
     * The mock has two states and folds this into the running one. It is the
     * false-success shape in the table this project keeps: the rows say the
     * stage finished and the artifact is not there. Telling an operator to go
     * and watch progress that has already completed sends them to a page that
     * will agree with the lie.
     */
    public function test_a_missing_file_on_a_rendered_story_is_not_reported_as_still_rendering(): void
    {
        $story = $this->renderedStory(StoryStatus::Rendered);
        @unlink(RenderWorkspace::for($story)->path('final.mp4'));

        $html = Livewire::test(PreviewGate::class, ['story' => $story])->html();

        $this->assertMatchesRegularExpression(
            '/no video|not on disk|render has not/i',
            $html,
            'A story marked rendered whose file is gone must say the file is gone.',
        );

        // Forbids the CLAIM, not the link. "Watch progress" is navigation and
        // stays true — the render page will correctly say nothing is running.
        // What must not appear is a sentence saying work is in flight, because
        // nothing is: the mux row says it finished and the file is not there.
        $this->assertDoesNotMatchRegularExpression(
            '/clips are encoding|still encoding|no finished render for this story yet/i',
            $html,
            'The story is not rendering — the mux row says it finished. Reporting a missing '
            .'artifact as work in flight sends the operator to a page that will agree with it.',
        );
    }

    /**
     * The mux badge comes from the row, never from a constant.
     *
     * The mock hardcodes `EXIT 0` beside the log. A failed mux is a real state
     * — it is how story 21's render page read for 21 hours while two stages
     * that never ran showed "1/1 done" — and a success badge painted over it is
     * this project's defect class in one span of markup.
     */
    public function test_the_mux_badge_reflects_the_row_and_not_a_constant(): void
    {
        $failed = $this->renderedStory(StoryStatus::Rendered);
        RenderJob::query()
            ->where('story_id', $failed->id)
            ->where('stage', RenderStage::Mux)
            ->update(['status' => RenderJobStatus::Failed, 'error' => 'ffmpeg exited 1']);

        $html = Livewire::test(PreviewGate::class, ['story' => $failed->refresh()])->html();

        $this->assertMatchesRegularExpression(
            '/failed/i',
            $html,
            'A failed mux must read as failed on the gate that reviews its output.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/exit 0/i',
            $html,
            'The success badge is hardcoded in the mock. It has to come from the render_jobs row.',
        );
    }

    /**
     * A progress figure on this page uses the honest denominator.
     *
     * THE ONE THAT IS ALREADY IN THE FALSE-SUCCESS TABLE. `RenderJob::open()`
     * runs INSIDE the job, so a scene still queued has no row and `render_jobs`
     * cannot count a backlog however carefully it is asked. That is exactly how
     * story 21 reported "118 stills done, nothing failed, no stale heartbeat"
     * while 152 scenes sat in Redis with nothing listening.
     *
     * `RenderProgress::stages()` already solved this: the scene count is the
     * denominator for a fan-out stage, and a stage total lower than it means
     * some scenes never ran a job. Any clip count on this page uses that, so
     * "112/270" cannot become "112/112".
     */
    public function test_a_clip_count_is_out_of_the_scene_count_not_the_row_count(): void
    {
        $story = $this->renderedStory(StoryStatus::Rendering);

        // Rows for a third of the scenes; the rest are still queued and have no
        // row at all, which is the state that produced the false success.
        foreach ($story->scenes()->orderBy('sequence')->limit(4)->get() as $scene) {
            RenderJob::query()->create([
                'story_id' => $story->id,
                'stage' => RenderStage::SceneClips,
                'status' => RenderJobStatus::Succeeded,
                'started_at' => now()->subMinute(),
                'finished_at' => now(),
                'output_path' => 'scene-'.$scene->sequence.'.mp4',
            ]);
        }

        $html = Livewire::test(PreviewGate::class, ['story' => $story])->html();

        $scenes = $story->scenes()->count();

        $this->assertMatchesRegularExpression(
            '#\b4\s*/\s*'.$scenes.'\b#',
            $html,
            sprintf(
                'A clip count must be out of the %d scenes this story has, not out of the %d rows '
                .'that happen to exist. A queued scene has no row, so the row count reports a '
                .'third of a run as the whole of it.',
                $scenes,
                4,
            ),
        );
    }

    /**
     * Act boundaries are placed on a scale that cannot leave the element.
     *
     * The mock draws them on the video scrubber. Nothing can put a marker on a
     * native `<video controls>` scrubber, so they render as their own strip —
     * same information, no custom player. Wherever they end up, a boundary
     * computed from `start_ms / duration` must be a percentage, and a story
     * whose act timings outrun its own duration must not push one off the end.
     */
    public function test_act_boundaries_stay_inside_their_own_scale(): void
    {
        $story = $this->renderedStory(StoryStatus::Rendered);

        // A boundary beyond the summed duration — the shape a partially
        // re-rendered story takes when one act keeps an older timing.
        $story->acts()->orderByDesc('sequence')->first()->forceFill([
            'start_ms' => 99_999_999,
        ])->save();

        $html = Livewire::test(PreviewGate::class, ['story' => $story->refresh()])->html();

        foreach ($this->markerPercents($html) as $percent) {
            $this->assertGreaterThanOrEqual(0.0, $percent);
            $this->assertLessThanOrEqual(100.0, $percent, 'An act boundary escaped its own scale.');
        }
    }

    // -- The rules every gate body carries -----------------------------------

    public function test_every_alert_carries_its_own_width(): void
    {
        foreach (StoryStatus::cases() as $status) {
            $html = Livewire::test(PreviewGate::class, ['story' => $this->renderedStory($status)])->html();

            $this->assertSame(
                [],
                PageProbe::alertsWithoutTheirOwnWidth($html),
                sprintf('An alert on Gate 3 at "%s" renders at the 96ch cap.', $status->value),
            );
        }
    }


    /**
     * Every scoped class on this page can actually be reached by its rule.
     *
     * THE ONE THAT CAUGHT A DEFECT IN THIS PAGE'S OWN FIRST BUILD, and the
     * reason Gate 2's version of it is worth copying rather than trusting a
     * clean audit. `class-audit` reports these as CONTEXT — a class that paints
     * nothing on its own and is only reachable under an ancestor — and CONTEXT
     * is its BENIGN verdict. It is benign only while the ancestor is really
     * there, and the audit reads the stylesheet and the markup separately, so it
     * cannot see that the pair never meets.
     *
     * `.measure` was `.alert.wide > .measure`. This page wrote it on prose
     * inside a `.panel`, and on a `.grow` one level below a wide alert. Three
     * of four usages matched nothing, the audit said CONTEXT for all of them,
     * and no test went red — `.warnfill` exactly.
     *
     * Checked against the RENDERED page, because that is the only place the
     * ancestor relationship is real.
     */
    public function test_every_scoped_class_reaches_its_rule(): void
    {
        $html = $this->everyStateOfThePage();

        // class => the ancestor chain its only rule needs, exactly as
        // class-audit reports it.
        $required = [
            'at' => '.windowbar .at',
            'axis' => '.windowbar .axis',
            'zone' => '.windowbar .zone',
            'tick' => '.actsrail .tick',
            'span2' => '.facts .span2',
            'right' => '.panelhead .right',
            'col' => '.previewcols .col',
        ];

        foreach ($required as $class => $selector) {
            $this->assertGreaterThan(
                0,
                PageProbe::matchCount($html, $selector),
                sprintf(
                    'Nothing on Gate 3 matches "%s", so every .%s on the page is unstyled. '
                    .'class-audit still calls this CONTEXT — it cannot see that the ancestor is gone.',
                    $selector,
                    $class,
                ),
            );
        }
    }

    /**
     * The rendered page across the states that between them show every class.
     *
     * One state cannot: the window bar and the act rail need a finished render,
     * the clip counter needs one in flight, and the strip needs a story past the
     * gate or short of it.
     */
    private function everyStateOfThePage(): string
    {
        return implode('', [
            Livewire::test(PreviewGate::class, ['story' => $this->renderedStory(StoryStatus::Rendered)])->html(),
            Livewire::test(PreviewGate::class, ['story' => $this->renderedStory(StoryStatus::Rendering)])->html(),
            Livewire::test(PreviewGate::class, ['story' => $this->renderedStory(StoryStatus::Published)])->html(),
            Livewire::test(PreviewGate::class, ['story' => $this->renderedStory(StoryStatus::ScenesDrafted)])->html(),
        ]);
    }

    // -- Fixtures ------------------------------------------------------------

    /**
     * Every percentage this page places an absolutely-positioned mark at.
     *
     * Read from the markup rather than from the component, because a marker is
     * only wrong once it is a `left:` on an element — a computed value that is
     * clamped on its way out is what this asserts.
     *
     * @return array<int, float>
     */
    private function markerPercents(string $html): array
    {
        preg_match_all('/left:\s*(-?[\d.]+)%/', $html, $matches);

        return array_map('floatval', $matches[1]);
    }

    private function renderedStory(
        StoryStatus $status,
        int $durationMs = 2_100_000,
        int $acts = 3,
        int $scenesPerAct = 4,
    ): Story {
        $story = Story::factory()->status($status)->create([
            'slug' => 'g3-'.strtolower($status->value).'-'.$durationMs,
            'target_duration_min' => 30,
            'target_duration_max' => 40,
        ]);

        app(Generator::class)->unique(reset: true);

        $per = intdiv($durationMs, $acts);

        for ($i = 1; $i <= $acts; $i++) {
            $act = Act::factory()->for($story)->atSequence($i)->create([
                'start_ms' => $per * ($i - 1),
                'duration_ms' => $i === $acts ? $durationMs - $per * ($acts - 1) : $per,
            ]);

            for ($n = 1; $n <= $scenesPerAct; $n++) {
                Scene::factory()->forAct($act)->atSequence(($i - 1) * $scenesPerAct + $n)->create([
                    'motion_preset' => MotionPreset::Static,
                    'duration_ms' => intdiv($per, $scenesPerAct),
                ]);
            }
        }

        RenderJob::query()->create([
            'story_id' => $story->id,
            'stage' => RenderStage::Mux,
            'status' => RenderJobStatus::Succeeded,
            'started_at' => now()->subMinutes(12),
            'finished_at' => now(),
            'log' => "mux: done in 11m 42s\n",
            'output_path' => 'final.mp4',
        ]);

        $workspace = RenderWorkspace::for($story);
        $workspace->ensureExists();
        file_put_contents($workspace->path('final.mp4'), str_repeat('0', 2048));

        return $story->refresh();
    }
}
