<?php

declare(strict_types=1);

namespace App\Middleware;

use Hyperf\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 追加额外的 Set-Cookie 响应头。
 *
 * 存在原因：Hyperf\Session\Middleware\SessionMiddleware 在控制器之后执行，
 * 且用 withHeader('Set-Cookie', ...) 写入会话 Cookie —— 这会整条替换 Set-Cookie，
 * 把控制器设置的 Cookie（例如“记住此设备”）覆盖掉。
 *
 * 因此控制器不直接改响应，而是把 Cookie 放入协程上下文；
 * 本中间件位于中间件链最外层，在所有内层中间件之后追加，不会相互覆盖。
 */
class PendingCookieMiddleware implements MiddlewareInterface
{
    /** 协程上下文键：待追加的 Set-Cookie 值列表 */
    public const CONTEXT_KEY = 'pending_set_cookies';

    public static function add(string $cookie): void
    {
        $cookies = Context::get(self::CONTEXT_KEY, []);
        if (! is_array($cookies)) {
            $cookies = [];
        }
        $cookies[] = $cookie;
        Context::set(self::CONTEXT_KEY, $cookies);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $cookies = Context::get(self::CONTEXT_KEY, []);
        // 请求结束即清理，避免常驻内存下串到下一个请求
        Context::destroy(self::CONTEXT_KEY);

        if (! is_array($cookies)) {
            return $response;
        }

        foreach ($cookies as $cookie) {
            if (is_string($cookie) && $cookie !== '') {
                $response = $response->withAddedHeader('Set-Cookie', $cookie);
            }
        }

        return $response;
    }
}
