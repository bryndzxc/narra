<?php

namespace App\Support\Providers;

use App\Enums\CastRole;

/**
 * One named person, as the outline declared them.
 *
 * Not a Character. A Character is what the extractor DESCRIBES, later, from
 * the scripts; this is what the outline DECIDED, before any script existed.
 * The extractor is handed the list of these and describes those people and
 * nobody else.
 */
final class CastMember
{
    public function __construct(
        public readonly string $name,
        public readonly ?CastRole $role,
        /** How they relate to the narrator, and for a future partner who they arrive through. */
        public readonly string $relationship = '',
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromRow(array $row): self
    {
        return new self(
            name: trim((string) ($row['name'] ?? '')),
            // A value outside the enum decodes to null rather than to a guess:
            // null is "not said", and Gate 1 reports it.
            role: CastRole::tryFrom(trim((string) ($row['role'] ?? ''))),
            relationship: trim((string) ($row['relationship'] ?? '')),
        );
    }

    /** @return array{name: string, role: string, relationship: string} */
    public function toRow(): array
    {
        return [
            'name' => $this->name,
            'role' => $this->role?->value ?? '',
            'relationship' => $this->relationship,
        ];
    }
}
