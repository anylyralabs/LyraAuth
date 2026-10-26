<?php

declare(strict_types=1);

use Hyperf\Contract\StdoutLoggerInterface;
use Psr\Log\LogLevel;

use function Hyperf\Support\env;

return [
    'app_name' => env('APP_NAME', 'LyraAuth'),
    'app_env' => env('APP_ENV', 'dev'),
    'scan_cacheable' => env('SCAN_CACHEABLE', false),
    // 站点基础信息
    'app_url' => rtrim((string) env('APP_URL', 'http://localhost:9501'), '/'),
    'app_key' => (string) env('APP_KEY', ''),
    'app_installed' => (bool) env('APP_INSTALLED', false),
    StdoutLoggerInterface::class => [
        'log_level' => [
            LogLevel::ALERT,
            LogLevel::CRITICAL,
            LogLevel::EMERGENCY,
            LogLevel::ERROR,
            LogLevel::WARNING,
        ],
    ],
];
