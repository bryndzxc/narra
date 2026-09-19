<?php

namespace App\Support\Providers;

/**
 * One premise the generator wrote, and the spine answers written beside it.
 *
 * The prose is the only part that reaches the outline: the outline writer is
 * handed `stories.premise` and nothing else. The fields are there so the Gate 1
 * checks can be run BEFORE the operator picks — on an unsaved story holding
 * them — and the premise checks tie them back to the prose, because a field
 * the prose does not carry is a claim about a premise the outline never sees.
 */
final class PremiseCandidate
{
    /** The spine fields a candidate carries, in schema order. */
    public const FIELDS = [
        'antagonist_justification',
        'accomplice_motive',
        'accomplice_performance',
        'betrayal_scene',
        'withheld_information',
        'narrator_at_exposure',
        'departure',
    ];

    /**
     * @param  array<int, CastMember>  $cast  the narrator first, as outline_cast stores it
     * @param  array<string, string>  $fields  keyed by FIELDS
     */
    public function __construct(
        public readonly string $premise,
        public readonly array $cast,
        public readonly array $fields,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromRow(array $row): self
    {
        $fields = [];

        foreach (self::FIELDS as $field) {
            $fields[$field] = trim((string) ($row[$field] ?? ''));
        }

        return new self(
            premise: trim((string) ($row['premise'] ?? '')),
            cast: array_map(
                static fn (array $m): CastMember => CastMember::fromRow($m),
                array_values(array_filter((array) ($row['cast'] ?? []), 'is_array')),
            ),
            fields: $fields,
        );
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return ['premise' => $this->premise]
            + ['cast' => array_map(static fn (CastMember $m): array => $m->toRow(), $this->cast)]
            + $this->fields;
    }
}
