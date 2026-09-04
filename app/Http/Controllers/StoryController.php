<?php

namespace App\Http\Controllers;

use App\Enums\Gate;
use App\Models\CharacterReference;
use App\Models\Scene;
use App\Models\Story;
use App\Support\RenderWorkspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The operator's side of the app: four gates and the stories waiting at them.
 *
 * Each gate is a Livewire component; this only picks which one to show and
 * serves the two kinds of file a gate needs to display — the render at Gate 3
 * and the stills at Gates 2 and 4. Neither disk is public, and neither should
 * be: an image prompt or a story title reaching the filesystem is treated as
 * hostile input everywhere else, and a publicly listable render directory would
 * undo that.
 */
class StoryController extends Controller
{
    /** Which route serves each gate. */
    private const GATE_ROUTES = [
        1 => 'stories.outline',
        2 => 'stories.scenes',
        3 => 'stories.preview',
        4 => 'stories.metadata',
    ];

    public function index(): View
    {
        // The table moved into a Livewire component. It needs to poll — this
        // page replaces three terminal windows that updated themselves — and it
        // needs per-row queue health, which is a live reading rather than a
        // column. Neither is something a static blade can do.
        return view('stories.index');
    }

    /**
     * The front door.
     *
     * There was no way to start a video from this app at all before this route:
     * `story:write --premise` held the only copy of story creation, so the tool
     * built so an operator would not need a terminal required one to begin.
     */
    public function create(): View
    {
        return view('stories.create');
    }

    /**
     * Send the operator to whichever gate this story is actually waiting at,
     * or to the last one it passed if it is mid-pipeline.
     */
    public function show(Story $story): RedirectResponse
    {
        $gate = $story->awaitingGate() ?? $this->lastPassedGate($story);

        return redirect()->route(self::GATE_ROUTES[$gate->value], $story);
    }

    public function outline(Story $story): View
    {
        return view('stories.gate', ['story' => $story, 'gate' => Gate::Outline]);
    }

    public function scenes(Story $story): View
    {
        return view('stories.gate', ['story' => $story, 'gate' => Gate::Scenes]);
    }

    /**
     * Gate 2's character sheet sub-step.
     *
     * Not a fifth gate. The four gates are the product's central promise and
     * their count is not a detail — this is the sheet an operator picks a face
     * on while standing at Gate 2, and the stepper still shows four.
     */
    public function characters(Story $story): View
    {
        return view('stories.characters', ['story' => $story, 'gate' => Gate::Scenes]);
    }

    /**
     * Every still a character appears in, side by side, in story order.
     *
     * Built for one question: does a face drift? That is the single biggest
     * quality risk in this format and it is the hardest to see in the medium it
     * happens in — a drift between scene 40 and scene 90 is thirty minutes
     * apart in the video and invisible while watching, but obvious when the two
     * frames are adjacent.
     *
     * Grouped per character rather than by scene, because the comparison that
     * matters is one person against themselves across the whole runtime, not
     * one scene against its neighbour.
     *
     * The endpoint is marked per still because the two are not equally at risk.
     * A frame with a cast goes through `edit` conditioned on approved
     * references; a frame with nobody in it has no face to hold and goes
     * through text-to-image. Mixing them in one grid without saying which is
     * which would attribute a style difference to drift.
     */
    public function faces(Story $story): View
    {
        $cast = $story->characters()
            ->with(['scenes' => fn ($q) => $q->orderBy('sequence')])
            ->orderBy('name')
            ->get();

        // Scenes nobody is in: the text-to-image set, shown as its own group
        // so it can be judged against the referenced frames rather than lost
        // among them.
        $castless = $story->scenes()
            ->whereDoesntHave('characters')
            ->orderBy('sequence')
            ->get();

        return view('stories.faces', [
            'story' => $story,
            'cast' => $cast,
            'castless' => $castless,
            'sceneCount' => $story->scenes()->count(),
        ]);
    }

    /**
     * Serve one candidate reference, for the picker.
     *
     * Same posture as the scene stills: the disk is not public and is not going
     * to become public. A character's face is generated from a description the
     * operator wrote and lives on a path derived from database ids, and a
     * listable directory would undo the care taken everywhere else to keep
     * operator text off the filesystem.
     */
    public function candidate(Story $story, CharacterReference $reference): StreamedResponse
    {
        abort_unless($reference->character?->story_id === $story->id, 404);
        abort_unless($reference->exists(), 404, 'That candidate is no longer on disk.');

        $bytes = $reference->bytes();

        return response()->stream(function () use ($bytes): void {
            echo $bytes;
        }, 200, [
            'Content-Type' => $reference->mimeType(),
            'Content-Length' => (string) strlen($bytes),
            // A candidate never changes under a fixed id — a new round is a new
            // batch, never an overwrite — so this is safe to cache hard.
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function preview(Story $story): View
    {
        return view('stories.gate', ['story' => $story, 'gate' => Gate::Preview]);
    }

    public function metadata(Story $story): View
    {
        return view('stories.gate', ['story' => $story, 'gate' => Gate::Metadata]);
    }

    /**
     * Stream the finished render to the browser.
     *
     * Range requests matter here rather than being a nicety: Gate 3 is 30-40
     * minutes long and the operator will scrub through it. Without range
     * support the browser re-downloads from the start on every seek.
     */
    public function video(Story $story): BinaryFileResponse
    {
        $path = RenderWorkspace::for($story)->path('final.mp4');

        abort_unless(is_readable($path), 404, 'No finished render for this story.');

        return response()->file($path, [
            'Content-Type' => 'video/mp4',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /**
     * Serve one scene's still, for the scene list and the thumbnail picker.
     */
    public function still(Story $story, Scene $scene): StreamedResponse
    {
        abort_unless($scene->story_id === $story->id, 404);

        $path = RenderWorkspace::for($story)->sourcePath((string) $scene->image_path);

        abort_unless($scene->image_path !== null && is_readable($path), 404, 'No still for this scene.');

        return response()->stream(function () use ($path): void {
            readfile($path);
        }, 200, [
            // From the file, not assumed. fal answers a PNG request with a
            // JPEG, so a hardcoded image/png describes most of this story's
            // stills wrongly — the same "declared, not observed" mistake the
            // provider layer already had to learn.
            'Content-Type' => match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                default => 'image/png',
            },
            'Content-Length' => (string) filesize($path),
            // Stills do not change under a fixed path, and a 200-scene page
            // would otherwise re-fetch every one of them on each render.
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Serve one composed thumbnail candidate.
     *
     * The compositions live in the render workspace, which is not a public
     * disk — same as the stills and the finished video. The key is matched
     * against the stored options rather than used as a path: it arrives from
     * the URL, and a key that could name a file would be a path the visitor
     * chooses.
     */
    public function thumbnail(Story $story, string $key): StreamedResponse
    {
        $option = collect($story->youtubeMetadata?->thumbnail_options ?? [])
            ->first(fn (array $o): bool => ($o['key'] ?? null) === $key);

        abort_if($option === null, 404, 'No such thumbnail composition for this story.');

        $path = (string) ($option['path'] ?? '');

        abort_unless(is_readable($path), 404, 'That composition has not been written to disk.');

        return response()->stream(function () use ($path): void {
            readfile($path);
        }, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) filesize($path),
            // Deliberately NOT cached. Re-composing writes over the same four
            // filenames, so a cached candidate would show the operator the
            // previous run's picture next to the current run's reasoning.
            'Cache-Control' => 'no-store',
        ]);
    }

    private function lastPassedGate(Story $story): Gate
    {
        $passed = Gate::Outline;

        foreach (Gate::cases() as $gate) {
            if ($story->hasPassedGate($gate)) {
                $passed = $gate;
            }
        }

        return $passed;
    }
}
