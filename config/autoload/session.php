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
        // 必须留空（host-only Cookie），不能用 null：
        // Hyperf 在 null 时会回退成 $uri->getHost()，从而输出 Domain=localhost。
        // RFC 6265 §5.2.3 规定单标签主机名不可作为 Cookie Domain，
        // Chromium/Firefox 会直接丢弃整条 Set-Cookie，导致会话根本无法建立。
        'domain' => '',
        'cookie_lifetime' => (int) env('SESSION_LIFETIME', 720) * 60,
        'cookie_same_site' => 'lax',
    ],
];
