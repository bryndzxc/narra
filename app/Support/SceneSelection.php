<?php

namespace App\Support;

use App\Models\Scene;
use InvalidArgumentException;

/**
 * A subset of a story's scenes, named the way the operator names them.
 *
 * This exists so the first run against a newly-bound provider can be five
 * scenes instead of a hundred and eighty-six. Two providers running together
 * for the first time is exactly when a small run earns its keep — and the
 * failure this project has already paid for once is an app reporting success
 * while nothing reached the vendor, which a five-scene run surfaces for the
 * price of five scenes.
 *
 * **By SEQUENCE, not by id.** The operator reads sequence numbers at Gate 2 and
 * on the render page; ids are an implementation detail they have never seen.
 * The cost is that a reorder changes what `1-5` means — which is correct, since
 * a reorder changes what scene 1 *is*.
 *
 * Immutable and made of plain integers on purpose: it is captured into a queued
 * batch callback, so it has to survive serialisation without dragging a model
 * or a query builder along with it.
 */
final class SceneSelection
{
    /**
     * @param  array<int, int>  $sequences
     */
    private function __construct(public readonly array $sequences) {}

    /**
     * Parse an operator's shorthand: `3`, `1-5`, `1,3,7`, `1-5,10,20-22`.
     *
     * Refuses anything it does not fully understand rather than salvaging the
     * parts it does. A typo that silently narrowed a run would be discovered as
     * a story with holes in it, long after the allowance had been spent
     * elsewhere; a typo that silently WIDENED one would be discovered on the
     * bill. Neither is worth being forgiving for.
     */
    public static function parse(string $expression): self
    {
        $sequences = [];

        foreach (explode(',', trim($expression)) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (preg_match('/^(\d+)$/', $part, $m)) {
                $sequences[] = (int) $m[1];

                continue;
            }

            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
                [$from, $to] = [(int) $m[1], (int) $m[2]];

                if ($from > $to) {
                    throw new InvalidArgumentException(
                        "Scene range \"{$part}\" runs backwards. Write it low-to-high, as {$to}-{$from}."
                    );
                }

                $sequences = [...$sequences, ...range($from, $to)];

                continue;
            }

            throw new InvalidArgumentException(
                "Could not read \"{$part}\" as a scene selection. Use a number (7), a range (1-5), or a "
                .'comma-separated mix of both (1-5,10,20-22).'
            );
        }

        if ($sequences === []) {
            throw new InvalidArgumentException('The scene selection is empty.');
        }

        sort($sequences);

        return new self(array_values(array_unique($sequences)));
    }

    public function matches(Scene $scene): bool
    {
        return in_array((int) $scene->sequence, $this->sequences, true);
    }

    public function count(): int
    {
        return count($this->sequences);
    }

    /**
     * The sequences asked for that the story does not have.
     *
     * Surfaced rather than ignored: `--scenes=1-200` on a 186-scene story is
     * more likely a misremembered length than an intention, and silently
     * running 186 of it teaches the operator that the flag is approximate.
     *
     * @param  array<int, int>  $available
     * @return array<int, int>
     */
    public function missingFrom(array $available): array
    {
        return array_values(array_diff($this->sequences, $available));
    }

    /** Compact, for a confirmation line: `1-5`, `1-5, 10`, `3`. */
    public function describe(): string
    {
        $parts = [];
        $start = $previous = null;

        foreach ([...$this->sequences, null] as $sequence) {
            if ($start === null) {
                $start = $previous = $sequence;

                continue;
            }

            if ($sequence === $previous + 1) {
                $previous = $sequence;

                continue;
            }

            $parts[] = $start === $previous ? (string) $start : "{$start}-{$previous}";
            $start = $previous = $sequence;
        }

        return implode(', ', $parts);
    }
}
