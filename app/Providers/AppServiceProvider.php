<?php

namespace App\Providers;

use App\Services\Ffmpeg;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Ffmpeg::class, fn (): Ffmpeg => new Ffmpeg(
            ffmpeg: config('render.ffmpeg'),
            ffprobe: config('render.ffprobe'),
            probeTimeout: (int) config('render.timeouts.probe'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
