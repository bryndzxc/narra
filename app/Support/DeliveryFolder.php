<?php

namespace App\Support;

use RuntimeException;

/**
 * The folder outside the project that finished work is copied into, and every
 * refusal that goes with it.
 *
 * Extracted when the thumbnail stage arrived, because the alternative was a
 * second copy of these guards. Every one of them is about a path this app does
 * not control — an external drive can be unplugged, a synced folder can be
 * mid-sync, a share can be unmounted, a drive letter can be a typo — and two
 * hand-maintained copies of that reasoning is the shape this project keeps
 * finding at the bottom of its own bug list. The MP4 and the JPG go to the same
 * folder under the same rules or the rules are not rules.
 *
 * What stays with the caller is what differs: the source file, the name it
 * lands under, and what "delivered" means for that artifact. A 700 MB copy that
 * is silently short and a 300 KB one are the same failure, but only the caller
 * knows what size it was expecting.
 */
final class DeliveryFolder
{
    /**
     * Windows refuses paths past 260 characters unless long paths are enabled,
     * and the error it gives for it is not one anybody reads as a path-length
     * problem. Checked here so the message says what is actually wrong.
     */
    public const MAX_PATH = 255;

    /**
     * The configured delivery root, or null when delivery is off.
     *
     * Not configured is not a failure. Delivery is opt-in, and a render on a
     * machine that has not set it is a complete, correct render.
     */
    public static function configured(?string $override = null): ?string
    {
        $root = trim((string) ($override ?? config('render.delivery.path')));

        return $root === '' ? null : $root;
    }

    /**
     * Where a named file would land, refusing a path too long to write.
     *
     * The length is checked before anything touches the disk, deliberately:
     * creating a directory and then refusing to write into it leaves the
     * operator an empty folder as the only evidence of a stage that never ran.
     */
    public static function pathFor(string $root, string $filename, string $shortenHint): string
    {
        $destination = rtrim($root, '/\\').DIRECTORY_SEPARATOR.$filename;

        if (mb_strlen($destination) > self::MAX_PATH) {
            throw new RuntimeException(sprintf(
                'The delivery path would be %d characters, past the %d Windows accepts without long '
                ."paths enabled:\n  %s\nShorten RENDER_DELIVERY_PATH, or %s.",
                mb_strlen($destination),
                self::MAX_PATH,
                $destination,
                $shortenHint,
            ));
        }

        return $destination;
    }

    /**
     * Whether the configured path is the KIND of path this feature accepts.
     *
     * Nothing here touches the disk, which is why it runs first.
     */
    public static function assertPathSane(string $root): void
    {
        // Relative is refused rather than resolved. "Relative to what" has
        // three plausible answers — the project root, the storage root, and the
        // worker's working directory, which is not the same thing on a Windows
        // service — and quietly picking one puts a large file somewhere the
        // operator then has to hunt for.
        if (! self::isAbsolute($root)) {
            throw new RuntimeException(sprintf(
                'RENDER_DELIVERY_PATH must be an absolute path; "%s" is relative. A worker running '
                .'as a service does not share a working directory with your shell, so a relative '
                .'path would deliver somewhere different depending on who ran the render.',
                $root,
            ));
        }

        // The point of this feature is a folder outside the project. A path
        // inside it is almost always a typo, and one inside storage/app/renders
        // could collide with a workspace directory named for a slug.
        $real = realpath($root) ?: $root;
        $base = realpath(base_path()) ?: base_path();

        if (self::isInside($real, $base)) {
            throw new RuntimeException(sprintf(
                "RENDER_DELIVERY_PATH points inside the project:\n  %s\nDelivery exists to put the "
                .'finished file somewhere outside it. A path under storage/app/renders can also '
                .'collide with a workspace directory named for a story slug.',
                $root,
            ));
        }
    }

    /**
     * Make the directory exist and be writable, or say precisely why not.
     */
    public static function assertDirectoryReady(string $root): void
    {
        if (is_dir($root)) {
            self::assertWritable($root);

            return;
        }

        if (! (bool) config('render.delivery.create', true)) {
            throw new RuntimeException(sprintf(
                'The delivery directory does not exist and RENDER_DELIVERY_CREATE is off: %s',
                $root,
            ));
        }

        // The parent must exist. This is the guard that catches the typo the
        // auto-create would otherwise hide: `E:\deliver` with no E: drive, or a
        // share that is not mounted, would otherwise be "created" as a path
        // that goes nowhere useful, and 275 MB would go into it every render.
        $parent = dirname($root);

        if (! is_dir($parent)) {
            throw new RuntimeException(sprintf(
                "Refusing to create the delivery directory: its parent does not exist.\n  path   %s"
                ."\n  parent %s\nThat usually means a wrong drive letter or an unmounted share. "
                .'Creating the whole tree anyway would put every render somewhere nobody looks.',
                $root,
                $parent,
            ));
        }

        if (! @mkdir($root, 0775, true) && ! is_dir($root)) {
            throw new RuntimeException("Could not create the delivery directory: {$root}");
        }

        self::assertWritable($root);
    }

    /**
     * Copy a file in, and verify what landed is what was sent.
     *
     * **Existence is not success** — the same rule the purge guard follows. A
     * copy to a full disk, a dropped network share or a folder mid-sync leaves
     * a file that exists and is short, and a stage that reported a delivery it
     * did not perform is the false-success shape this project keeps paying for.
     *
     * @return array{bytes: int, replaced: bool}
     */
    public static function copyInto(string $source, string $destination): array
    {
        $replaced = is_file($destination);
        $bytes = (int) filesize($source);

        if (! @copy($source, $destination)) {
            throw new RuntimeException(sprintf(
                "Could not copy %s to %s.\nThe workspace copy is untouched. Check the path is "
                .'reachable and writable.',
                $source,
                $destination,
            ));
        }

        clearstatcache(true, $destination);
        $written = is_file($destination) ? (int) filesize($destination) : 0;

        if ($written !== $bytes) {
            throw new RuntimeException(sprintf(
                'The delivered file is %s and the source is %s. That is a truncated copy, not a '
                ."delivery — the destination may be full, unmounted or mid-sync.\n  %s",
                self::human($written),
                self::human($bytes),
                $destination,
            ));
        }

        return ['bytes' => $bytes, 'replaced' => $replaced];
    }

    public static function human(int $bytes): string
    {
        return $bytes >= 1048576
            ? sprintf('%.1f MB', $bytes / 1048576)
            : sprintf('%d bytes', $bytes);
    }

    private static function assertWritable(string $root): void
    {
        if (! is_writable($root)) {
            throw new RuntimeException(sprintf(
                'The delivery directory is not writable by the worker: %s. A queue worker running '
                .'as a Windows service runs as a different account than your shell does.',
                $root,
            ));
        }
    }

    /** Windows drive letters and UNC shares, plus POSIX roots. */
    private static function isAbsolute(string $path): bool
    {
        return (bool) preg_match('#^([a-zA-Z]:[\\\\/]|\\\\\\\\|/)#', $path);
    }

    private static function isInside(string $path, string $base): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/').'/';
        $base = rtrim(str_replace('\\', '/', $base), '/').'/';

        return str_starts_with(mb_strtolower($path), mb_strtolower($base));
    }
}
