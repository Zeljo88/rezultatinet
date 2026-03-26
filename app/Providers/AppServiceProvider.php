<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Http\Middleware\SetCacheHeaders;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // SetCacheHeaders must be the OUTERMOST (first) middleware so it handles
        // the response LAST on the way back, after Livewire's DisableBackButtonCacheMiddleware.
        $this->app->booted(function () {
            $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
            // prependMiddleware = position 0 = outermost = last to handle response
            $kernel->prependMiddleware(SetCacheHeaders::class);
        });
    }
}
