<?php

use App\Http\Controllers\RenderProgressController;
use App\Http\Controllers\StoryController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('stories.index'));

/*
 * The operator's four gates. Every one of them is a human decision the app is
 * not allowed to make: the outline, the scenes, the render, the publish sheet.
 * There is no route here that generates and uploads, and there never will be.
 *
 * Unauthenticated for now: single-operator internal tool, no user model in the
 * spec, and auth scaffolding before there are users is exactly what the spec
 * says not to build.
 */
Route::get('/stories', [StoryController::class, 'index'])->name('stories.index');

Route::prefix('/stories/{story:slug}')->name('stories.')->group(function () {
    Route::get('/', [StoryController::class, 'show'])->name('show');
    Route::get('/outline', [StoryController::class, 'outline'])->name('outline');
    Route::get('/scenes', [StoryController::class, 'scenes'])->name('scenes');
    Route::get('/preview', [StoryController::class, 'preview'])->name('preview');
    Route::get('/metadata', [StoryController::class, 'metadata'])->name('metadata');

    // Neither storage disk is public. These two serve exactly what a gate needs
    // to show and nothing else.
    Route::get('/video', [StoryController::class, 'video'])->name('video');
    Route::get('/scenes/{scene}/still', [StoryController::class, 'still'])->name('still');
});

/*
 * The batch progress page. Horizon is not available on this platform — it hard
 * requires pcntl and posix, which do not exist in Windows PHP — so this is the
 * only window into what the queue workers are doing.
 */
Route::get('/renders', [RenderProgressController::class, 'index'])->name('renders.index');
Route::get('/renders/{slug}', [RenderProgressController::class, 'show'])->name('renders.show');
