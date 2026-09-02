<?php

namespace App\Support\Providers;

/**
 * What the narration allowance has left, read from the vendor.
 *
 * This exists because of one property of the plan this app runs on: **there is
 * no overage.** A Starter subscription does not bill for characters past its
 * allowance, it STOPS generating. That turns "not enough credits" from a
 * billing surprise into a partial render — the batch keeps going, scene 171
 * onward fail with a quota error, and the story parks at `assets_generating`
 * with fifteen scenes flagged and the rest of the allowance already spent.
 *
 * The cost estimate cannot catch that. It answers "what will this cost", and on
 * a subscription the answer is $0.00 marginal either way. The question that
 * actually decides whether the run completes is "does this FIT", and it needs a
 * number only the vendor has.
 *
 * `readable` is separate from the counts, and carries the case where the API
 * key is scoped without `user_read`. A key can synthesize perfectly well and
 * still be unable to answer this — and "I could not check" must never render as
 * "you have plenty", so every consumer branches on this flag rather than on a
 * zero.
 */
final class SpeechQuota
{
    public function __construct(
        public readonly bool $readable,
        public readonly ?string $tier = null,
        public readonly ?int $used = null,
        public readonly ?int $limit = null,
        public readonly ?bool $canExtend = null,
        public readonly ?int $resetsAt = null,
        public readonly ?string $unreadableReason = null,
    ) {}

    /**
     * The vendor could not be asked. Not the same as an empty allowance, and
     * the message says which key permission is missing so it is one click to
     * fix rather than a support ticket.
     */
    public static function unreadable(string $reason): self
    {
        return new self(readable: false, unreadableReason: $reason);
    }

    /** Credits left this billing period, or null if it could not be read. */
    public function remaining(): ?int
    {
        if (! $this->readable || $this->limit === null || $this->used === null) {
            return null;
        }

        return max(0, $this->limit - $this->used);
    }

    /**
     * Whether `$credits` fits in what is left.
     *
     * Null when unknown, deliberately — not `true`. A caller that treated an
     * unreadable quota as passing would be exactly the silent-substitution
     * failure this codebase keeps getting bitten by, one layer along.
     */
    public function accommodates(float $credits): ?bool
    {
        $remaining = $this->remaining();

        return $remaining === null ? null : $credits <= $remaining;
    }

    public function summary(): string
    {
        if (! $this->readable) {
            return 'quota unreadable — '.(string) $this->unreadableReason;
        }

        return sprintf(
            '%s: %s of %s credits used, %s remaining%s',
            $this->tier ?? 'unknown tier',
            number_format((float) $this->used),
            number_format((float) $this->limit),
            number_format((float) $this->remaining()),
            $this->canExtend === true ? ', overage enabled' : ', NO overage (generation stops at the limit)',
        );
    }
}
