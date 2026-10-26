<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\SessionGuard;
use Hyperf\Context\ResponseContext;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\View\RenderInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * 要求管理员身份。
 *
 * 必须置于 AuthenticateMiddleware 之后。
 */
class AdminMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionGuard $guard,
        private readonly RenderInterface $render
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->guard->user();
        if ($user !== null && $user->isAdmin()) {
            return $handler->handle($request);
        }

        $acceptsHtml = str_contains(strtolower($request->getHeaderLine('Accept')), 'text/html');

        if (! $acceptsHtml) {
            return ResponseContext::get()
                ->withStatus(403)
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withBody(new SwooleStream(json_encode([
                    'error' => 'forbidden',
                    'message' => '需要管理员权限。',
                ], JSON_UNESCAPED_UNICODE)));
        }

        try {
            $html = $this->render->getContents('errors.error', [
                'statusCode' => '403',
                'heading' => '没有访问权限',
                'detail' => '当前账号无权访问管理后台，如有疑问请联系站长。',
                'showLogin' => false,
                'pageTitle' => '没有访问权限',
            ]);

            return ResponseContext::get()
                ->withStatus(403)
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withBody(new SwooleStream($html));
        } catch (Throwable) {
            return ResponseContext::get()
                ->withStatus(403)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withBody(new SwooleStream('403 Forbidden'));
        }
    }
}
