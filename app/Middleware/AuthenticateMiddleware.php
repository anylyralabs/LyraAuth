<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\SessionGuard;
use App\Support\View;
use Hyperf\Context\ResponseContext;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 要求登录。
 *
 * 浏览器请求重定向到登录页并记住来源；非 HTML 请求返回 401 JSON。
 */
class AuthenticateMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SessionGuard $guard)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->guard->check()) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        $acceptsHtml = str_contains(strtolower($request->getHeaderLine('Accept')), 'text/html');

        if (! $acceptsHtml) {
            return ResponseContext::get()
                ->withStatus(401)
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withBody(new SwooleStream(json_encode([
                    'error' => 'login_required',
                    'message' => '需要登录后才能访问。',
                ], JSON_UNESCAPED_UNICODE)));
        }

        $target = View::safeRedirect($path . ($request->getUri()->getQuery() !== '' ? '?' . $request->getUri()->getQuery() : ''), '/dashboard');
        $this->guard->rememberIntended($target);

        return ResponseContext::get()
            ->withStatus(302)
            ->withHeader('Location', '/login');
    }
}
