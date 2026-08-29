<?php

namespace App\Http\Controllers;

use App\Models\Story;
use App\Support\RenderProgress;
use Illuminate\Contracts\View\View;

/**
 * The batch progress page — this project's replacement for Horizon's dashboard.
 *
 * Read-only. It reports what the queue is doing; it does not drive it. The four
 * operator gates get their own UI in a later phase, and nothing here crosses
 * one.
 */
class RenderProgressController extends Controller
{
    public function index(): View
    {
        return view('renders.index', [
            'rows' => RenderProgress::index(),
        ]);
    }

    public function show(string $slug): View
    {
        $story = Story::query()->where('slug', $slug)->firstOrFail();

        return view('renders.show', RenderProgress::for($story));
    }
}
