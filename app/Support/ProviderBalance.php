<?php

namespace App\Support;

/**
 * What a vendor has left, or the honest reason there is no such number.
 *
 * ---------------------------------------------------------------------------
 * THE ONE RULE THIS CLASS EXISTS TO ENFORCE
 * ---------------------------------------------------------------------------
 *
 * **A balance and a spend total are different questions, and only one of them
 * most of these vendors can answer.** Three providers, three different answers:
 *
 *   ElevenLabs  exposes `/user/subscription`, so there is a real remaining
 *               figure — and it MATTERS here beyond curiosity, because the
 *               plan has no overage: running out does not bill extra, it
 *               stops generating and leaves a story half-narrated with the
 *               allowance already spent. See SpeechQuota.
 *   fal         exposes a balance behind an admin-scoped key. The key this
 *               app holds gets 403. There is no number.
 *   Anthropic   exposes no balance endpoint at all. There is no number, and
 *               there never will be one to read.
 *
 * For the last two the dashboard shows spend-to-date and SAYS it is spend.
 * What it must never do is subtract our own ledger from a remembered top-up
 * and print the result where a balance goes. That figure would be derived
 * entirely from our own bookkeeping, which is the thing rule 3 of the
 * false-success section says is worth nothing on its own — and it would be
 * indistinguishable, on screen, from the one number here that a vendor
 * actually vouches for.
 *
 * UNREADABLE is its own state and is never folded into "no endpoint" or into a
 * zero. A key without `user_read` can synthesize perfectly well and still be
 * unable to answer, and "I could not check" rendered as "you have plenty" is
 * the absence-read-as-agreement failure this codebase keeps finding.
 */
final class ProviderBalance
{
    /** The vendor told us what is left. */
    public const BALANCE = 'balance';

    /** The vendor has a balance and we could not read it. Not a pass. */
    public const UNREADABLE = 'unreadable';

    /** The vendor publishes no balance. Spend-to-date is shown instead. */
    public const NO_ENDPOINT = 'no_endpoint';

    /** A stand-in is configured, so there is no allowance to consume. */
    public const SIMULATED = 'simulated';

    /** Runs locally. No account, no allowance, no bill. */
    public const LOCAL = 'local';

    /**
     * The resolved instance cannot say what it is.
     *
     * Distinct from UNREADABLE, which is a vendor we could not reach, and from
     * NO_ENDPOINT, which is a vendor with nothing to reach. This one is about
     * OUR OWN code: a provider contract that does not extend ProviderIdentity,
     * so the object cannot be asked its name or whether it bills. Kept as its
     * own state because the fix is in this repository rather than in a
     * dashboard or a key.
     */
    public const UNNAMEABLE = 'unnameable';

    private function __construct(
        public readonly string $provider,
        public readonly string $role,
        public readonly string $kind,
        public readonly string $headline,
        public readonly ?int $remaining = null,
        public readonly ?int $limit = null,
        public readonly ?string $tier = null,
        public readonly ?float $spendToDate = null,
        public readonly ?string $unit = null,
    ) {}

    public static function balance(
        string $provider,
        string $role,
        int $remaining,
        int $limit,
        ?string $tier,
        string $unit,
        string $headline,
    ): self {
        return new self(
            provider: $provider,
            role: $role,
            kind: self::BALANCE,
            headline: $headline,
            remaining: $remaining,
            limit: $limit,
            tier: $tier,
            unit: $unit,
        );
    }

    public static function unreadable(string $provider, string $role, string $why): self
    {
        return new self($provider, $role, self::UNREADABLE, $why);
    }

    public static function noEndpoint(string $provider, string $role, float $spendToDate, string $why): self
    {
        return new self(
            provider: $provider,
            role: $role,
            kind: self::NO_ENDPOINT,
            headline: $why,
            spendToDate: $spendToDate,
        );
    }

    public static function unnameable(string $class, string $role, string $why): self
    {
        return new self($class, $role, self::UNNAMEABLE, $why);
    }

    public static function simulated(string $provider, string $role): self
    {
        return new self(
            $provider,
            $role,
            self::SIMULATED,
            'A stand-in is configured for this role. No vendor is contacted and nothing is billed, '
            .'so there is no allowance to report.',
        );
    }

    public static function local(string $provider, string $role): self
    {
        return new self(
            $provider,
            $role,
            self::LOCAL,
            'Runs on this machine. No account and no allowance — the cost is CPU time.',
        );
    }

    /**
     * How much of the allowance is gone, for a meter.
     *
     * Null unless there is a real balance, which is what stops a meter being
     * drawn for a provider that has no such thing.
     */
    public function usedFraction(): ?float
    {
        if ($this->kind !== self::BALANCE || $this->limit === null || $this->limit <= 0) {
            return null;
        }

        return min(1.0, max(0.0, ($this->limit - (int) $this->remaining) / $this->limit));
    }

    /**
     * Whether this reading should be shouted about.
     *
     * An unreadable balance is a warning: it is a check that could not run, and
     * this project treats those as failures rather than passes. A real balance
     * under a tenth of its limit is a warning for a different reason — on a
     * plan without overage that is a partially narrated story waiting to
     * happen. Both are things an operator can act on TODAY, with a key or a
     * top-up.
     *
     * UNNAMEABLE deliberately is NOT one of them, and the distinction is worth
     * stating because it looks like a retreat from "say it loudly".
     *
     * It is not an operational fact — it is a missing `extends ProviderIdentity`
     * on one contract in this repository. Nothing an operator does changes it,
     * so raising it every fifteen seconds on the page they leave open would
     * make it permanent furniture, and a permanent alarm is one that stops
     * being read — taking the two real alarms beside it down with it. It keeps
     * its badge and its full explanation and loses the amber surface: stated
     * plainly, once, where somebody will see it, rather than shouted forever.
     */
    public function isConcerning(): bool
    {
        if ($this->kind === self::UNREADABLE) {
            return true;
        }

        $used = $this->usedFraction();

        return $used !== null && $used >= 0.9;
    }
}
