<?php

namespace Tests\Unit;

use App\Enums\CostCategory;
use App\Enums\CostUnit;
use App\Support\ModelRoster;
use App\Support\Providers\ProviderUsage;
use App\Support\Providers\SceneDraft;
use App\Support\Providers\SceneDraftSet;
use Tests\TestCase;

/**
 * The pipeline runs on three models now, and the split introduced two failure
 * modes that are silent in exactly the wrong way.
 *
 * Both are config-shaped rather than code-shaped, which is why they are pinned
 * here: a wrong value in providers.php does not fail to parse, it fails on the
 * next real story, after the outline has already been paid for.
 */
class ModelRoutingTest extends TestCase
{
    /**
     * Models that accept `output_config.effort`.
     *
     * Not a stylistic list. Effort is a hard 400 on the 4.5 generation — Haiku
     * 4.5 and Sonnet 4.5 reject it outright — so sending it to the model that
     * drafts scenes would fail every scene call in a story, one per act, after
     * the outline and all the act scripts had been billed.
     */
    private const EFFORT_CAPABLE = [
        'claude-opus-5',
        'claude-sonnet-5',
        'claude-fable-5',
        'claude-opus-4-8',
        'claude-opus-4-7',
        'claude-opus-4-6',
        'claude-sonnet-4-6',
    ];

    public function test_no_operation_sends_effort_to_a_model_that_rejects_it(): void
    {
        foreach (config('providers.anthropic.operations') as $operation => $config) {
            $effort = $config['effort'] ?? null;

            if ($effort === null || $effort === '') {
                continue;
            }

            $this->assertContains(
                $config['model'],
                self::EFFORT_CAPABLE,
                sprintf(
                    "Operation '%s' sends effort '%s' to %s, which rejects it with a 400. This does "
                    .'not degrade the output, it fails the call — and for a per-act operation that '
                    .'is every act, after the outline has been billed.',
                    $operation,
                    $effort,
                    $config['model'],
                )
            );
        }
    }

    /**
     * An effort value the API does not know is a 400, exactly like sending one
     * to a model that rejects it — and it fails in the same place, per act,
     * after everything upstream has been billed.
     *
     * The list is the API's, not ours, which is what stops this being a test
     * of a literal against itself: it catches a typo in a value config is free
     * to set to anything. Worth having the moment a level is edited by hand,
     * which `fallback_effort` just was.
     */
    private const EFFORT_LEVELS = ['low', 'medium', 'high', 'xhigh', 'max'];

    public function test_every_configured_effort_is_a_level_the_api_accepts(): void
    {
        foreach (config('providers.anthropic.operations') as $operation => $config) {
            foreach (['effort', 'fallback_effort'] as $key) {
                $effort = $config[$key] ?? null;

                if ($effort === null || $effort === '') {
                    continue;
                }

                $this->assertContains(
                    $effort,
                    self::EFFORT_LEVELS,
                    sprintf("Operation '%s' sets %s to '%s', which is not an effort level.", $operation, $key, $effort),
                );
            }
        }
    }

    public function test_the_scene_fallback_model_also_respects_the_effort_rule(): void
    {
        $fallback = config('providers.anthropic.operations.draft_scenes.fallback');
        $effort = config('providers.anthropic.operations.draft_scenes.fallback_effort');

        if ($fallback === null || $effort === null || $effort === '') {
            $this->markTestSkipped('No fallback effort configured.');
        }

        // The fallback is the recovery path. A 400 here means a story that
        // could have been rescued fails twice and bills twice.
        $this->assertContains($fallback, self::EFFORT_CAPABLE);
    }

    public function test_every_model_the_pipeline_can_call_has_a_rate_card(): void
    {
        $priced = array_keys((array) config('providers.anthropic.pricing'));

        foreach (config('providers.anthropic.operations') as $operation => $config) {
            $this->assertContains(
                $config['model'],
                $priced,
                "Operation '{$operation}' runs on {$config['model']}, which has no rate card. A paid "
                .'call recorded at $0.00 breaks the one question cost_entries exists to answer, and '
                .'breaks it silently.'
            );

            if (($config['fallback'] ?? null) !== null) {
                $this->assertContains($config['fallback'], $priced);
            }
        }
    }

    public function test_a_cheaper_model_is_not_priced_at_the_expensive_one_s_rate(): void
    {
        // The specific way a split like this reports a saving that never
        // happened: one rate card shared by three models.
        $opus = config('providers.anthropic.pricing.claude-opus-5');
        $sonnet = config('providers.anthropic.pricing.claude-sonnet-5');
        $haiku = config('providers.anthropic.pricing.claude-haiku-4-5');

        $this->assertLessThan($opus['output_per_mtok'], $sonnet['output_per_mtok']);
        $this->assertLessThan($sonnet['output_per_mtok'], $haiku['output_per_mtok']);

        // Cache read is a tenth of input on every model. It is the rate the act
        // calls spend most of their input at, so getting it wrong misprices the
        // bulk of the run.
        foreach ([$opus, $sonnet, $haiku] as $card) {
            $this->assertEqualsWithDelta($card['input_per_mtok'] * 0.1, $card['cache_read_per_mtok'], 0.001);
            $this->assertEqualsWithDelta($card['input_per_mtok'] * 1.25, $card['cache_write_per_mtok'], 0.001);
        }
    }

    public function test_the_outline_stays_on_the_most_capable_model(): void
    {
        // The one call whose output every later call is written against, and
        // the cheapest place in the pipeline to be generous: one call per video.
        $this->assertSame('claude-opus-5', config('providers.anthropic.operations.generate_outline.model'));
    }

    public function test_a_discarded_attempt_still_owes_a_cost_row(): void
    {
        $discarded = $this->usage('claude-haiku-4-5', 0.0021);
        $kept = $this->usage('claude-sonnet-5', 0.0140);

        $set = new SceneDraftSet(
            [new SceneDraft(firstSentence: 1, lastSentence: 4, frame: 'A kitchen at dusk.')],
            $kept,
            [$discarded],
        );

        // Both, in the order they were made. A fallback that reported only the
        // winner would understate the run by the cost of the attempt it threw
        // away — and would make the split look cheaper than it is.
        $this->assertSame([$discarded, $kept], $set->allUsages());
        $this->assertEqualsWithDelta(0.0161, array_sum(array_map(
            fn (ProviderUsage $u): float => $u->usdCost,
            $set->allUsages()
        )), 0.00001);
    }

    public function test_a_clean_run_owes_exactly_one_row(): void
    {
        $usage = $this->usage('claude-haiku-4-5', 0.0021);

        $set = new SceneDraftSet(
            [new SceneDraft(firstSentence: 1, lastSentence: 4, frame: 'A kitchen at dusk.')],
            $usage,
        );

        $this->assertSame([$usage], $set->allUsages());
    }

    public function test_the_roster_reads_the_same_config_the_router_dispatches_on(): void
    {
        // A confirmation screen that could disagree with the router is worse
        // than none: it reassures the operator about a model the call is not
        // going to use.
        $roster = new ModelRoster;

        foreach (config('providers.anthropic.operations') as $operation => $config) {
            $this->assertSame($config['model'], $roster->for($operation));
        }

        $lines = implode("\n", $roster->lines());

        $this->assertStringContainsString('outline', $lines);
        $this->assertStringContainsString('falls back to', $lines);
    }

    private function usage(string $model, float $usd): ProviderUsage
    {
        return new ProviderUsage(
            provider: 'anthropic',
            operation: 'draft_scenes',
            category: CostCategory::Text,
            quantity: 1000.0,
            unit: CostUnit::OutputTokens,
            usdCost: $usd,
            detail: ['model' => $model],
        );
    }
}
