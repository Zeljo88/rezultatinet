<?php
// Lookup-only transport. No importer, Eloquent, query builder, or app bootstrap.
function hnlPreviewTransport(\App\Services\ApiFootball\ApiFootballGateway $gateway, ?\Illuminate\Http\Client\Factory $factory = null): array
{
    $claims = 0;
    $responses = [];
    $factory ??= new \Illuminate\Http\Client\Factory;
    $factory->globalOptions(['allow_redirects' => false]);
    $factory->globalResponseMiddleware(function ($response) use (&$responses) {
        $body = (string) $response->getBody();
        $responses[] = ['status' => $response->getStatusCode(), 'bytes' => strlen($body),
            'sha256' => hash('sha256', $body),
            'envelope' => strlen($body) <= 131072 ? json_decode($body, true) : null];
        return $response;
    });
    \Illuminate\Support\Facades\Http::swap($factory);
    $error = null;
    try {
        $gateway->get('/standings', ['league' => 210, 'season' => 2026], 'standings', 'HnlCoveragePreview20261021',
            static function () use (&$claims): bool { return ++$claims === 1; });
    } catch (\Throwable $e) {
        $error = get_class($e); // Never retain exception messages or request headers.
    }
    return ['at' => gmdate('c'), 'claim_hook_invocations' => $claims,
        'completed_responses' => count($responses), 'error_type' => $error, 'responses' => $responses];
}
