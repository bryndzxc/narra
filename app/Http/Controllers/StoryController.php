<?php

namespace App\Http\Controllers;

use App\Enums\Gate;
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
        return view('stories.index', [
            'stories' => Story::query()
                ->withCount('scenes')
                ->orderByDesc('updated_at')
                ->paginate(25),
        ]);
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
            'Content-Type' => 'image/png',
            'Content-Length' => (string) filesize($path),
            // Stills do not change under a fixed path, and a 200-scene page
            // would otherwise re-fetch every one of them on each render.
            'Cache-Control' => 'private, max-age=3600',
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
