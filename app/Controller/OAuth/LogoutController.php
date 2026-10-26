<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use App\Controller\AbstractController;
use App\Model\OauthAccessToken;
use App\Model\OauthClient;
use App\Model\OauthRefreshToken;
use App\Model\User;
use App\Service\AuditService;
use App\Service\SessionGuard;
use App\Service\SettingsService;
use App\Support\View as ViewSupport;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\View\RenderInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * RP 发起的登出（OIDC RP-Initiated Logout）。
 *
 * 结束 IdP 会话并吊销该会话签发的令牌，然后只允许回跳到已注册的
 * post_logout_redirect_uri，并附带 state。
 */
#[Controller]
class LogoutController extends AbstractController
{
    public function __construct(
        private readonly RenderInterface $view,
        private readonly SessionGuard $guard,
        private readonly AuditService $audit,
        private readonly SettingsService $settings
    ) {
    }

    #[GetMapping(path: '/oauth/logout')]
    public function show(): ResponseInterface
    {
        $client = $this->resolveClient((string) $this->request->query('client_id', ''));
        $redirect = (string) $this->request->query('post_logout_redirect_uri', '');
        $state = (string) $this->request->query('state', '');
        $idTokenHint = (string) $this->request->query('id_token_hint', '');

        // 未登录时直接回跳（已无会话可结束）
        if (! $this->guard->check()) {
            return $this->finish($client, $redirect, $state);
        }

        return $this->view->render('auth.logout', [
            'pageTitle' => '退出登录',
            'clientName' => $client?->name,
            'clientId' => $client?->client_id,
            'clientColor' => $client?->logo_color ?? '#2F4D5E',
            'redirect' => $redirect,
            'state' => $state,
            'idTokenHint' => $idTokenHint,
            'hasClient' => $client !== null,
        ]);
    }

    #[PostMapping(path: '/oauth/logout')]
    public function destroy(): ResponseInterface
    {
        /** @var User|null $user */
        $user = $this->guard->user();
        $sessionId = $this->guard->currentSessionId();

        $client = $this->resolveClient((string) $this->request->input('client_id', ''));
        $redirect = (string) $this->request->input('post_logout_redirect_uri', '');
        $state = (string) $this->request->input('state', '');

        if ($sessionId !== null) {
            // 吊销本次会话签发的所有令牌，实现单点登出
            OauthAccessToken::query()
                ->where('session_id', $sessionId)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => date('Y-m-d H:i:s')]);

            if ($user !== null) {
                OauthRefreshToken::query()
                    ->where('user_id', $user->id)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => date('Y-m-d H:i:s')]);
            }
        }

        $this->guard->logout();
        $this->audit->log('auth.logout.sso', 'success', $user, 'session', $sessionId);

        return $this->finish($client, $redirect, $state);
    }

    /**
     * 回跳校验：必须与客户端注册的 post_logout_redirect_uri 精确匹配。
     */
    private function finish(?OauthClient $client, string $redirect, string $state): ResponseInterface
    {
        if ($client !== null && $redirect !== '' && $client->hasPostLogoutRedirectUri($redirect)) {
            $url = $redirect;
            if ($state !== '') {
                $url .= (str_contains($url, '?') ? '&' : '?') . 'state=' . rawurlencode($state);
            }

            return $this->redirect($url);
        }

        return $this->redirect('/login');
    }

    private function resolveClient(string $clientId): ?OauthClient
    {
        if ($clientId === '') {
            return null;
        }

        return OauthClient::query()
            ->where('client_id', $clientId)
            ->where('is_active', true)
            ->first();
    }
}
