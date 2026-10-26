<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Controller\AbstractController;
use App\Model\User;
use App\Service\AuditService;
use App\Service\AuthService;
use App\Service\MailService;
use App\Service\SessionGuard;
use App\Service\SettingsService;
use App\Service\VerificationTokenService;
use App\Support\Crypto;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\View\RenderInterface;
use Psr\Http\Message\ResponseInterface;

#[Controller]
class RegisterController extends AbstractController
{
    public function __construct(
        private readonly RenderInterface $view,
        private readonly AuthService $auth,
        private readonly SessionGuard $guard,
        private readonly SettingsService $settings,
        private readonly VerificationTokenService $tokens,
        private readonly MailService $mail,
        private readonly AuditService $audit
    ) {
    }

    #[GetMapping(path: '/register')]
    public function show(): ResponseInterface
    {
        if ($this->guard->check()) {
            return $this->redirect('/dashboard');
        }

        if (! $this->settings->registrationEnabled()) {
            return $this->render('auth.register', [
                'pageTitle' => '注册',
                'closed' => true,
                'errors' => [],
                'values' => [],
            ], 403);
        }

        return $this->render('auth.register', [
            'pageTitle' => '注册',
            'closed' => false,
            'errors' => [],
            'values' => [],
        ]);
    }

    #[PostMapping(path: '/register')]
    public function store(): ResponseInterface
    {
        if (! $this->settings->registrationEnabled()) {
            return $this->render('auth.register', [
                'pageTitle' => '注册',
                'closed' => true,
                'errors' => [],
                'values' => [],
            ], 403);
        }

        $email = strtolower(trim((string) $this->request->input('email', '')));
        $username = trim((string) $this->request->input('username', ''));
        $displayName = trim((string) $this->request->input('display_name', ''));
        $password = (string) $this->request->input('password', '');
        $passwordConfirm = (string) $this->request->input('password_confirmation', '');

        $values = ['email' => $email, 'username' => $username, 'display_name' => $displayName];
        $errors = [];

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            $errors['email'] = '请输入有效的邮箱地址。';
        }
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{2,63}$/', $username) !== 1) {
            $errors['username'] = '用户名需 3-64 位，以字母或数字开头，仅可包含字母、数字、下划线、点与连字符。';
        }
        if ($displayName === '' || mb_strlen($displayName) > 100) {
            $errors['display_name'] = '请填写显示名称（不超过 100 个字符）。';
        }
        if (($passwordError = $this->validatePassword($password)) !== null) {
            $errors['password'] = $passwordError;
        }
        if ($password !== $passwordConfirm) {
            $errors['password_confirmation'] = '两次输入的密码不一致。';
        }

        if ($errors === []) {
            if (User::withTrashed()->where('email', $email)->exists()) {
                // 不直接暴露邮箱是否已注册，统一提示
                $errors['email'] = '该邮箱无法用于注册，请更换或尝试找回密码。';
            }
            if (User::withTrashed()->where('username', $username)->exists()) {
                $errors['username'] = '该用户名已被使用。';
            }
        }

        if ($errors !== []) {
            return $this->render('auth.register', [
                'pageTitle' => '注册',
                'closed' => false,
                'errors' => $errors,
                'values' => $values,
            ], 422);
        }

        $requiresVerification = $this->settings->requireEmailVerification();

        $user = User::create([
            'uuid' => $this->uuid(),
            'username' => $username,
            'email' => $email,
            'display_name' => $displayName,
            'password_hash' => AuthService::hashPassword($password),
            'status' => $requiresVerification ? User::STATUS_PENDING : User::STATUS_ACTIVE,
            'platform_role' => User::ROLE_USER,
            'email_verified_at' => $requiresVerification ? null : date('Y-m-d H:i:s'),
            'password_changed_at' => date('Y-m-d H:i:s'),
            'avatar_color' => $this->pickAvatarColor($username),
        ]);

        $this->audit->log('auth.register', 'success', $user, 'user', (string) $user->id);

        if ($requiresVerification) {
            $token = $this->tokens->issue($user, \App\Model\VerificationToken::PURPOSE_EMAIL_VERIFY, $user->email);
            $this->mail->sendVerification($user, $token);

            return $this->redirect('/register/pending?email=' . urlencode($user->email));
        }

        return $this->redirect('/login?registered=1');
    }

    /** 注册成功后的“请查收邮件”页 */
    #[GetMapping(path: '/register/pending')]
    public function pending(): ResponseInterface
    {
        $email = (string) $this->request->query('email', '');

        return $this->render('auth.register-pending', [
            'pageTitle' => '验证邮箱',
            'email' => $email,
        ]);
    }

    private function validatePassword(string $password): ?string
    {
        if (mb_strlen($password) < 10) {
            return '密码至少 10 位。';
        }
        if (mb_strlen($password) > 128) {
            return '密码不能超过 128 位。';
        }
        if (preg_match('/[A-Za-z]/', $password) !== 1 || preg_match('/\d/', $password) !== 1) {
            return '密码需同时包含字母与数字。';
        }

        return null;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function pickAvatarColor(string $seed): string
    {
        $palette = ['#2F4D5E', '#3D5A52', '#5A4E3F', '#4A4459', '#3F4A5C', '#544A3D'];

        return $palette[abs(crc32($seed)) % count($palette)];
    }

    private function render(string $template, array $data, int $status = 200): ResponseInterface
    {
        return $this->view->render($template, $data)->withStatus($status);
    }
}
