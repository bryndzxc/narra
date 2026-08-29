<?php

namespace App\Actions;

use App\Models\YoutubeMetadata;

/**
 * Assembles the description block an operator would otherwise paste together
 * by hand: their opening, the chapter list, and the channel footer.
 *
 * Only the mechanical parts. The opening two or three sentences are the real
 * payload — they are what shows in search and above the fold — and they are
 * written, not generated, until a provider exists to draft them at Gate 4.
 */
class ComposeDescription
{
    public function handle(YoutubeMetadata $metadata, string $opening): string
    {
        $parts = [rtrim($opening)];

        $chapters = $metadata->chapters();

        if ($chapters !== []) {
            $lines = ['Chapters:'];

            foreach ($chapters as $chapter) {
                $lines[] = $chapter['timestamp'].' '.$chapter['title'];
            }

            $parts[] = implode("\n", $lines);
        }

        $footer = trim((string) config('youtube.footer'));

        if ($footer !== '') {
            $parts[] = $footer;
        }

        return implode("\n\n", array_filter($parts, fn (string $part): bool => trim($part) !== ''));
    }
}
