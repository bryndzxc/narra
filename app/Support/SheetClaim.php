<?php

namespace App\Support;

use App\Models\Character;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * One character's sheet can only be generating once at a time.
 *
 * ---------------------------------------------------------------------------
 * WHY A LOCK AND NOT COMPONENT STATE
 * ---------------------------------------------------------------------------
 *
 * The obvious fix for a double submit is to consume the confirmation flag —
 * `$this->confirming` — at the top of the handler. **It does not work here, and
 * the reason is worth stating because it looks like it should.** Livewire sends
 * a serialised snapshot of the component with every request. Two clicks fired
 * before the first response returns arrive as two requests carrying the SAME
 * snapshot, each with `confirming` still armed, each deserialised into its own
 * component instance. Whatever the component believes, it believes twice.
 *
 * Nor is the window narrow. A candidate image measures 36 seconds at the median
 * and 53 at the worst across 62 real generations, so a four-candidate sheet
 * holds the request for about **144 seconds**. That is the interval during
 * which the old page showed a live button and an unchanged row.
 *
 * A lock in the cache is outside the request, so it is the only thing that can
 * see the other one. It also covers the arrangements no amount of UI state
 * could reach: a second browser tab, a second operator, and `characters:sheets`
 * running in a terminal while the page is open.
 *
 * ---------------------------------------------------------------------------
 * WHY IT EXPIRES
 * ---------------------------------------------------------------------------
 *
 * **A lock with no expiry converts a crash into a character that can never be
 * generated again**, with nothing on screen explaining why — a worse failure
 * than the one being fixed, and silent in the way this project keeps paying
 * for. The claim is held for a bounded time, sized off the measurement rather
 * than picked: 144 seconds is the median sheet, the worst observed sheet is 136
 * seconds of gaps plus a final image, and the default here is an order of
 * magnitude above both so that a slow provider day cannot expire a live claim
 * and let a second press through.
 *
 * It is released in a `finally`, so the ordinary path never waits for the
 * expiry — the timeout is for the request that died, not for the one that
 * worked.
 *
 * ---------------------------------------------------------------------------
 * WHERE IT LIVES
 * ---------------------------------------------------------------------------
 *
 * Taken inside `GenerateCharacterSheet`, not in the Livewire component. A guard
 * in the UI would leave the console command — the one caller the UI cannot see
 * — exactly as exposed as before, which is the "a guard must be upstream of the
 * thing it distrusts" rule. The component's job is to report the refusal.
 */
class SheetClaim
{
    /**
     * The claim on one character, unacquired.
     *
     * Returned rather than acquired so a caller can choose between `get()` (try
     * once, refuse) and `block()` (wait). Only the first is used: an operator
     * pressing twice wants to be told the first press landed, not to be queued
     * behind it and billed again when it finishes.
     */
    public function lockFor(Character $character): Lock
    {
        return Cache::lock($this->key($character), $this->secondsHeld());
    }

    /**
     * How long a claim survives an abandoned request.
     *
     * Config, not a constant, because it is derived from a measured provider
     * latency and that is exactly the kind of number that moves. See the class
     * docblock for where 36s / 144s came from.
     */
    public function secondsHeld(): int
    {
        return max(1, (int) config('characters.sheet_claim_seconds', 1800));
    }

    /** The cache key, in one place so the tests cannot drift from the Action. */
    public function key(Character $character): string
    {
        return 'character-sheet:'.$character->getKey();
    }
}
