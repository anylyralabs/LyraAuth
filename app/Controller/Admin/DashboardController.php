<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Middleware\AdminMiddleware;
use App\Middleware\AuthenticateMiddleware;
use App\Model\AuditLog;
use App\Model\LoginAttempt;
use App\Model\OauthClient;
use App\Model\OauthRefreshToken;
use App\Model\Organization;
use App\Model\User;
use App\Service\SessionGuard;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\View\RenderInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 管理概览。
 */
#[Controller]
#[Middleware(AuthenticateMiddleware::class)]
#[Middleware(AdminMiddleware::class)]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly RenderInterface $view,
        private readonly SessionGuard $guard
    ) {
    }

    #[GetMapping(path: '/admin')]
    public function index(): ResponseInterface
    {
        $since = date('Y-m-d H:i:s', time() - 86400);

        $stats = [
            'users' => User::query()->count(),
            'active_users' => User::query()->where('status', User::STATUS_ACTIVE)->count(),
            'clients' => OauthClient::query()->where('is_active', true)->count(),
            'organizations' => Organization::query()->count(),
            'sessions' => \App\Model\UserSession::query()
                ->whereNull('revoked_at')
                ->where('expires_at', '>', date('Y-m-d H:i:s'))
                ->count(),
            'tokens' => OauthRefreshToken::query()
                ->whereNull('revoked_at')
                ->where('expires_at', '>', date('Y-m-d H:i:s'))
                ->count(),
        ];

        $recentLogins = LoginAttempt::query()
            ->where('created_at', '>', $since)
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        $recentAudit = AuditLog::query()->orderByDesc('id')->limit(10)->get();

        $loginStats = [
            'success' => LoginAttempt::query()->where('created_at', '>', $since)->where('result', 'success')->count(),
            'failed' => LoginAttempt::query()->where('created_at', '>', $since)->whereIn('result', ['bad_credentials', 'mfa_failed', 'blocked', 'locked'])->count(),
        ];

        return $this->view->render('admin.dashboard', [
            'pageTitle' => '管理概览',
            'pageDescription' => '平台运行状态与最近活动。',
            'navSection' => 'admin',
            'currentUser' => $this->guard->user(),
            'isAdmin' => true,
            'stats' => $stats,
            'loginStats' => $loginStats,
            'recentLogins' => $recentLogins,
            'recentAudit' => $recentAudit,
        ]);
    }
}
