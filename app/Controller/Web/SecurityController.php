<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Controller\AbstractController;
use App\Middleware\AuthenticateMiddleware;
use App\Model\User;
use App\Service\AuditService;
use App\Service\AuthService;
use App\Service\MfaService;
use App\Service\SessionGuard;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\View\RenderInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 账号安全：改密、TOTP、Passkey 列表、登录设备。
 */
#[Controller]
#[Middleware(AuthenticateMiddleware::class)]
class SecurityController extends AbstractController
{
    public function __construct(
        private readonly RenderInterface $view,
        private readonly SessionGuard $guard,
        private readonly MfaService $mfa,
        private readonly AuthService $auth,
        private readonly AuditService $audit
    ) {
    }

    #[GetMapping(path: '/account/security')]
    public function index(): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();

        $sessions = $user->sessions()
            ->whereNull('revoked_at')
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->orderByDesc('last_activity_at')
            ->get();

        return $this->view->render('account.security', [
            'pageTitle' => '账号安全',
            'pageDescription' => '管理密码、双因素认证与登录设备。',
            'navSection' => 'security',
            'currentUser' => $user,
            'isAdmin' => $user->isAdmin(),
            'summary' => $this->mfa->summary($user),
            'sessions' => $sessions,
            'currentSessionId' => $this->guard->currentSessionId(),
            'totp' => $user->totp()->first(),
            'passkeys' => $user->webauthnCredentials()->orderByDesc('last_used_at')->get(),
            'trustedDevices' => $user->trustedDevices ?? collect(),
            'recoveryCodes' => $this->mfa->remainingRecoveryCodes($user),
            'setup' => $this->guard->pullTotpSetup(),
            'newRecoveryCodes' => $this->guard->pullRecoveryCodes(),
        ]);
    }

    // -------------------------------------------------------------- 修改密码

    #[PostMapping(path: '/account/password')]
    public function changePassword(): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();

        $current = (string) $this->request->input('current_password', '');
        $new = (string) $this->request->input('password', '');
        $confirmation = (string) $this->request->input('password_confirmation', '');

        if (! AuthService::verifyPassword($current, $user->password_hash)) {
            $this->guard->flash('error', '当前密码不正确。');

            return $this->redirect('/account/security');
        }

        if (mb_strlen($new) < 10 || preg_match('/[A-Za-z]/', $new) !== 1 || preg_match('/\d/', $new) !== 1) {
            $this->guard->flash('error', '新密码至少 10 位，且需同时包含字母与数字。');

            return $this->redirect('/account/security');
        }

        if ($new !== $confirmation) {
            $this->guard->flash('error', '两次输入的新密码不一致。');

            return $this->redirect('/account/security');
        }

        if ($new === $current) {
            $this->guard->flash('error', '新密码不能与当前密码相同。');

            return $this->redirect('/account/security');
        }

        $user->password_hash = AuthService::hashPassword($new);
        $user->password_changed_at = date('Y-m-d H:i:s');
        $user->must_change_password = false;
        $user->save();

        // 保留当前会话，其余会话全部退出
        $revoked = $this->guard->revokeAll($user->id, $this->guard->currentSessionId());
        $this->audit->log('account.password.changed', 'success', $user, 'user', (string) $user->id, ['revoked_sessions' => $revoked]);
        $this->guard->flash('success', '密码已更新，其他设备的登录状态已失效。');

        return $this->redirect('/account/security');
    }

    // ------------------------------------------------------------------ TOTP

    #[PostMapping(path: '/account/totp/setup')]
    public function startTotp(): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();

        $payload = $this->mfa->createTotpSecret($user, 'LyraAuth');
        $this->guard->rememberTotpSetup($payload['secret'], $payload['uri']);
        $this->audit->log('account.totp.setup_started', 'success', $user, 'user', (string) $user->id);

        return $this->redirect('/account/security#totp');
    }

    #[PostMapping(path: '/account/totp/confirm')]
    public function confirmTotp(): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();
        $code = (string) $this->request->input('code', '');

        if (! $this->mfa->confirmTotp($user, $code)) {
            $this->guard->flash('error', '验证码不正确，请确认设备时间并重新输入。');

            return $this->redirect('/account/security#totp');
        }

        // 首次启用时生成恢复码，明文只在这一次展示
        $codes = $this->mfa->regenerateRecoveryCodes($user);
        $this->guard->forgetTotpSetup();
        $this->guard->rememberRecoveryCodes($codes);
        $this->audit->log('account.totp.enabled', 'success', $user, 'user', (string) $user->id);

        return $this->redirect('/account/security#recovery');
    }

    #[PostMapping(path: '/account/totp/disable')]
    public function disableTotp(): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();

        if (! AuthService::verifyPassword((string) $this->request->input('password', ''), $user->password_hash)) {
            $this->guard->flash('error', '密码不正确，无法关闭双因素认证。');

            return $this->redirect('/account/security#totp');
        }

        $this->mfa->disableTotp($user);
        $this->audit->log('account.totp.disabled', 'success', $user, 'user', (string) $user->id);
        $this->guard->flash('success', '双因素认证已关闭。建议尽快重新启用。');

        return $this->redirect('/account/security');
    }

    #[PostMapping(path: '/account/recovery-codes')]
    public function regenerateRecoveryCodes(): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();

        if (! AuthService::verifyPassword((string) $this->request->input('password', ''), $user->password_hash)) {
            $this->guard->flash('error', '密码不正确。');

            return $this->redirect('/account/security#recovery');
        }

        $codes = $this->mfa->regenerateRecoveryCodes($user);
        $this->guard->rememberRecoveryCodes($codes);
        $this->audit->log('account.recovery_codes.regenerated', 'success', $user, 'user', (string) $user->id);

        return $this->redirect('/account/security#recovery');
    }

    // --------------------------------------------------------------- 会话管理

    #[PostMapping(path: '/account/sessions/revoke')]
    public function revokeSession(): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();
        $sessionId = (string) $this->request->input('session_id', '');

        if ($sessionId === $this->guard->currentSessionId()) {
            $this->guard->flash('error', '当前设备请使用「退出登录」。');

            return $this->redirect('/account/security#sessions');
        }

        $ok = $this->guard->revokeSession($user->id, $sessionId);
        $this->audit->log('account.session.revoked', $ok ? 'success' : 'failure', $user, 'session', $sessionId);
        $this->guard->flash($ok ? 'success' : 'error', $ok ? '该设备已退出登录。' : '会话不存在或已失效。');

        return $this->redirect('/account/security#sessions');
    }

    #[PostMapping(path: '/account/sessions/revoke-all')]
    public function revokeAllSessions(): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();

        $count = $this->guard->revokeAll($user->id, $this->guard->currentSessionId());
        $this->mfa->revokeAllTrustedDevices($user);
        $this->audit->log('account.sessions.revoked_all', 'success', $user, 'user', (string) $user->id, ['count' => $count]);
        $this->guard->flash('success', "已退出其他 {$count} 个设备，并清除全部信任设备。");

        return $this->redirect('/account/security#sessions');
    }

    // ------------------------------------------------------------- 信任设备

    #[PostMapping(path: '/account/trusted-devices/revoke')]
    public function revokeTrustedDevice(): ResponseInterface
    {
        /** @var User $user */
        $user = $this->guard->user();
        $id = (int) $this->request->input('id', 0);

        $record = \App\Model\TrustedDevice::query()->where('user_id', $user->id)->where('id', $id)->first();
        if ($record !== null) {
            $record->delete();
            $this->audit->log('account.trusted_device.revoked', 'success', $user, 'trusted_device', (string) $id);
            $this->guard->flash('success', '该设备不再被信任。');
        } else {
            $this->guard->flash('error', '设备记录不存在。');
        }

        return $this->redirect('/account/security#trusted');
    }
}
