<?php

namespace App\Providers;

use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The admin panel is Russian-only; make Carbon's locale-aware output
        // (diffForHumans(), translatedFormat(), etc.) match instead of defaulting to English.
        Carbon::setLocale(config('app.locale'));
    }
}
