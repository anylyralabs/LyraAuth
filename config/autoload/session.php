<?php

declare(strict_types=1);

use Hyperf\Session\Handler\RedisHandler;

use function Hyperf\Support\env;

return [
    'handler' => RedisHandler::class,
    'options' => [
        'connection' => 'default',
        'path' => BASE_PATH . '/runtime/session',
        // 闲置超时（秒），由应用层中间件强制校验
        'gc_maxlifetime' => (int) env('SESSION_LIFETIME', 720) * 60,
        'session_name' => env('SESSION_COOKIE_NAME', 'lyraauth_session'),
        'domain' => null,
        'cookie_lifetime' => (int) env('SESSION_LIFETIME', 720) * 60,
        'cookie_same_site' => 'lax',
    ],
];
