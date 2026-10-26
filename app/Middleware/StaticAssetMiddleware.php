<?php

declare(strict_types=1);

namespace App\Middleware;

use Hyperf\HttpMessage\Stream\SwooleFileStream;
use Hyperf\HttpServer\Router\Dispatched;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 静态资源中间件。
 *
 * Hyperf 不自带静态文件服务，且项目不使用 nginx，因此由应用直接响应
 * /assets/* 与根目录下的静态图标。路径做规范化与真实路径校验，防止目录遍历。
 */
class StaticAssetMiddleware implements MiddlewareInterface
{
    private const ALLOWED_EXTENSIONS = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'svg' => 'image/svg+xml; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'map' => 'application/json; charset=utf-8',
    ];

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() !== 'GET' && $request->getMethod() !== 'HEAD') {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        if (! str_starts_with($path, '/assets/')) {
            return $handler->handle($request);
        }

        $response = $this->serve($path, $request->getMethod() === 'HEAD');
        if ($response === null) {
            return $handler->handle($request);
        }

        return $response;
    }

    private function serve(string $path, bool $headOnly): ?ResponseInterface
    {
        $relative = substr($path, strlen('/assets/'));
        $relative = rawurldecode($relative);

        if ($relative === '' || str_contains($relative, "\0")) {
            return null;
        }
        // 只允许安全字符，拒绝 .. 与绝对路径
        if (preg_match('#^[A-Za-z0-9._/-]+$#', $relative) !== 1 || str_contains($relative, '..')) {
            return null;
        }

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        $contentType = self::ALLOWED_EXTENSIONS[$extension] ?? null;
        if ($contentType === null) {
            return null;
        }

        $base = realpath(BASE_PATH . '/public/assets');
        $file = realpath(BASE_PATH . '/public/assets/' . $relative);
        if ($base === false || $file === false || ! is_file($file) || ! str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $response = \Hyperf\Context\ResponseContext::get()
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Cache-Control', 'public, max-age=86400')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        if ($headOnly) {
            return $response->withHeader('Content-Length', (string) filesize($file));
        }

        return $response->withBody(new SwooleFileStream($file));
    }
}
