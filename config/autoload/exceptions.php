<?php

declare(strict_types=1);

use App\Exception\Handler\AppExceptionHandler;

return [
    'handler' => [
        'http' => [
            // 先于框架的 HttpExceptionHandler 执行：统一渲染 404 / 403 / 500 页面
            AppExceptionHandler::class,
        ],
    ],
];
