<?php

namespace Tests\Feature;

use App\Enums\FailureKind;
use App\Enums\StoryStatus;
use App\Models\Story;
use App\Support\FailureRemedy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Finder\Finder;
use Tests\Support\NamedKnobs;
use Tests\TestCase;

/**
 * EVERY COMMAND, FLAG AND ENV VAR THIS APP TELLS AN OPERATOR ABOUT EXISTS.
 *
 * Two pieces of advice cost real runs by naming things that were not there:
 * "Raise ANTHROPIC_MAX_TOKENS" (no config reads it) and "confirm with `php
 * artisan providers:show`" (no such command, in the message behind 328 failed
 * alignments). Both were specific, confident, and a dead end.
 *
 * The sources, widest first:
 *
 *  1. Every string literal in app/ and config/. A superset of the remedies, on
 *     purpose: advice has lived in exception messages, in config, in a job's
 *     refusal and in a console hint, and the next copy will not be where the
 *     last one was.
 *  2. Every Blade view, with Blade comments removed.
 *  3. Every remedy `FailureRemedy` can produce, for every failure kind at every
 *     status — the text is assembled at display time, so no literal holds it
 *     whole.
 *  4. Every configured `truncation_remedy`, resolved, because a sentence in
 *     config is a concatenation and its halves are only a sentence together.
 *
 * And the detector is tested on known inputs first, both ways, because a check
 * that can only be pointed at the live tree can only ever pass it.
 */
class RemediesNameRealKnobsTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------------
    // The detector, on known answers. RED then GREEN, as close as possible.
    // ---------------------------------------------------------------------

    public function test_red_a_command_that_does_not_exist(): void
    {
        $this->assertSame(
            ['command providers:show does not exist'],
            NamedKnobs::missing('Confirm with `php artisan providers:show`, which runs a real alignment.', $this->commands(), $this->envNames()),
        );
    }

    public function test_green_a_command_that_exists(): void
    {
        $this->assertSame(
            [],
            NamedKnobs::missing('Confirm with `php artisan narration:preflight`.', $this->commands(), $this->envNames()),
        );
    }

    public function test_red_a_real_command_with_a_flag_it_does_not_have(): void
    {
        // Written bare, the way the act-bound refusal wrote it: no backticks,
        // no "php artisan". The namespace is what makes it a command.
        $this->assertSame(
            ['story:write has no --acts-onyl option'],
            NamedKnobs::missing('re-run this act (story:write --acts-onyl=5).', $this->commands(), $this->envNames()),
        );
    }

    public function test_green_a_real_command_with_a_real_flag(): void
    {
        $this->assertSame(
            [],
            NamedKnobs::missing('re-run this act (story:write --acts-only=5).', $this->commands(), $this->envNames()),
        );
    }

    public function test_red_an_env_var_nothing_reads(): void
    {
        $this->assertSame(
            ['env var ANTHROPIC_MAX_TOKENS is read by nothing'],
            NamedKnobs::missing('Raise ANTHROPIC_MAX_TOKENS or lower the per-act word target.', $this->commands(), $this->envNames()),
        );
    }

    public function test_green_an_env_var_config_reads_and_a_php_constant_that_is_not_one(): void
    {
        $this->assertSame(
            [],
            NamedKnobs::missing('Check ANTHROPIC_EFFORT_OUTLINE; decoded with JSON_THROW_ON_ERROR.', $this->commands(), $this->envNames()),
        );
    }

    public function test_green_a_bare_colon_in_a_namespace_nothing_uses_is_not_a_command(): void
    {
        $this->assertSame(
            [],
            NamedKnobs::missing('Batch scene-clips:rent-will, note: nothing here.', $this->commands(), $this->envNames()),
        );
    }

    /** Found by the first run over the views: a component tag is not a command. */
    public function test_green_a_livewire_component_tag_is_not_a_command(): void
    {
        $this->assertSame(
            [],
            NamedKnobs::missing('<livewire:gates.outline-gate :story="$story" /> </livewire:dashboard>', $this->commands(), $this->envNames()),
        );
    }

    public function test_red_advice_to_raise_a_ceiling(): void
    {
        $this->assertTrue(NamedKnobs::advisesRaisingACeiling('Re-run once, then raise ANTHROPIC_MAX_TOKENS_TITLES.'));
        $this->assertTrue(NamedKnobs::advisesRaisingACeiling('Raise the ceiling if it happens again.'));
    }

    public function test_green_advice_not_to_raise_a_ceiling(): void
    {
        $this->assertFalse(NamedKnobs::advisesRaisingACeiling(
            'record it before retrying, and do not raise ANTHROPIC_MAX_TOKENS_OUTLINE to absorb it'
        ));
        $this->assertFalse(NamedKnobs::advisesRaisingACeiling('The ceiling stays at 16,000.'));
    }

    public function test_red_green_a_sentence_split_across_concatenated_literals_is_read_whole(): void
    {
        $red = "<?php \$x = 'then raise '\n    .'ANTHROPIC_MAX_TOKENS_ACT_SCRIPT. Note';";
        $green = "<?php \$x = 'then raise '.\$y.'ANTHROPIC_MAX_TOKENS_ACT_SCRIPT';";

        $this->assertTrue(NamedKnobs::advisesRaisingACeiling(NamedKnobs::phpStrings($red)[0]['text']));
        // A variable between the halves ends the join: the text there is unknown.
        $this->assertCount(2, NamedKnobs::phpStrings($green));
    }

    // ---------------------------------------------------------------------
    // The live tree.
    // ---------------------------------------------------------------------

    public function test_no_string_in_app_or_config_names_a_knob_that_does_not_exist(): void
    {
        $finder = Finder::create()->files()->name('*.php')->in([app_path(), config_path()]);
        $problems = [];

        foreach ($finder as $file) {
            foreach (NamedKnobs::phpStrings($file->getContents()) as $string) {
                foreach (NamedKnobs::missing($string['text'], $this->commands(), $this->envNames()) as $problem) {
                    $problems[] = sprintf('%s:%d — %s', $this->relative($file->getPathname()), $string['line'], $problem);
                }
            }
        }

        $this->assertSame([], $problems, "Advice names things that are not there:\n".implode("\n", $problems));
    }

    public function test_no_view_names_a_knob_that_does_not_exist(): void
    {
        $finder = Finder::create()->files()->name('*.blade.php')->in(resource_path('views'));
        $problems = [];

        foreach ($finder as $file) {
            $text = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());

            foreach (NamedKnobs::missing($text, $this->commands(), $this->envNames()) as $problem) {
                $problems[] = $this->relative($file->getPathname()).' — '.$problem;
            }
        }

        $this->assertSame([], $problems, "A view names things that are not there:\n".implode("\n", $problems));
    }

    public function test_every_truncation_remedy_names_real_knobs_and_never_says_raise_the_ceiling(): void
    {
        foreach ((array) config('providers.anthropic.operations') as $operation => $config) {
            $remedy = (string) ($config['truncation_remedy'] ?? '');

            $this->assertSame([], NamedKnobs::missing($remedy, $this->commands(), $this->envNames()), $operation);
            $this->assertFalse(
                NamedKnobs::advisesRaisingACeiling($remedy),
                "'{$operation}' tells the operator to raise its ceiling. A truncated call is billed at the "
                .'ceiling, so that makes the failure dearer; where it was measured, reasoning was filling it. '
                .'An operation with no measured repair has a null remedy and the page says "No known repair."',
            );
        }
    }

    /**
     * Every remedy the page can show, for every kind, at every status.
     *
     * Statuses matter because the remedy names the action the story can
     * actually take there, and a different status is a different sentence.
     */
    public function test_every_remedy_the_page_can_show_names_real_knobs(): void
    {
        $story = Story::factory()->create(['slug' => 'knob-probe']);
        $problems = [];

        foreach (FailureKind::cases() as $kind) {
            foreach ($this->factSets() as $facts) {
                foreach (StoryStatus::cases() as $status) {
                    $story->status = $status;
                    $remedy = FailureRemedy::for($kind, $facts, $story);

                    $text = implode("\n", array_filter([$remedy->text, $remedy->unmeasured, $remedy->command]));

                    foreach (NamedKnobs::missing($text, $this->commands(), $this->envNames()) as $problem) {
                        $problems[] = "{$kind->value} at {$status->value}: {$problem}";
                    }

                    if (NamedKnobs::advisesRaisingACeiling($text)) {
                        $problems[] = "{$kind->value} at {$status->value}: advises raising a ceiling";
                    }

                    if (! $remedy->known) {
                        $this->assertNull($remedy->text, "{$kind->value}: an unknown repair must carry no text, not a softer suggestion.");
                        $this->assertNull($remedy->command, $kind->value);
                        $this->assertNull($remedy->actionUrl, $kind->value);
                    }
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * UNCLASSIFIED IS UNKNOWN AT EVERY STATUS, AND SO ARE THE KNOWN KINDS ON
     * THE FACTS THAT HAVE NO MEASURED REPAIR.
     *
     * The existence checks above cannot see a softened guess — "try re-running
     * the stage" names no knob at all — so this pins the cases where the
     * answer is "No known repair." and must stay that.
     */
    public function test_what_has_no_known_repair_stays_unknown(): void
    {
        $story = Story::factory()->create();

        // A locale refusal on the cast and a decline on the scenes used to be
        // pinned here. They moved on 2026-09-19 into the certain-move test
        // below: running the stage again is the only move either has, and "No
        // known repair." beside that button told the operator no move
        // existed. A truncation stays here — the outline's ceiling position
        // names effort as a second lever, so a re-run is not the only move.
        $unknown = [
            [FailureKind::Unclassified, []],
            [FailureKind::VendorTimeout, ['stage' => 'scene_narration']],
            [FailureKind::Truncated, ['operation' => 'generate_titles']],
            // An asset stage is not a text call and has no re-run of this kind.
            [FailureKind::LocaleRefused, ['stage' => 'images']],
            [FailureKind::OutputRefused, ['stage' => 'images', 'check' => 'scene_bounds']],
            [FailureKind::OutputRefused, []],
            [FailureKind::ModelDeclined, ['operation' => 'not_an_operation']],
        ];

        foreach ($unknown as [$kind, $facts]) {
            foreach (StoryStatus::cases() as $status) {
                $story->status = $status;
                $this->assertFalse(
                    FailureRemedy::for($kind, $facts, $story)->known,
                    "{$kind->value} ".json_encode($facts)." at {$status->value} offers a repair nobody has measured.",
                );
            }
        }
    }

    /**
     * A CERTAIN MOVE WITH AN UNMEASURED OUTCOME NAMES THE BUTTON AND SAYS SO.
     *
     * The second of FailureKind's three cases, pinned both ways: never "No
     * known repair." (story 37's outline page, 2026-09-18, beside the one
     * button that repairs it), and never the button without the caveat,
     * which would be the re-run advice that cost runs. At every status, the
     * caveat stays; the button is offered only where Write is permitted.
     */
    public function test_a_certain_move_with_an_unmeasured_outcome_names_the_button_and_says_so(): void
    {
        $story = Story::factory()->create();

        $unmeasured = [[FailureKind::ModelDeclined, ['operation' => 'generate_outline']]];

        foreach (array_keys(FailureKind::OUTLINE_CHECKS) as $check) {
            $unmeasured[] = [FailureKind::OutlineRefused, ['check' => $check]];
        }

        foreach ($unmeasured as [$kind, $facts]) {
            foreach (StoryStatus::cases() as $status) {
                $story->status = $status;
                $remedy = FailureRemedy::for($kind, $facts, $story);
                $where = "{$kind->value} ".json_encode($facts)." at {$status->value}";

                $this->assertTrue($remedy->known, "{$where} says no move exists.");
                $this->assertNotEmpty($remedy->unmeasured, "{$where} names the move without saying its outcome is unmeasured.");
                $this->assertSame(
                    \App\Enums\OperatorAction::WriteScript->permittedAt($status),
                    $remedy->actionUrl !== null,
                    "{$where}: the button must be offered exactly where Write is permitted.",
                );
            }
        }

        // An unknown check still gets the move: the check is a noun, not a condition.
        $this->assertNotNull(FailureRemedy::for(FailureKind::OutlineRefused, ['check' => 'nope'], $story)->unmeasured);
    }

    /**
     * THE WHOLE FAMILY, NOT ONE STAGE AT A TIME.
     *
     * Every text stage after the outline whose billed output can be refused
     * or declined, storing nothing, gets the same shape the outline got on
     * 2026-09-18: the move that runs it again, and a sentence saying its
     * outcome is unmeasured. It arrived one stage per failure until
     * 2026-09-19 (the outline, then act-script chapter shapes, then story
     * 38's cast), each reaching the page as "No known repair."
     *
     * The stage-to-action table is written out here rather than read from
     * FailureRemedy, so a stage mapped to the wrong button fails instead of
     * agreeing with itself.
     */
    public function test_every_refused_text_stage_names_its_button_and_says_the_outcome_is_unmeasured(): void
    {
        $story = Story::factory()->create();

        $actionFor = [
            'premises' => \App\Enums\OperatorAction::WritePremises,
            'act_scripts' => \App\Enums\OperatorAction::WriteScript,
            'extract_cast' => \App\Enums\OperatorAction::DraftSceneList,
            'draft_scenes' => \App\Enums\OperatorAction::DraftSceneList,
            'metadata' => \App\Enums\OperatorAction::WriteMetadata,
        ];

        $cases = [];

        foreach (array_keys(FailureKind::OUTPUT_CHECKS) as $check) {
            foreach (array_keys($actionFor) as $stage) {
                $cases[] = [FailureKind::OutputRefused, ['stage' => $stage, 'check' => $check, 'act' => 2], $stage];
            }
        }

        foreach (['generate_premises' => 'premises', 'generate_act_script' => 'act_scripts',
            'extract_characters' => 'extract_cast', 'draft_scenes' => 'draft_scenes',
            'generate_titles' => 'metadata', 'generate_copy' => 'metadata', 'generate_tags' => 'metadata'] as $operation => $stage) {
            $cases[] = [FailureKind::ModelDeclined, ['operation' => $operation], $stage];
        }

        // A cast description or a scene frame still refuses a denied term.
        $cases[] = [FailureKind::LocaleRefused, ['stage' => 'extract_cast'], 'extract_cast'];
        $cases[] = [FailureKind::LocaleRefused, ['stage' => 'draft_scenes'], 'draft_scenes'];

        foreach ($cases as [$kind, $facts, $stage]) {
            foreach (StoryStatus::cases() as $status) {
                $story->status = $status;
                $remedy = FailureRemedy::for($kind, $facts, $story);
                $where = "{$kind->value} ".json_encode($facts)." at {$status->value}";

                $this->assertTrue($remedy->known, "{$where} says no move exists.");
                $this->assertNotEmpty($remedy->unmeasured, "{$where} names the move without saying its outcome is unmeasured.");
                $this->assertSame(
                    $actionFor[$stage]->permittedAt($status),
                    $remedy->actionUrl !== null,
                    "{$where}: the button must be offered exactly where {$actionFor[$stage]->name} is permitted.",
                );

                if ($remedy->actionUrl !== null) {
                    $this->assertSame($actionFor[$stage]->label(), $remedy->actionLabel, $where);
                }
            }
        }
    }

    /**
     * The stage comes from the row when the exception could not say it: a
     * locale refusal carries no facts, so the page hands over the row's stage.
     */
    public function test_red_green_a_locale_refusal_is_placed_by_the_rows_stage(): void
    {
        $story = Story::factory()->create(['status' => StoryStatus::Scripted]);

        $this->assertFalse(FailureRemedy::for(FailureKind::LocaleRefused, [], $story)->known);
        $this->assertNotNull(FailureRemedy::for(FailureKind::LocaleRefused, [], $story, 'extract_cast')->actionUrl);
    }

    /**
     * RED/GREEN: a cast refused on a REBUILD left the old cast standing, and
     * the draft button would keep that cast rather than extract, so offering
     * it as the repair for the refusal would be false.
     */
    public function test_red_green_a_refused_extraction_on_a_story_that_kept_its_cast_offers_no_button(): void
    {
        $story = Story::factory()->create(['status' => StoryStatus::Scripted]);
        $facts = ['stage' => 'extract_cast', 'check' => 'character_text'];

        $this->assertNotNull(FailureRemedy::for(FailureKind::OutputRefused, $facts, $story)->actionUrl);

        \App\Models\Character::factory()->for($story)->create();
        $remedy = FailureRemedy::for(FailureKind::OutputRefused, $facts, $story->fresh());

        $this->assertTrue($remedy->known);
        $this->assertNull($remedy->actionUrl);
        $this->assertNull($remedy->unmeasured);
        $this->assertStringContainsString('still on the story', (string) $remedy->text);
    }

    /**
     * RED/GREEN: once the story has acts, an outline was written after the
     * failure and Write writes act scripts, so offering it as the repair for
     * the old refusal would be false. No action, and no caveat about a move
     * that is no longer on offer.
     */
    public function test_red_green_an_outline_refusal_on_a_story_that_now_has_acts_offers_no_write(): void
    {
        $story = Story::factory()->create(['status' => StoryStatus::Outlined]);
        $facts = ['check' => 'cast_structure'];

        $this->assertNotNull(FailureRemedy::for(FailureKind::OutlineRefused, $facts, $story)->actionUrl);

        \App\Models\Act::factory()->for($story)->create(['sequence' => 1]);
        $remedy = FailureRemedy::for(FailureKind::OutlineRefused, $facts, $story->fresh());

        $this->assertTrue($remedy->known);
        $this->assertNull($remedy->actionUrl);
        $this->assertNull($remedy->unmeasured);
        $this->assertStringContainsString('nothing of it left to repair', (string) $remedy->text);
    }

    public function test_an_unmeasured_move_cannot_be_built_without_saying_what_is_unmeasured(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        \App\Support\Remedy::unmeasuredMove('Press the button.', '  ');
    }

    // ---------------------------------------------------------------------

    /**
     * Fact sets covering every key a kind reads, including one per text
     * operation so each truncation remedy is rendered.
     *
     * @return array<int, array<string, mixed>>
     */
    private function factSets(): array
    {
        $base = ['act' => 3, 'scene' => 12, 'queue' => (string) config('render.queues.assets'), 'stage' => 'images'];

        $sets = [$base];

        foreach (array_keys((array) config('providers.anthropic.operations')) as $operation) {
            $sets[] = $base + ['operation' => $operation];
        }

        foreach (['text', 'assets', 'render'] as $role) {
            $sets[] = ['queue' => (string) config("render.queues.{$role}")] + $base;
        }

        foreach (array_keys(FailureKind::OUTLINE_CHECKS) as $check) {
            $sets[] = $base + ['check' => $check];
        }

        // Every text stage a refused output can come from, so each stage's
        // command is rendered and checked to exist.
        foreach (['premises', 'act_scripts', 'extract_cast', 'draft_scenes', 'metadata'] as $stage) {
            foreach (array_keys(FailureKind::OUTPUT_CHECKS) as $check) {
                $sets[] = ['stage' => $stage, 'check' => $check] + $base;
            }
        }

        return $sets;
    }

    /** @return array<string, array<int, string>> command name => option names */
    private function commands(): array
    {
        static $commands = null;

        if ($commands === null) {
            $commands = [];

            foreach (Artisan::all() as $name => $command) {
                $commands[$name] = array_keys($command->getDefinition()->getOptions());
            }
        }

        return $commands;
    }

    /**
     * Every env var the app reads, from the source rather than a list.
     *
     * @return array<int, string>
     */
    private function envNames(): array
    {
        static $names = null;

        if ($names === null) {
            $names = [];
            $finder = Finder::create()->files()->name('*.php')->in([config_path(), app_path()]);

            foreach ($finder as $file) {
                preg_match_all('/\benv\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/', $file->getContents(), $matches);
                array_push($names, ...$matches[1]);
            }

            $names = array_values(array_unique($names));
        }

        return $names;
    }

    private function relative(string $path): string
    {
        return str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);
    }
}
