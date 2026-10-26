<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Controller\AbstractController;
use App\Model\User;
use App\Model\VerificationToken;
use App\Service\AuditService;
use App\Service\AuthService;
use App\Service\MailService;
use App\Service\RateLimiter;
use App\Service\SessionGuard;
use App\Service\VerificationTokenService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\View\RenderInterface;
use Psr\Http\Message\ResponseInterface;

#[Controller]
class PasswordController extends AbstractController
{
    public function __construct(
        private readonly RenderInterface $view,
        private readonly VerificationTokenService $tokens,
        private readonly MailService $mail,
        private readonly SessionGuard $guard,
        private readonly RateLimiter $limiter,
        private readonly AuditService $audit
    ) {
    }

    // ------------------------------------------------------ 邮箱验证

    #[GetMapping(path: '/email/verify/{token}')]
    public function verifyEmail(string $token): ResponseInterface
    {
        $user = $this->tokens->consume($token, VerificationToken::PURPOSE_EMAIL_VERIFY);

        if ($user === null) {
            return $this->view->render('auth.email-verified', [
                'pageTitle' => '验证失败',
                'success' => false,
                'message' => '验证链接无效或已过期，请重新申请验证邮件。',
            ])->withStatus(400);
        }

        if ($user->email_verified_at === null) {
            $user->email_verified_at = date('Y-m-d H:i:s');
            if ($user->status === User::STATUS_PENDING) {
                $user->status = User::STATUS_ACTIVE;
            }
            $user->save();
        }

        $this->audit->log('auth.email.verified', 'success', $user, 'user', (string) $user->id);

        return $this->view->render('auth.email-verified', [
            'pageTitle' => '邮箱已验证',
            'success' => true,
            'message' => '你的邮箱已验证成功，现在可以登录了。',
        ]);
    }

    // ---------------------------------------------------- 找回密码

    #[GetMapping(path: '/password/forgot')]
    public function showForgot(): ResponseInterface
    {
        return $this->view->render('auth.forgot-password', [
            'pageTitle' => '找回密码',
            'sent' => false,
            'email' => '',
            'error' => null,
        ]);
    }

    #[PostMapping(path: '/password/forgot')]
    public function sendResetLink(): ResponseInterface
    {
        $email = strtolower(trim((string) $this->request->input('email', '')));
        $ip = $this->audit->clientIp() ?? '0.0.0.0';

        // 按 IP 与邮箱双维度限流，防止邮件轰炸
        if (! $this->limiter->attempt('pwreset:ip:' . $ip, 5, 3600)
            || ! $this->limiter->attempt('pwreset:email:' . $email, 3, 3600)) {
            return $this->view->render('auth.forgot-password', [
                'pageTitle' => '找回密码',
                'sent' => false,
                'email' => $email,
                'error' => '请求过于频繁，请稍后再试。',
            ])->withStatus(429);
        }

        $user = User::query()->where('email', $email)->first();

        // 无论账号是否存在都返回相同结果，避免枚举
        if ($user !== null && $user->isActive()) {
            $token = $this->tokens->issue($user, VerificationToken::PURPOSE_PASSWORD_RESET, $user->email);
            $this->mail->sendPasswordReset($user, $token);
            $this->audit->log('auth.password.reset_requested', 'success', $user, 'user', (string) $user->id);
        } else {
            $this->audit->log('auth.password.reset_requested', 'failure', null, null, null, ['email' => $email]);
        }

        return $this->view->render('auth.forgot-password', [
            'pageTitle' => '找回密码',
            'sent' => true,
            'email' => $email,
            'error' => null,
        ]);
    }

    #[GetMapping(path: '/password/reset/{token}')]
    public function showReset(string $token): ResponseInterface
    {
        $user = $this->tokens->peek($token, VerificationToken::PURPOSE_PASSWORD_RESET);

        if ($user === null) {
            return $this->view->render('auth.reset-password', [
                'pageTitle' => '重置密码',
                'token' => $token,
                'valid' => false,
                'errors' => [],
            ])->withStatus(400);
        }

        return $this->view->render('auth.reset-password', [
            'pageTitle' => '重置密码',
            'token' => $token,
            'valid' => true,
            'errors' => [],
        ]);
    }

    #[PostMapping(path: '/password/reset/{token}')]
    public function reset(string $token): ResponseInterface
    {
        $password = (string) $this->request->input('password', '');
        $confirmation = (string) $this->request->input('password_confirmation', '');
        $errors = [];

        if (mb_strlen($password) < 10) {
            $errors['password'] = '密码至少 10 位。';
        } elseif (preg_match('/[A-Za-z]/', $password) !== 1 || preg_match('/\d/', $password) !== 1) {
            $errors['password'] = '密码需同时包含字母与数字。';
        }

        if ($password !== $confirmation) {
            $errors['password_confirmation'] = '两次输入的密码不一致。';
        }

        if ($errors !== []) {
            return $this->view->render('auth.reset-password', [
                'pageTitle' => '重置密码',
                'token' => $token,
                'valid' => true,
                'errors' => $errors,
            ])->withStatus(422);
        }

        $user = $this->tokens->consume($token, VerificationToken::PURPOSE_PASSWORD_RESET);

        if ($user === null) {
            return $this->view->render('auth.reset-password', [
                'pageTitle' => '重置密码',
                'token' => $token,
                'valid' => false,
                'errors' => [],
            ])->withStatus(400);
        }

        $user->password_hash = AuthService::hashPassword($password);
        $user->password_changed_at = date('Y-m-d H:i:s');
        $user->must_change_password = false;
        // 重置后视为已证明邮箱所有权
        if ($user->email_verified_at === null) {
            $user->email_verified_at = date('Y-m-d H:i:s');
        }
        if ($user->status === User::STATUS_PENDING) {
            $user->status = User::STATUS_ACTIVE;
        }
        $user->save();

        // 强制下线全部会话，避免旧会话继续可用
        $revoked = $this->guard->revokeAll($user->id);
        $this->audit->log('auth.password.reset', 'success', $user, 'user', (string) $user->id, ['revoked_sessions' => $revoked]);

        return $this->redirect('/login?reset=1');
    }
}
