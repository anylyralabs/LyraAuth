<?php

declare(strict_types=1);

use App\Middleware\CsrfMiddleware;
use App\Middleware\OAuthContextMiddleware;
use App\Middleware\SecurityHeaderMiddleware;
use App\Middleware\StaticAssetMiddleware;
use Hyperf\Session\Middleware\SessionMiddleware;

return [
    'http' => [
        OAuthContextMiddleware::class,
        SecurityHeaderMiddleware::class,
        StaticAssetMiddleware::class,
        SessionMiddleware::class,
        CsrfMiddleware::class,
    ],
];
