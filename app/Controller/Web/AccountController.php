<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Controller\AbstractController;
use App\Middleware\AuthenticateMiddleware;
use App\Model\OauthClient;
use App\Model\OauthConsent;
use App\Model\User;
use App\Service\AuditService;
use App\Service\SessionGuard;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\View\RenderInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 个人资料与已授权应用。
 */
#[Controller]
#[Middleware(AuthenticateMiddleware::class)]
class AccountController extends AbstractController
{
    public function __construct(
        private readonly RenderInterface $view,
        private readonly SessionGuard $guard,
        private readonly AuditService $audit
    ) {
    }

    #[GetMapping(path: '/account/profile')]
    public function profile(): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();

        return $this->view->render('account.profile', [
            'pageTitle' => '个人资料',
            'pageDescription' => '这些信息会通过 OIDC 的 profile 与 email scope 提供给已授权的应用。',
            'navSection' => 'profile',
            'currentUser' => $user,
            'isAdmin' => $user->isAdmin(),
            'errors' => [],
            'values' => [
                'display_name' => $user->display_name,
                'locale' => $user->locale,
                'timezone' => $user->timezone,
                'profile_bio' => (string) $user->profile_bio,
                'avatar_color' => $user->avatar_color,
            ],
        ]);
    }

    #[PostMapping(path: '/account/profile')]
    public function updateProfile(): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();

        $displayName = trim((string) $this->request->input('display_name', ''));
        $bio = trim((string) $this->request->input('profile_bio', ''));
        $locale = (string) $this->request->input('locale', 'zh_CN');
        $timezone = (string) $this->request->input('timezone', 'Asia/Shanghai');
        $color = (string) $this->request->input('avatar_color', $user->avatar_color);

        $errors = [];
        if ($displayName === '' || mb_strlen($displayName) > 100) {
            $errors['display_name'] = '请填写显示名称（不超过 100 字）。';
        }
        if (mb_strlen($bio) > 500) {
            $errors['profile_bio'] = '简介不能超过 500 字。';
        }
        if (! in_array($locale, ['zh_CN', 'en'], true)) {
            $errors['locale'] = '语言不在支持范围内。';
        }
        if (! in_array($timezone, ['Asia/Shanghai', 'UTC', 'Asia/Tokyo', 'Europe/London', 'America/New_York'], true)) {
            $errors['timezone'] = '时区不在支持范围内。';
        }

        if ($errors !== []) {
            return $this->view->render('account.profile', [
                'pageTitle' => '个人资料',
                'pageDescription' => '这些信息会通过 OIDC 的 profile 与 email scope 提供给已授权的应用。',
                'navSection' => 'profile',
                'currentUser' => $user,
                'isAdmin' => $user->isAdmin(),
                'errors' => $errors,
                'values' => [
                    'display_name' => $displayName,
                    'locale' => $locale,
                    'timezone' => $timezone,
                    'profile_bio' => $bio,
                    'avatar_color' => $color,
                ],
            ])->withStatus(422);
        }

        $user->display_name = mb_substr($displayName, 0, 100);
        $user->profile_bio = $bio !== '' ? mb_substr($bio, 0, 500) : null;
        $user->locale = $locale;
        $user->timezone = $timezone;
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1) {
            $user->avatar_color = strtoupper($color);
        }
        $user->save();

        $this->audit->log('account.profile.updated', 'success', $user, 'user', (string) $user->id);
        $this->guard->flash('success', '资料已更新。');

        return $this->redirect('/account/profile');
    }

    #[GetMapping(path: '/account/apps')]
    public function apps(): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();

        $consents = OauthConsent::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->orderByDesc('last_granted_at')
            ->get();

        $clients = $consents->isEmpty()
            ? new \Hyperf\Collection\Collection()
            : OauthClient::query()->whereIn('id', $consents->pluck('client_id')->all())->get()->keyBy('id');

        return $this->view->render('account.apps', [
            'pageTitle' => '已授权应用',
            'pageDescription' => '这些应用可以以你的身份访问相应信息，随时可以撤销。',
            'navSection' => 'apps',
            'currentUser' => $user,
            'isAdmin' => $user->isAdmin(),
            'consents' => $consents,
            'clients' => $clients,
        ]);
    }

    #[PostMapping(path: '/account/apps/{id}/revoke')]
    public function revokeApp(int $id): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();

        $consent = OauthConsent::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if ($consent === null) {
            $this->guard->flash('error', '授权记录不存在。');

            return $this->redirect('/account/apps');
        }

        $consent->revoked_at = date('Y-m-d H:i:s');
        $consent->save();

        // 同时撤销该应用签发的令牌
        \App\Model\OauthAccessToken::query()
            ->where('user_id', $user->id)
            ->where('client_id', $consent->client_id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);

        \App\Model\OauthRefreshToken::query()
            ->where('user_id', $user->id)
            ->where('client_id', $consent->client_id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);

        $this->audit->log('account.consent.revoked', 'success', $user, 'oauth_client', (string) $consent->client_id);
        $this->guard->flash('success', '已撤销该应用的访问权限。');

        return $this->redirect('/account/apps');
    }
}
