<?php

namespace App\Support;

/**
 * What the page can say about repairing one failure.
 *
 * THREE SHAPES, because there are three different things to know:
 *
 *  - **unknown** — no move is known. `text`, `command` and the action are all
 *    null, and the page renders "No known repair." A remedy that softened into
 *    a suggestion when nothing was known would be exactly the advice this
 *    exists to stop.
 *  - **known** — the move is known and so is its outcome: certain from the
 *    failure itself, or measured working here.
 *  - **unmeasuredMove** — exactly one move exists and it is certain, and
 *    nobody has measured whether it succeeds. It gets the button, and it
 *    gets `unmeasured`: a sentence saying so, stored apart from `text` and
 *    rendered as its own line, so an edit to the advice cannot quietly drop
 *    the caveat. Split out 2026-09-18, when story 37's outline was refused for
 *    its cast and the page said "No known repair." beside the one button that
 *    repairs it. See FailureKind for the rule.
 */
final readonly class Remedy
{
    private function __construct(
        public bool $known,
        public ?string $text,
        public ?string $command,
        public ?string $actionLabel,
        public ?string $actionUrl,
        /** Null when the outcome is known. Otherwise, plainly, what has not been measured. */
        public ?string $unmeasured = null,
    ) {}

    public static function unknown(): self
    {
        return new self(false, null, null, null, null);
    }

    public static function known(string $text, ?string $command = null, ?string $actionLabel = null, ?string $actionUrl = null): self
    {
        return new self(true, $text, $command, $actionUrl === null ? null : $actionLabel, $actionUrl);
    }

    public static function unmeasuredMove(
        string $text,
        string $unmeasured,
        ?string $command = null,
        ?string $actionLabel = null,
        ?string $actionUrl = null,
    ): self {
        if (trim($unmeasured) === '') {
            throw new \InvalidArgumentException('An unmeasured move has to say what has not been measured.');
        }

        return new self(true, $text, $command, $actionUrl === null ? null : $actionLabel, $actionUrl, $unmeasured);
    }
}
