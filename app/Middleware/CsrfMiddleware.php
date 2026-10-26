<?php

declare(strict_types=1);

namespace App\Middleware;

use Hyperf\Contract\SessionInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Router\Dispatched;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CSRF 防护。
 *
 * Hyperf 没有官方 csrf 组件，这里对全部非幂等请求校验隐藏字段 _token。
 * 令牌采用会话内单一密钥 + hash_equals 比较，登录后重新生成。
 */
class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    private const TOKEN_FIELD = '_token';

    /** 无需校验的路径：OAuth 令牌端点是机器对机器调用，靠客户端认证保护 */
    private const EXEMPT_PATHS = [
        '/oauth/token',
        '/oauth/introspect',
        '/oauth/revoke',
        '/oauth/device_authorization',
        '/.well-known/openid-configuration',
        '/.well-known/oauth-authorization-server',
        '/.well-known/jwks.json',
        '/userinfo',
    ];

    public function __construct(
        private readonly SessionInterface $session,
        private readonly RequestInterface $request
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        if (in_array($path, self::EXEMPT_PATHS, true)) {
            return $handler->handle($request);
        }

        if (! $this->session->isStarted()) {
            return $this->reject($request, '会话不可用，请重新打开页面后再试。');
        }

        $expected = $this->session->get('_csrf_token');
        if (! is_string($expected) || $expected === '') {
            return $this->reject($request, '安全令牌缺失，请刷新页面后重试。');
        }

        $submitted = $this->submittedToken($request);
        if (! is_string($submitted) || $submitted === '' || ! hash_equals($expected, $submitted)) {
            return $this->reject($request, '安全令牌无效或已过期，请刷新页面后重试。');
        }

        return $handler->handle($request);
    }

    private function submittedToken(ServerRequestInterface $request): ?string
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && isset($parsed[self::TOKEN_FIELD]) && is_string($parsed[self::TOKEN_FIELD])) {
            return $parsed[self::TOKEN_FIELD];
        }

        $header = $request->getHeaderLine('X-CSRF-TOKEN');

        return $header !== '' ? $header : null;
    }

    private function reject(ServerRequestInterface $request, string $message): ResponseInterface
    {
        $acceptsHtml = str_contains(strtolower($request->getHeaderLine('Accept')), 'text/html');

        if ($acceptsHtml) {
            return \Hyperf\Context\ResponseContext::get()
                ->withStatus(419)
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(
                    '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>页面已过期</title></head>'
                    . '<body style="font-family:system-ui;padding:48px;color:#131a21">'
                    . '<h1 style="font-size:18px">页面已过期</h1>'
                    . '<p style="color:#66727f;font-size:14px">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
                    . '<p><a href="/" style="color:#2f4d5e">返回首页</a></p>'
                    . '</body></html>'
                ));
        }

        return \Hyperf\Context\ResponseContext::get()
            ->withStatus(419)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream(
                json_encode(['error' => 'csrf_token_mismatch', 'message' => $message], JSON_UNESCAPED_UNICODE)
            ));
    }
}
