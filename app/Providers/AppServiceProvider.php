<?php

namespace App\Providers;

use App\Contracts\ApiFootballQuotaStore;
use App\Http\Middleware\SetCacheHeaders;
use App\Services\ApiFootball\RedisApiFootballQuotaStore;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ApiFootballQuotaStore::class, RedisApiFootballQuotaStore::class);
    }

    public function boot(): void
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        $scheme = parse_url($appUrl, PHP_URL_SCHEME);

        if ($appUrl !== '') {
            URL::forceRootUrl($appUrl);
        }

        if (is_string($scheme) && $scheme !== '') {
            URL::forceScheme($scheme);
        }

        // SetCacheHeaders must be the OUTERMOST (first) middleware so it handles
        // the response LAST on the way back, after Livewire's DisableBackButtonCacheMiddleware.
        $this->app->booted(function () {
            $kernel = $this->app->make(Kernel::class);
            // prependMiddleware = position 0 = outermost = last to handle response
            $kernel->prependMiddleware(SetCacheHeaders::class);
        });
    }
}
