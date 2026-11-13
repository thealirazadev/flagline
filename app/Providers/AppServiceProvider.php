<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // Keyed by a hash of the presented key, not the resolved environment: the throttle
        // runs before authentication, and this way one noisy key cannot starve another
        // while guesses at unknown keys are throttled too.
        RateLimiter::for('sdk', fn (Request $request) => Limit::perMinute((int) config('flagline.sdk_rate_limit'))
            ->by('sdk:'.hash('sha256', (string) $request->bearerToken())));
    }
}
