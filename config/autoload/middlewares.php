<?php

declare(strict_types=1);

use App\Middleware\CsrfMiddleware;
use App\Middleware\OAuthContextMiddleware;
use App\Middleware\PendingCookieMiddleware;
use App\Middleware\SecurityHeaderMiddleware;
use App\Middleware\StaticAssetMiddleware;
use Hyperf\Session\Middleware\SessionMiddleware;

return [
    'http' => [
        // 必须最外层：SessionMiddleware 会用 withHeader 覆盖 Set-Cookie，
        // 这里在其后才能把额外 Cookie 追加回去。
        PendingCookieMiddleware::class,
        OAuthContextMiddleware::class,
        SecurityHeaderMiddleware::class,
        StaticAssetMiddleware::class,
        SessionMiddleware::class,
        CsrfMiddleware::class,
    ],
];
