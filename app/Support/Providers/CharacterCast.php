<?php

namespace App\Support\Providers;

/**
 * Every recurring character in a story, extracted before a single scene is
 * drafted.
 *
 * The order matters: characters first, then scenes. A scene drafted before the
 * cast exists has to invent a description for whoever is in it, and two scenes
 * inventing separately is exactly the drift this table exists to prevent.
 */
final class CharacterCast
{
    /**
     * @param  array<int, CharacterProfile>  $characters
     */
    public function __construct(
        public readonly array $characters,
        public readonly ProviderUsage $usage,
    ) {}

    public function count(): int
    {
        return count($this->characters);
    }

    /** @return array<int, string> */
    public function names(): array
    {
        return array_map(fn (CharacterProfile $c): string => $c->name, $this->characters);
    }

    /** Everything a locale check has to read. */
    public function proseForInspection(): string
    {
        return implode("\n", array_map(
            fn (CharacterProfile $c): string => implode("\n", [$c->name, $c->description, $c->styleNotes]),
            $this->characters
        ));
    }
}
