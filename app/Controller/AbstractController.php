<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Container\ContainerInterface;

abstract class AbstractController
{
    #[Inject]
    protected ContainerInterface $container;

    #[Inject]
    protected RequestInterface $request;

    #[Inject]
    protected ResponseInterface $response;

    protected function success(string $message): \Psr\Http\Message\ResponseInterface
    {
        return $this->response->json(['ok' => true, 'message' => $message]);
    }

    protected function error(string $message, int $status = 400): \Psr\Http\Message\ResponseInterface
    {
        return $this->response->json(['ok' => false, 'message' => $message], $status);
    }

    protected function redirect(string $location, int $status = 302): \Psr\Http\Message\ResponseInterface
    {
        return $this->response->withStatus($status)->withHeader('Location', $location);
    }
}
