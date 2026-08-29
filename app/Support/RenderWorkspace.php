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
     * In Phase 1b these are relative to the fixtures disk, because that is
     * where the stills and narration come from. Phase 2 changes this method and
     * nothing else.
     */
    public function sourcePath(string $storedPath): string
    {
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
        if (! is_dir($this->renderRoot)) {
            mkdir($this->renderRoot, 0775, true);
        }
    }

    private static function normalise(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
