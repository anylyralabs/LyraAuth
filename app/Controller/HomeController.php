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
  <rect x="0.5" y="0.5" width="31" height="31" rx="2.5" fill="#14171A"/>
  <path d="M10 23V9" stroke="#F5F5F2" stroke-width="2.1" stroke-linecap="square"/>
  <path d="M10 16.4L16.6 9" stroke="#F5F5F2" stroke-width="2.1" stroke-linecap="square"/>
  <path d="M10 16.4L17.2 23" stroke="#F5F5F2" stroke-width="2.1" stroke-linecap="square"/>
  <circle cx="22.6" cy="16" r="3.1" stroke="#96631C" stroke-width="1.7"/>
</svg>
SVG;

        return $this->response
            ->raw($svg)
            ->withHeader('Content-Type', 'image/svg+xml; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=604800');
    }
}
