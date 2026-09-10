<?php

namespace App\Support;

use App\Models\Character;

/**
 * What happened when a name the generator used was matched against the cast.
 *
 * ---------------------------------------------------------------------------
 * WHY A RESULT OBJECT AND NOT A NULLABLE CHARACTER
 * ---------------------------------------------------------------------------
 *
 * `ImagePromptBuilder::resolve()` returned `?Character`, so **"nobody is called
 * that" and "five people are called that" were the same answer**, and
 * `DraftScenes::resolvePresent()` dropped both on the floor. Its comment
 * justified the drop for a generator that had invented a person — which is
 * right, and is a completely different situation from a real cast member whose
 * name the matcher could not parse. A single null cannot tell them apart, so
 * nothing downstream could either.
 *
 * The states are deliberately four rather than two:
 *
 *  - `EXACT`     — the whole name matched, case-folded. The ordinary case.
 *  - `TOKEN`     — one character shares more name tokens with this than any
 *                  other. "Kevin" for "Kevin Lin"; "Bennett" for "Kyle Bennett".
 *  - `AMBIGUOUS` — several characters tie. "Song" against a cast holding five
 *                  Songs. **Refused, never guessed**, and it carries the names
 *                  it could not choose between so the report can print them.
 *  - `UNKNOWN`   — nothing shares a token. Usually the generator inventing
 *                  somebody, which is what the original drop was written for.
 *
 * AMBIGUOUS and UNKNOWN both resolve to no character. They are separate because
 * they want opposite reactions: UNKNOWN means a person who is not in the story,
 * and AMBIGUOUS means a person who is — probably several — and a description
 * that will be missing from a frame that needed it.
 */
final class NameMatch
{
    public const EXACT = 'exact';

    public const TOKEN = 'token';

    public const AMBIGUOUS = 'ambiguous';

    public const UNKNOWN = 'unknown';

    /**
     * @param  array<int, string>  $candidates  Names tied for an ambiguous match.
     */
    private function __construct(
        public readonly ?Character $character,
        public readonly string $reason,
        public readonly string $asked,
        public readonly array $candidates = [],
    ) {}

    public static function exact(Character $character, string $asked): self
    {
        return new self($character, self::EXACT, $asked);
    }

    public static function token(Character $character, string $asked): self
    {
        return new self($character, self::TOKEN, $asked);
    }

    /**
     * @param  array<int, string>  $candidates
     */
    public static function ambiguous(string $asked, array $candidates): self
    {
        sort($candidates);

        return new self(null, self::AMBIGUOUS, $asked, $candidates);
    }

    public static function unknown(string $asked): self
    {
        return new self(null, self::UNKNOWN, $asked);
    }

    public function resolved(): bool
    {
        return $this->character !== null;
    }

    /**
     * A sentence for a log or a page, or null when nothing went wrong.
     *
     * Written here rather than at the call sites because there are three of
     * them and this project's most reliable source of bugs is a fix applied to
     * one caller out of several. The two problems read differently on purpose:
     * an ambiguous name names the people it could not choose between, because
     * "Song is ambiguous" is worth much less in front of an operator than
     * "Song could be Song Anan, Song Baoqin, Song Peiyuan, Song Peizhen or
     * Song Yiran".
     */
    public function problem(): ?string
    {
        return match ($this->reason) {
            self::AMBIGUOUS => sprintf(
                '"%s" matches %d characters and was NOT guessed: %s. Their descriptions are '
                .'missing from this frame — name one of them in full.',
                $this->asked,
                count($this->candidates),
                implode(', ', $this->candidates),
            ),
            self::UNKNOWN => sprintf(
                '"%s" matches nobody in the cast, so no description was pasted for them. Usually '
                .'the generator inventing a person.',
                $this->asked,
            ),
            default => null,
        };
    }
}
