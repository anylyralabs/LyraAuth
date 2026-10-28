<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Controller\AbstractController;
use App\Middleware\PendingCookieMiddleware;
use App\Model\User;
use App\Service\AuditService;
use App\Service\AuthService;
use App\Service\MfaService;
use App\Service\SessionGuard;
use App\Support\Csp;
use App\Support\View as ViewSupport;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\View\RenderInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 登录过程中的二次验证。
 *
 * 密码校验通过后会话进入“待验证”状态（未登录），完成 TOTP / 恢复码 / Passkey
 * 任一方式后才真正建立登录态。
 */
#[Controller]
class MfaController extends AbstractController
{
    private const TRUSTED_COOKIE = 'lyraauth_trusted';

    public function __construct(
        private readonly RenderInterface $view,
        private readonly SessionGuard $guard,
        private readonly MfaService $mfa,
        private readonly AuthService $auth,
        private readonly AuditService $audit
    ) {
    }

    #[GetMapping(path: '/mfa')]
    public function show(): ResponseInterface
    {
        $userId = $this->guard->pendingMfaUserId();
        if ($userId === null) {
            return $this->redirect('/login');
        }

        $user = User::query()->find($userId);
        if ($user === null || ! $user->isActive()) {
            $this->guard->clearMfaChallenge();

            return $this->redirect('/login');
        }

        // MFA 通过后仍要跳回原客户端，CSP 的 form-action 需要提前放行
        Csp::allowForAuthorizationUrl($this->guard->peekIntended());

        return $this->view->render('auth.mfa', [
            'pageTitle' => '两步验证',
            'method' => $this->mfa->hasTotp($user) ? 'totp' : 'recovery',
            'hasPasskey' => $this->mfa->hasPasskey($user),
            'email' => ViewSupport::maskEmail($user->email),
            'error' => $this->guard->pullMfaError(),
            'pendingPasskey' => $this->guard->shouldRememberDevice(),
        ]);
    }

    #[PostMapping(path: '/mfa/totp')]
    public function verifyTotp(): ResponseInterface
    {
        $user = $this->pendingUser();
        if ($user === null) {
            return $this->redirect('/login');
        }

        $code = (string) $this->request->input('code', '');
        $remember = $this->guard->shouldRememberDevice();

        if (! $this->mfa->verifyTotp($user, $code)) {
            $this->audit->log('auth.mfa.failed', 'failure', $user, 'user', (string) $user->id, ['method' => 'totp']);

            return $this->fail($user, '验证码不正确，请重新输入。');
        }

        return $this->complete($user, ['pwd', 'totp'], $remember);
    }

    #[PostMapping(path: '/mfa/recovery')]
    public function verifyRecovery(): ResponseInterface
    {
        $user = $this->pendingUser();
        if ($user === null) {
            return $this->redirect('/login');
        }

        $code = (string) $this->request->input('code', '');
        $remember = $this->guard->shouldRememberDevice();

        if (! $this->mfa->consumeRecoveryCode($user, $code)) {
            $this->audit->log('auth.mfa.failed', 'failure', $user, 'user', (string) $user->id, ['method' => 'recovery']);

            return $this->fail($user, '恢复码无效或已被使用。');
        }

        $this->audit->log('auth.mfa.recovery_used', 'success', $user, 'user', (string) $user->id, [
            'remaining' => $this->mfa->remainingRecoveryCodes($user),
        ]);

        return $this->complete($user, ['pwd', 'recovery'], $remember);
    }

    /** Passkey 登录选项（JSON，用于已输入密码后的二次验证） */
    #[PostMapping(path: '/mfa/passkey/options')]
    public function passkeyOptions(): ResponseInterface
    {
        $user = $this->pendingUser();
        if ($user === null) {
            return $this->error('会话已过期，请重新登录。', 401);
        }

        return $this->response->json($this->getWebauthn()->requestOptions($user));
    }

    /** Passkey 断言（JSON） */
    #[PostMapping(path: '/mfa/passkey/verify')]
    public function passkeyVerify(): ResponseInterface
    {
        $user = $this->pendingUser();
        if ($user === null) {
            return $this->error('会话已过期，请重新登录。', 401);
        }

        $asserted = $this->getWebauthn()->assert($this->request->all());
        if ($asserted === null || $asserted->id !== $user->id) {
            $this->audit->log('auth.mfa.failed', 'failure', $user, 'user', (string) $user->id, ['method' => 'passkey']);

            return $this->error('Passkey 验证失败，请重试或改用其他方式。', 422);
        }

        $remember = $this->guard->shouldRememberDevice();
        $this->complete($user, ['pwd', 'passkey'], $remember);

        return $this->response->json(['ok' => true, 'redirect' => $this->nextUrl()]);
    }

    // ------------------------------------------------------------------ 内部

    private function pendingUser(): ?User
    {
        $userId = $this->guard->pendingMfaUserId();
        if ($userId === null) {
            return null;
        }

        $user = User::query()->find($userId);

        return $user !== null && $user->isActive() ? $user : null;
    }

    private function complete(User $user, array $amr, bool $rememberDevice): ResponseInterface
    {
        $ip = $this->audit->clientIp() ?? '0.0.0.0';
        $userAgent = $this->audit->userAgent();

        $this->guard->login($user, $amr, $ip, $userAgent);
        $this->guard->clearErrors();
        $this->auth->touchLogin($user, $ip);
        // MFA 通过属于登录成功的补充信息，单独记一条以免覆盖首因子记录
        $this->audit->log('auth.mfa.passed', 'success', $user, 'user', (string) $user->id, ['amr' => $amr]);

        $response = $this->redirect($this->nextUrl());

        // 记住设备：写入 30 天有效的 HMAC 令牌，下次登录跳过二次验证
        if ($rememberDevice && $this->request->input('remember_device') !== null) {
            $plain = $this->mfa->trustDevice($user, $this->audit->userAgent());
            $cookie = new \Hyperf\HttpMessage\Cookie\Cookie(
                self::TRUSTED_COOKIE,
                $plain,
                time() + MfaService::TRUSTED_DEVICE_TTL,
                '/',
                // 必须为空串（host-only）：传 null 会触发 TypeError，
                // 且单标签主机名（localhost）不允许出现在 Domain 属性里。
                '',
                str_starts_with((string) $this->request->getUri()->getScheme(), 'https'),
                true,
                false,
                'lax'
            );

            // 不能用 $response->withCookie()：Hyperf\HttpServer\Response 只有 withCookie(),
            // 没有 getCookies()，ResponseEmitter 遍历不到，Cookie 会被静默丢弃。
            // 也不能直接写 Set-Cookie 头：SessionMiddleware 在控制器之后用
            // withHeader('Set-Cookie') 写入会话 Cookie，会把这里的值整条覆盖。
            // 因此交给最外层 PendingCookieMiddleware 追加。
            PendingCookieMiddleware::add((string) $cookie);
        }

        return $response;
    }

    private function fail(User $user, string $message): ResponseInterface
    {
        $this->guard->rememberMfaError($message);

        return $this->redirect('/mfa');
    }

    private function nextUrl(): string
    {
        return ViewSupport::safeRedirect($this->guard->pullIntended(), '/dashboard');
    }

    private function getWebauthn(): \App\Service\WebauthnService
    {
        return $this->container->get(\App\Service\WebauthnService::class);
    }
}
