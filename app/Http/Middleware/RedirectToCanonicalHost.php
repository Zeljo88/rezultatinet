<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectToCanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $canonicalRoot = parse_url((string) config('app.url'));
        $canonicalHost = $canonicalRoot['host'] ?? null;

        if (! config('seo.enforce_canonical_host')
            || $canonicalHost === null
            || ! in_array($request->method(), ['GET', 'HEAD'], true)
            || (strcasecmp($request->getHost(), $canonicalHost) === 0 && $request->isSecure())) {
            return $next($request);
        }

        $port = isset($canonicalRoot['port']) ? ':'.$canonicalRoot['port'] : '';

        return redirect()->away(
            'https://'.$canonicalHost.$port.$request->getRequestUri(),
            301,
        );
    }
}
