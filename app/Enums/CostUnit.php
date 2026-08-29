<?php

namespace App\Enums;

/**
 * What a `cost_entries.quantity` is counting.
 *
 * Providers bill in different units and the raw quantity is meaningless
 * without one — 12,000 is a fine number of tokens and an alarming number of
 * images. Stored per row so "what did this video cost, and where did it go"
 * stays answerable in one query.
 */
enum CostUnit: string
{
    case InputTokens = 'input_tokens';
    case OutputTokens = 'output_tokens';
    case Characters = 'characters';
    case Images = 'images';
    case AudioSeconds = 'audio_seconds';
    case Requests = 'requests';

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }
}
