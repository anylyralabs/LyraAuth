<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    // log = 写入 runtime/logs/mail.log；smtp = 真实投递
    'driver' => env('MAIL_DRIVER', 'log'),
    'host' => (string) env('MAIL_HOST', ''),
    'port' => (int) env('MAIL_PORT', 587),
    'username' => (string) env('MAIL_USERNAME', ''),
    'password' => (string) env('MAIL_PASSWORD', ''),
    // tls = STARTTLS（587）；ssl = 隐式 TLS（465）；留空 = 明文（仅限内网/本地测试）
    'encryption' => (string) env('MAIL_ENCRYPTION', 'tls'),
    // login = 用户名+密码（AUTH LOGIN）；xoauth2 = 微软 OAuth2（AUTH XOAUTH2）
    'auth_method' => (string) env('MAIL_AUTH_METHOD', 'login'),
    // 是否校验服务器证书；自签证书或内网测试服务器可设为 false
    'verify_peer' => (bool) env('MAIL_VERIFY_PEER', true),
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'no-reply@lyraauth.local'),
        'name' => env('MAIL_FROM_NAME', 'LyraAuth'),
    ],
];
