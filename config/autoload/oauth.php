<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    // 令牌与授权码有效期（秒）
    'access_token_ttl' => (int) env('OAUTH_ACCESS_TOKEN_TTL', 900),
    'refresh_token_ttl' => (int) env('OAUTH_REFRESH_TOKEN_TTL', 2592000),
    'auth_code_ttl' => (int) env('OAUTH_AUTH_CODE_TTL', 60),
    'device_code_ttl' => (int) env('OAUTH_DEVICE_CODE_TTL', 600),

    // 签发者标识；为空时使用 app_url
    'issuer' => (string) env('OAUTH_ISSUER', ''),
];
