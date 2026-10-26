<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\View as ViewSupport;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\View\RenderInterface;
use Psr\Http\Message\ResponseInterface;

#[Controller]
class HomeController extends AbstractController
{
    public function __construct(private readonly RenderInterface $render)
    {
    }

    #[GetMapping(path: '/')]
    public function index(): ResponseInterface
    {
        return $this->render->render('home', [
            'pageTitle' => '统一身份认证平台',
        ]);
    }

    #[GetMapping(path: '/favicon.svg')]
    public function favicon(): ResponseInterface
    {
        $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32" height="32">
  <rect width="32" height="32" rx="8" fill="#2F4D5E"/>
  <path d="M9 21.5V10.5" stroke="#F5F6F7" stroke-width="2" stroke-linecap="round"/>
  <path d="M9 16.2L15.4 10.5" stroke="#F5F6F7" stroke-width="2" stroke-linecap="round"/>
  <path d="M9 16.2L16 21.5" stroke="#F5F6F7" stroke-width="2" stroke-linecap="round"/>
  <circle cx="22.5" cy="16" r="3.2" stroke="#9DB6C4" stroke-width="1.6"/>
</svg>
SVG;

        return $this->response
            ->withHeader('Content-Type', 'image/svg+xml; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=604800')
            ->raw($svg);
    }
}
