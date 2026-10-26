<?php

declare(strict_types=1);

namespace App\Exception\Handler;

use App\Support\View;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\View\RenderInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * 统一异常渲染。
 *
 * 浏览器请求渲染 404 / 403 / 500 页面，OAuth 与 API 请求保持原始状态码与纯文本，
 * 避免把内部细节（文件路径、SQL）暴露给客户端。
 */
class AppExceptionHandler extends ExceptionHandler
{
    public function __construct(
        private readonly StdoutLoggerInterface $logger,
        private readonly RenderInterface $render
    ) {
    }

    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        $statusCode = $throwable instanceof HttpException ? $throwable->getStatusCode() : 500;

        if ($statusCode >= 500) {
            $this->logger->error(sprintf(
                '%s[%s] in %s',
                $throwable->getMessage(),
                $throwable->getLine(),
                $throwable->getFile()
            ));
            $this->logger->error($throwable->getTraceAsString());
        }

        $request = View::currentRequest();
        $path = $request?->getUri()->getPath() ?? '';
        $acceptsHtml = $request !== null
            && str_contains(strtolower($request->getHeaderLine('Accept')), 'text/html');

        if (! $acceptsHtml || str_starts_with($path, '/oauth/') || str_starts_with($path, '/userinfo')) {
            return $response
                ->withStatus($statusCode)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withBody(new SwooleStream($this->plainMessage($statusCode)));
        }

        try {
            $html = $this->render->getContents('errors.error', [
                'statusCode' => (string) $statusCode,
                'heading' => $this->heading($statusCode),
                'detail' => $this->detail($statusCode),
                'showLogin' => $statusCode === 401,
                'pageTitle' => $this->heading($statusCode),
            ]);

            return $response
                ->withStatus($statusCode)
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withBody(new SwooleStream($html));
        } catch (Throwable $renderError) {
            $this->logger->error('Failed to render error page: ' . $renderError->getMessage());

            return $response
                ->withStatus($statusCode)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withBody(new SwooleStream($this->plainMessage($statusCode)));
        }
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }

    private function heading(int $statusCode): string
    {
        return match ($statusCode) {
            400 => '请求无法处理',
            401 => '需要登录',
            403 => '没有访问权限',
            404 => '页面不存在',
            405 => '请求方式不被允许',
            419 => '页面已过期',
            429 => '请求过于频繁',
            default => '出现了一点问题',
        };
    }

    private function detail(int $statusCode): string
    {
        return match ($statusCode) {
            400 => '提交的内容不完整或格式有误，请返回上一页检查后重试。',
            401 => '请先登录后再访问该页面。',
            403 => '当前账号无权访问此资源，如有疑问请联系管理员。',
            404 => '你访问的地址不存在，或者已经被移除。',
            405 => '该地址不支持当前的请求方式。',
            419 => '页面停留时间过长，安全令牌已失效，请刷新后重新提交。',
            429 => '短时间内请求次数过多，请稍后再试。',
            default => '服务暂时无法完成该请求，请稍后重试。',
        };
    }

    private function plainMessage(int $statusCode): string
    {
        return $statusCode >= 500 ? 'Internal Server Error' : 'Request Failed';
    }
}
