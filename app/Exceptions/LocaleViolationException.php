<?php

namespace App\Exceptions;

use App\Enums\FailureKind;
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
 *
 * ---------------------------------------------------------------------------
 * NO LONGER THROWN FOR THE OUTLINE OR THE ACT SCRIPTS (2026-09-17)
 * ---------------------------------------------------------------------------
 *
 * The argument above is about FINDING the term: an operator scrolling 7,000
 * words misses it. For the two stages Gate 1 reviews, the page now finds it —
 * `OutlineGate::localeDenied()` puts the phrase, its act and its context at
 * the top in the refusal's red — so the judgement is a glance, and refusing
 * had been costing a billed act (story 36, "car park") and the run. Those two
 * stages call `LocaleGuard::denied()` and keep the text.
 *
 * It is still thrown for scene frames and the cast, where the argument holds:
 * a frame or a description is one of 150-250 strings that nobody reads as
 * prose, it goes into a picture rather than past an operator, and no page puts
 * its phrase in front of anyone.
 */
class LocaleViolationException extends RuntimeException implements ClassifiedFailure
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

    /**
     * What happened, and nothing about how the pipeline works.
     *
     * This message is stored verbatim on `render_jobs.error` and read back for
     * as long as the row exists. It used to close with advice — "the stage
     * failed rather than passing this to Gate 1 … re-run the stage" — and on
     * 2026-09-17 the outline and act stages stopped failing on a denied term,
     * so story 36's act 2 row went on telling the operator, from the progress
     * page, a rule the code no longer had. A fact about a run stays true; a
     * sentence about behaviour is only true on the day it was written.
     *
     * The behaviour lives in FailureRemedy, under FailureKind::LocaleRefused,
     * which the page calls at display time, so it is read from the code that
     * is running now.
     */
    private function compose(): string
    {
        $lines = array_map(
            fn (array $hit): string => sprintf('  "%s" — ...%s...', $hit['term'], $hit['context']),
            $this->hits
        );

        return sprintf(
            "%s produced text that is not %s. %d denied term(s):\n%s",
            ucfirst(str_replace('_', ' ', $this->stage)),
            $this->localeProfile,
            count($this->hits),
            implode("\n", $lines)
        );
    }

    /*
     * `recognises()` and `adviceFor()` used to live here: the row kept only a
     * string, so the page matched the message with a regex and asked this
     * class for the advice. The row now records the kind, which is a fact
     * that cannot drift from the wording, and the repair is built by
     * FailureRemedy with every other kind's.
     */

    public function failureKind(): FailureKind
    {
        return FailureKind::LocaleRefused;
    }

    public function failureFacts(): array
    {
        return [];
    }
}
