<?php

namespace App\Actions;

use App\Models\Story;
use App\Models\YoutubeMetadata;
use App\Support\DeliveryFolder;
use RuntimeException;

/**
 * Copies the chosen thumbnail out beside the finished video.
 *
 * `<slug>.mp4` and `<slug>.jpg`, in one folder, so the two files an upload
 * needs are next to each other under the same name. Anything else means the
 * operator has the video in the delivery folder and the picture somewhere under
 * `storage/app/renders`, which is the arrangement the delivery stage exists to
 * end.
 *
 * The same folder under the same rules as the video — see DeliveryFolder, which
 * holds every refusal about a path this app does not control. What differs here
 * is only the source and the extension.
 *
 * Copy, never move: the workspace copy is what Gate 4 shows the operator when
 * they come back to the page, and a moved file leaves the picker pointing at
 * nothing.
 */
class DeliverThumbnail
{
    /**
     * @return array{
     *     delivered: bool,
     *     reason: ?string,
     *     source: ?string,
     *     destination: ?string,
     *     bytes: int,
     *     replaced: bool,
     * }
     */
    public function handle(Story $story, YoutubeMetadata $metadata, ?string $root = null): array
    {
        $selected = trim((string) $metadata->thumbnail_selected);

        if ($selected === '') {
            return $this->skipped('No thumbnail composition is selected, so nothing was copied out.');
        }

        $option = collect($metadata->thumbnail_options ?? [])
            ->first(fn (array $o): bool => ($o['key'] ?? null) === $selected);

        if ($option === null) {
            // Not silent. A selection naming a composition that is not in the
            // list means the options were re-composed and the key went with
            // them, and an operator who thinks they picked a thumbnail should
            // find out here rather than at upload.
            throw new RuntimeException(sprintf(
                'The selected thumbnail "%s" is not among the composed options. Re-compose the '
                .'thumbnails and pick again.',
                $selected,
            ));
        }

        $source = (string) ($option['path'] ?? '');

        if ($source === '' || ! is_readable($source)) {
            throw new RuntimeException(sprintf(
                "The selected thumbnail file is missing:\n  %s\nRe-compose the thumbnails; the "
                .'stills it is built from are still there.',
                $source,
            ));
        }

        $root = DeliveryFolder::configured($root);

        if ($root === null) {
            return $this->skipped(
                'No delivery path configured (RENDER_DELIVERY_PATH is empty), so the thumbnail '
                .'stays in the render workspace and nothing was copied.'
            );
        }

        DeliveryFolder::assertPathSane($root);

        $destination = DeliveryFolder::pathFor(
            $root,
            $this->filename($story),
            'shorten the story slug',
        );

        DeliveryFolder::assertDirectoryReady($root);

        $copied = DeliveryFolder::copyInto($source, $destination);

        return [
            'delivered' => true,
            'reason' => null,
            'source' => $source,
            'destination' => $destination,
            'bytes' => $copied['bytes'],
            'replaced' => $copied['replaced'],
        ];
    }

    /** `<slug>.jpg` — beside `<slug>.mp4`, named the same way for the same reason. */
    public function filename(Story $story): string
    {
        $slug = trim((string) $story->slug);

        if ($slug === '') {
            throw new RuntimeException(
                'This story has no slug, so there is no safe name to deliver it under. A title is '
                .'not a filename — it carries quotes, colons and slashes.'
            );
        }

        return $slug.'.jpg';
    }

    /**
     * @return array{delivered: bool, reason: ?string, source: ?string, destination: ?string, bytes: int, replaced: bool}
     */
    private function skipped(string $reason): array
    {
        return [
            'delivered' => false,
            'reason' => $reason,
            'source' => null,
            'destination' => null,
            'bytes' => 0,
            'replaced' => false,
        ];
    }
}
