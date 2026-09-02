<?php

namespace App\Support;

use App\Models\Scene;
use App\Models\Story;
use Illuminate\Support\Facades\Storage;

/**
 * Every filesystem path the render pipeline uses, in one place.
 *
 * Two reasons it exists rather than paths being assembled at each call site:
 *
 *  1. Windows caps a path at 260 characters unless long paths are enabled, and
 *     a 200-scene render puts a lot of files under one root. Short slugged
 *     names, decided once.
 *  2. Phase 1b reads its source assets from the fixtures disk; Phase 2 will
 *     generate them instead. That is one method to change here, rather than a
 *     hunt through jobs.
 *
 * Paths come out with forward slashes throughout, which FFmpeg accepts on
 * Windows and which keeps the same strings usable in a concat list.
 */
class RenderWorkspace
{
    private function __construct(
        public readonly string $slug,
        public readonly string $sourceRoot,
        public readonly string $renderRoot,
    ) {}

    public static function for(Story $story): self
    {
        return self::forSlug((string) $story->slug);
    }

    public static function forSlug(string $slug): self
    {
        $slug = trim($slug, '/');

        return new self(
            slug: $slug,
            sourceRoot: self::normalise(Storage::disk('fixtures')->path($slug)),
            renderRoot: self::normalise(Storage::disk('renders')->path($slug)),
        );
    }

    /**
     * Resolve an asset path as stored on a scene or scene_audio row.
     *
     * Two disks now hold source assets and a stored path names neither, so the
     * resolution is by where the file actually is: `assets` first, then
     * `fixtures`.
     *
     * The order is what matters. `assets` holds what a provider generated and
     * was billed for; `fixtures` holds the hand-made Phase 0 inputs that prove
     * the render pipeline without spending anything. A story is driven by one
     * or the other and never both, so in practice only one disk ever has the
     * file — but if a slug ever collided, resolving to the paid asset is the
     * safe way to be wrong.
     *
     * Falling back to the fixtures path when neither disk has it is deliberate:
     * the callers that consume this raise errors naming the missing file, and a
     * path is more use in that message than an empty string.
     */
    public function sourcePath(string $storedPath): string
    {
        $assets = Storage::disk((string) config('render.assets.disk', 'assets'));

        if ($assets->exists($storedPath)) {
            return self::normalise($assets->path($storedPath));
        }

        return self::normalise(Storage::disk('fixtures')->path($storedPath));
    }

    /** `scene-007` — short, slugged, and zero-padded so it sorts. */
    public function sceneSlug(Scene $scene): string
    {
        return sprintf('scene-%03d', $scene->sequence);
    }

    public function clipPath(Scene $scene): string
    {
        return $this->renderRoot.'/clips/'.$this->sceneSlug($scene).'.mp4';
    }

    public function paddedAudioPath(Scene $scene): string
    {
        return $this->renderRoot.'/padded/'.$this->sceneSlug($scene).'.wav';
    }

    public function path(string $name): string
    {
        return $this->renderRoot.'/'.ltrim($name, '/');
    }

    public function exists(string $name): bool
    {
        return is_readable($this->path($name));
    }

    public function ensureExists(): void
    {
        Directory::ensure($this->renderRoot);
    }

    private static function normalise(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
