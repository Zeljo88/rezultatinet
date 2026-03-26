<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCacheHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only cache GET/HEAD requests with 2xx responses
        if (!$request->isMethodSafe() || !$response->isSuccessful()) {
            return $response;
        }

        $response->headers->remove('Pragma');
        $response->headers->remove('Expires');

        // Public short-TTL cache — safe because Livewire hydrates on every page load
        $response->headers->set(
            'Cache-Control',
            'public, max-age=30, s-maxage=60, stale-while-revalidate=300'
        );

        return $response;
    }
}
