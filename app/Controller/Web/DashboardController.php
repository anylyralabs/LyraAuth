<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Controller\AbstractController;
use App\Model\OauthConsent;
use App\Model\Organization;
use App\Model\User;
use App\Service\MfaService;
use App\Service\SessionGuard;
use App\Support\View as ViewSupport;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use App\Middleware\AuthenticateMiddleware;
use Hyperf\View\RenderInterface;
use Psr\Http\Message\ResponseInterface;

#[Controller]
#[Middleware(AuthenticateMiddleware::class)]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly RenderInterface $view,
        private readonly SessionGuard $guard,
        private readonly MfaService $mfa
    ) {
    }

    #[GetMapping(path: '/dashboard')]
    public function index(): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();

        $sessions = $user->sessions()
            ->whereNull('revoked_at')
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->orderByDesc('last_activity_at')
            ->limit(5)
            ->get();

        $consents = OauthConsent::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->orderByDesc('last_granted_at')
            ->limit(5)
            ->get();

        $clientIds = $consents->pluck('client_id')->all();
        $clients = $clientIds === []
            ? new \Hyperf\Collection\Collection()
            : \App\Model\OauthClient::query()->whereIn('id', $clientIds)->get()->keyBy('id');

        $organizations = Organization::query()
            ->whereHas('members', fn ($query) => $query->where('user_id', $user->id))
            ->limit(5)
            ->get();

        return $this->view->render('dashboard.index', [
            'pageTitle' => '概览',
            'pageDescription' => '账号状态与最近活动一览。',
            'navSection' => 'dashboard',
            'currentUser' => $user,
            'isAdmin' => $user->isAdmin(),
            'summary' => $this->mfa->summary($user),
            'sessions' => $sessions,
            'consents' => $consents,
            'clients' => $clients,
            'organizations' => $organizations,
            'currentSessionId' => $this->guard->currentSessionId(),
            'mustChangePassword' => (bool) $user->must_change_password,
            'emailVerified' => $user->isEmailVerified(),
        ]);
    }
}
