<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Runtime requirements are checked by /ready and app:validate-runtime. Keeping validation
        // out of provider boot allows dependency installation and recovery commands to run before
        // production secrets are injected.
    }
}
