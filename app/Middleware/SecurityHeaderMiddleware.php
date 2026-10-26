<?php

declare(strict_types=1);

namespace App\Middleware;

use Hyperf\Context\ResponseContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 统一安全响应头与请求编号。
 *
 * 全站禁内联脚本（页面脚本均为外部文件），表单页禁止被 iframe 嵌套以防点击劫持。
 */
class SecurityHeaderMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = bin2hex(random_bytes(8));
        $response = $handler->handle($request);

        $isHttps = strtolower($request->getUri()->getScheme()) === 'https';

        $response = $response
            ->withHeader('X-Request-Id', $requestId)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()')
            ->withHeader(
                'Content-Security-Policy',
                implode('; ', [
                    "default-src 'self'",
                    "script-src 'self'",
                    "style-src 'self' 'unsafe-inline'",
                    "img-src 'self' data:",
                    "font-src 'self'",
                    "connect-src 'self'",
                    "form-action 'self'",
                    "frame-ancestors 'none'",
                    "base-uri 'none'",
                    "object-src 'none'",
                ])
            );

        if ($isHttps) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
