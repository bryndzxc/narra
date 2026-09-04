<?php

namespace App\Actions;

use App\Models\Story;
use App\Support\DeliveryFolder;
use App\Support\RenderWorkspace;
use RuntimeException;

/**
 * Copies the finished MP4 somewhere a human will actually find it.
 *
 * The render workspace is not that place: `storage/app/renders/<slug>/final.mp4`
 * is next to the scratch, and every story's deliverable is called the same
 * thing. This puts one file per story, named for the story, in a folder the
 * operator chose.
 *
 * **Copy, never move.** Three things read the workspace copy — Gate 3's video
 * route, the purge guard, and the re-render idempotency check — and all three
 * break on a moved file. The delivered copy is the operator's to do anything
 * with; the workspace copy is the app's record. See config/render.php.
 *
 * **Every refusal here is about a path this app does not control.** That is the
 * whole reason this is a stage with a row of its own rather than three lines at
 * the end of the mux: an external drive can be unplugged, a synced folder can
 * be mid-sync, a share can be unmounted, and a network path can accept half a
 * file. None of those are conditions the mux has ever had to think about.
 *
 * Those refusals now live in App\Support\DeliveryFolder, because the thumbnail
 * lands in the same folder under the same rules and two hand-maintained copies
 * of one guard is how they come to disagree. What stays here is what is
 * specific to the video: where it comes from, what it is named, and that
 * reaching this stage without a final.mp4 is an error rather than a skip.
 */
class DeliverFinalVideo
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
    public function handle(Story $story, ?string $root = null): array
    {
        $root = DeliveryFolder::configured($root);

        // Not configured is not a failure. Delivery is opt-in, and a render on
        // a machine that has not set it is a complete, correct render.
        if ($root === null) {
            return $this->skipped(
                'No delivery path configured (RENDER_DELIVERY_PATH is empty), so the finished file '
                .'stays in the render workspace and nothing was copied.'
            );
        }

        $source = RenderWorkspace::for($story)->path('final.mp4');

        if (! is_readable($source)) {
            throw new RuntimeException(
                "Nothing to deliver: {$source} does not exist or cannot be read. This stage runs "
                .'after the mux, so reaching it without a final.mp4 means the file was removed '
                .'between the two.'
            );
        }

        // Sanity of the path itself first, then the length of what would be
        // written, and only then anything that touches the disk. That order is
        // deliberate: creating a directory and then refusing to write into it
        // leaves the operator an empty folder as the only evidence of a stage
        // that never ran.
        DeliveryFolder::assertPathSane($root);

        $destination = DeliveryFolder::pathFor(
            $root,
            $this->filename($story),
            'shorten the story slug',
        );

        DeliveryFolder::assertDirectoryReady($root);

        // A re-render of the same story lands on the same name, deliberately:
        // the operator wants the current cut of this video, not an archive of
        // every attempt. It is reported rather than silent, because overwriting
        // something the operator may have already uploaded is worth a sentence.
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

    /** `<slug>.mp4` — one file per story, named for the story. */
    public function filename(Story $story): string
    {
        // The slug is already filesystem-safe: Story::slugFor built it, and
        // every other path in this app is derived from it for exactly that
        // reason. Never the title, which carries quotes and colons.
        $slug = trim((string) $story->slug);

        if ($slug === '') {
            throw new RuntimeException(
                'This story has no slug, so there is no safe name to deliver it under. A title is '
                .'not a filename — it carries quotes, colons and slashes.'
            );
        }

        return $slug.'.mp4';
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
