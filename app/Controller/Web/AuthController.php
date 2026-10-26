<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Controller\AbstractController;
use App\Model\User;
use App\Service\AuditService;
use App\Service\AuthService;
use App\Service\MfaService;
use App\Service\SessionGuard;
use App\Support\View as ViewSupport;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use App\Middleware\AuthenticateMiddleware;
use Hyperf\View\RenderInterface;
use Psr\Http\Message\ResponseInterface;

#[Controller]
class AuthController extends AbstractController
{
    public function __construct(
        private readonly RenderInterface $view,
        private readonly AuthService $auth,
        private readonly SessionGuard $guard,
        private readonly MfaService $mfa,
        private readonly AuditService $audit
    ) {
    }

    #[GetMapping(path: '/login')]
    public function showLogin(): ResponseInterface
    {
        if ($this->guard->check()) {
            return $this->redirect('/dashboard');
        }

        return $this->renderLogin();
    }

    #[PostMapping(path: '/login')]
    public function login(): ResponseInterface
    {
        if ($this->guard->check()) {
            return $this->redirect('/dashboard');
        }

        $identifier = trim((string) $this->request->input('identifier', ''));
        $password = (string) $this->request->input('password', '');
        $remember = (bool) $this->request->input('remember', false);

        if ($identifier === '' || $password === '') {
            return $this->renderLogin([
                'identifier' => $identifier,
                'error' => '请输入账号与密码。',
            ], 422);
        }

        $ip = $this->audit->clientIp() ?? '0.0.0.0';
        $userAgent = $this->audit->userAgent();

        if ($this->auth->isLocked($identifier, $ip)) {
            $this->audit->log('auth.login.blocked', 'denied', null, null, null, ['identifier' => $identifier]);

            return $this->renderLogin([
                'identifier' => $identifier,
                'error' => '尝试次数过多，请 15 分钟后再试。',
            ], 429);
        }

        $result = $this->auth->attempt($identifier, $password, $ip, $userAgent);

        if (! $result['ok']) {
            $message = match ($result['reason'] ?? '') {
                'locked' => '尝试次数过多，请 15 分钟后再试。',
                'blocked' => '该账号当前不可用，请联系管理员。',
                default => '账号或密码不正确。',
            };

            return $this->renderLogin([
                'identifier' => $identifier,
                'error' => $message,
            ], 401);
        }

        /** @var User $user */
        $user = $result['user'];

        // 需要二次验证：进入 MFA 挑战，尚未真正登录
        if ($this->mfa->requiresChallenge($user)) {
            $rememberDevice = $this->mfa->isTrustedDevice($user);
            if ($rememberDevice) {
                $this->completeLogin($user, $ip, $userAgent, ['pwd', 'trusted_device']);

                return $this->redirect($this->nextUrl());
            }

            $this->guard->beginMfaChallenge($user->id, $remember);
            $this->audit->log('auth.mfa.challenge', 'success', $user, 'user', (string) $user->id);

            return $this->redirect('/mfa');
        }

        $this->completeLogin($user, $ip, $userAgent, ['pwd']);

        return $this->redirect($this->nextUrl());
    }

    #[PostMapping(path: '/logout')]
    public function logout(): ResponseInterface
    {
        $user = $this->guard->user();
        $this->guard->logout();
        $this->audit->log('auth.logout', 'success', $user, 'user', $user !== null ? (string) $user->id : null);

        return $this->redirect('/login');
    }

    /** 登录完成后的收尾：写会话、更新登录信息、清错误 */
    private function completeLogin(User $user, string $ip, ?string $userAgent, array $amr): void
    {
        $this->guard->login($user, $amr, $ip, $userAgent);
        $this->guard->clearErrors();
        $this->auth->touchLogin($user, $ip);
        // 登录审计由 AuthService::recordAttempt 统一写入，此处不重复记录
    }

    private function nextUrl(): string
    {
        return ViewSupport::safeRedirect($this->guard->pullIntended(), '/dashboard');
    }

    private function renderLogin(array $data = [], int $status = 200): ResponseInterface
    {
        $response = $this->view->render('auth.login', array_merge([
            'pageTitle' => '登录',
            'identifier' => '',
            'error' => null,
            'registrationEnabled' => true,
            'notice' => null,
        ], $data));

        return $response->withStatus($status);
    }
}
