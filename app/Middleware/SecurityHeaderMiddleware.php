<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Csp;
use Hyperf\Context\ResponseContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 统一安全响应头与请求编号。
 *
 * 全站禁内联脚本（页面脚本均为外部文件），表单页禁止被 iframe 嵌套以防点击劫持。
 *
 * 注意 CSP 的 form-action：浏览器不只校验表单的 action 属性，还会校验
 * **整条重定向链的终点**。登录 POST 的链路是
 * `POST /login → 302 /oauth/authorize → 302 <客户端 redirect_uri>`，
 * 最后一段跨源，会被 `form-action 'self'` 拦掉——服务端已经登录成功，
 * 浏览器却把这次导航丢弃，表现为「点登录没反应、地址不变」。
 *
 * 因此白名单由渲染表单的控制器（登录页 / MFA 页 / 同意页 / 登出页）
 * 通过 {@see Csp::allowFormActionForClient()} 登记，这里只负责汇总输出；
 * 未登记时等价于原来的 `form-action 'self'`。
 */
class SecurityHeaderMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = bin2hex(random_bytes(8));

        // 请求开始时清空，避免常驻内存下串到下一个请求
        Csp::reset();

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
                    'form-action ' . implode(' ', Csp::formActionSources()),
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
