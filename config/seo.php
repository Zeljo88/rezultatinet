<?php

return [
    /*
    | Redirect non-canonical web requests to APP_URL in production. This stays
    | disabled by default in local/test environments.
    */
    'enforce_canonical_host' => (bool) env(
        'SEO_ENFORCE_CANONICAL_HOST',
        env('APP_ENV', 'production') === 'production',
    ),
];
