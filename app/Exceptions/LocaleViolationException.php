<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Generated text carries idiom that does not belong to the target locale.
 *
 * Loud on purpose, and a job failure rather than a warning. The channel is
 * operated from the Philippines for a United States audience, and a single
 * "sari-sari store" or "ay naku" in 7,000 words is exactly the kind of thing an
 * operator reading an act at Gate 1 will scroll straight past — and exactly the
 * kind of thing a US viewer will notice immediately.
 *
 * Passing it through to Gate 1 with a note would make the check advisory, and
 * an advisory check on a 7,000-word review is not a check. Failing the stage
 * costs one act's worth of tokens to redo; shipping it costs the video.
 */
class LocaleViolationException extends RuntimeException
{
    /**
     * @param  array<int, array{term: string, context: string}>  $hits
     */
    public function __construct(
        public readonly string $localeProfile,
        public readonly string $stage,
        public readonly array $hits,
    ) {
        parent::__construct($this->compose());
    }

    private function compose(): string
    {
        $lines = array_map(
            fn (array $hit): string => sprintf('  "%s" — ...%s...', $hit['term'], $hit['context']),
            $this->hits
        );

        return sprintf(
            "%s produced text that is not %s. %d denied term(s):\n%s\n\n"
            .'The stage failed rather than passing this to Gate 1: at 7,000 words an operator will not '
            .'reliably spot one leaked idiom, and a US audience will. Re-run the stage — the locale '
            .'guidance is in config/locale.php under the story\'s locale_profile.',
            ucfirst(str_replace('_', ' ', $this->stage)),
            $this->localeProfile,
            count($this->hits),
            implode("\n", $lines)
        );
    }
}
