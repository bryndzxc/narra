<?php

namespace App\Support;

/**
 * Which model runs which call, in a form a human can read before spending.
 *
 * The pipeline stopped being one model per story the moment the four text
 * calls were priced separately, and a confirmation prompt that still says
 * "against claude-opus-5" is then actively lying about what is being
 * authorised. This is the one place that answers "what is about to run".
 *
 * It reads the same config the provider dispatches on, rather than keeping its
 * own list. A roster that could disagree with the router would be worse than
 * no roster: it would be a confirmation screen that reassures you about a
 * model the call is not using.
 *
 * **Every method takes the operations the CALLER is about to run.** Not the
 * whole roster. `story:write` makes four calls and `metadata:generate` makes
 * three different ones, and a confirmation that listed all seven would be the
 * same lie in the other direction — naming models the command will not touch.
 */
class ModelRoster
{
    /** The calls that write the story, in the order they run. */
    public const SCRIPT_OPERATIONS = [
        'generate_outline',
        'generate_act_script',
        'extract_characters',
        'draft_scenes',
    ];

    /** The calls that write the publish sheet, in the order they run. */
    public const METADATA_OPERATIONS = [
        'generate_titles',
        'generate_copy',
        'generate_tags',
    ];

    /**
     * @param  array<int, string>|null  $operations  Null for every assigned operation.
     * @return array<string, string> operation => model
     */
    public function all(?array $operations = null): array
    {
        $configured = (array) config('providers.anthropic.operations', []);

        if ($operations !== null) {
            $configured = array_intersect_key($configured, array_flip($operations));
        }

        return array_map(
            fn (array $config): string => (string) $config['model'],
            $configured
        );
    }

    public function for(string $operation): ?string
    {
        return $this->all()[$operation] ?? null;
    }

    /**
     * The distinct models these operations will bill against, cheapest name
     * first.
     *
     * @param  array<int, string>|null  $operations
     * @return array<int, string>
     */
    public function distinct(?array $operations = null): array
    {
        $models = array_values(array_unique($this->all($operations)));
        sort($models);

        return $models;
    }

    /**
     * One line per stage, for a console confirmation.
     *
     * Stage names rather than operation keys, because the operator thinks in
     * "the outline" and "the acts", not in `generate_act_script`.
     *
     * @param  array<int, string>|null  $operations
     * @return array<int, string>
     */
    public function lines(?array $operations = null): array
    {
        $labels = [
            'generate_premises' => 'premises',
            'generate_outline' => 'outline',
            'generate_act_script' => 'act scripts',
            'extract_characters' => 'cast',
            'draft_scenes' => 'scene drafts',
            'generate_titles' => 'titles',
            'generate_copy' => 'thumb/comment',
            'generate_tags' => 'tags',
        ];

        $lines = [];

        foreach ($this->all($operations) as $operation => $model) {
            $label = $labels[$operation] ?? $operation;

            $fallback = config("providers.anthropic.operations.{$operation}.fallback");

            $lines[] = sprintf(
                '%-13s %s%s',
                $label,
                $model,
                // Named up front rather than discovered in the ledger. A
                // fallback that fires is a second billed call for the same act,
                // and an operator who was never told it exists reads that row
                // as a duplicate.
                $fallback ? "  (falls back to {$fallback} if the sentence ranges do not tile)" : '',
            );
        }

        return $lines;
    }

    /**
     * Compact form for a one-line warning.
     *
     * @param  array<int, string>|null  $operations
     */
    public function summary(?array $operations = null): string
    {
        return implode(', ', $this->distinct($operations));
    }
}
