<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\OAuthContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 每个请求开始时清空 OAuth 请求级上下文。
 *
 * OAuthContext 由容器缓存（进程内单例），而 Hyperf 是常驻内存模型，
 * 若不清空会把上一个请求的 sid / nonce 带到下一个请求。
 */
class OAuthContextMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly OAuthContext $context)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->context->clear();

        return $handler->handle($request);
    }
}
