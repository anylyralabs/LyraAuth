<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Middleware\AdminMiddleware;
use App\Middleware\AuthenticateMiddleware;
use App\Model\Organization;
use App\Model\OrganizationMember;
use App\Service\SessionGuard;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\View\RenderInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 组织总览（管理员）。
 */
#[Controller]
#[Middleware(AuthenticateMiddleware::class)]
#[Middleware(AdminMiddleware::class)]
class OrganizationController extends AbstractController
{
    public function __construct(
        private readonly RenderInterface $view,
        private readonly SessionGuard $guard
    ) {
    }

    #[GetMapping(path: '/admin/organizations')]
    public function index(): ResponseInterface
    {
        $organizations = Organization::query()->orderByDesc('id')->paginate(20);

        $memberCounts = OrganizationMember::query()
            ->selectRaw('organization_id, COUNT(*) as total')
            ->groupBy('organization_id')
            ->pluck('total', 'organization_id')
            ->all();

        return $this->view->render('admin.organizations.index', [
            'pageTitle' => '组织',
            'pageDescription' => '全部组织及其成员规模。',
            'navSection' => 'admin-orgs',
            'currentUser' => $this->guard->user(),
            'isAdmin' => true,
            'organizations' => $organizations,
            'memberCounts' => $memberCounts,
        ]);
    }
}
