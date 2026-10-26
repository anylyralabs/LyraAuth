<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Middleware\AdminMiddleware;
use App\Middleware\AuthenticateMiddleware;
use App\Model\User;
use App\Service\AuditService;
use App\Service\SessionGuard;
use App\Service\SettingsService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\View\RenderInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 站点设置。
 */
#[Controller]
#[Middleware(AuthenticateMiddleware::class)]
#[Middleware(AdminMiddleware::class)]
class SettingsController extends AbstractController
{
    public function __construct(
        private readonly RenderInterface $view,
        private readonly SessionGuard $guard,
        private readonly SettingsService $settings,
        private readonly AuditService $audit
    ) {
    }

    #[GetMapping(path: '/admin/settings')]
    public function index(): ResponseInterface
    {
        return $this->render([]);
    }

    /** 通用设置 */
    #[PostMapping(path: '/admin/settings/general')]
    public function saveGeneral(): ResponseInterface
    {
        $this->settings->set('site_name', trim((string) $this->request->input('site_name', 'LyraAuth')), 'general');
        $this->settings->set('support_email', trim((string) $this->request->input('support_email', '')), 'general');
        $this->settings->set('registration_enabled', $this->request->input('registration_enabled') === '1' ? '1' : '0', 'general');
        $this->settings->set('require_email_verification', $this->request->input('require_email_verification') === '1' ? '1' : '0', 'general');
        $this->settings->set('restrict_client_creation', $this->request->input('restrict_client_creation') === '1' ? '1' : '0', 'general');

        $this->recordChange('general');

        return $this->redirect('/admin/settings');
    }

    /** 邮件设置 */
    #[PostMapping(path: '/admin/settings/mail')]
    public function saveMail(): ResponseInterface
    {
        $this->settings->set('mail_driver', (string) $this->request->input('mail_driver', 'log'), 'mail');
        $this->settings->set('mail_host', trim((string) $this->request->input('mail_host', '')), 'mail');
        $this->settings->set('mail_port', trim((string) $this->request->input('mail_port', '587')), 'mail');
        $this->settings->set('mail_username', trim((string) $this->request->input('mail_username', '')), 'mail');

        // 密码留空表示不修改
        $password = (string) $this->request->input('mail_password', '');
        if ($password !== '') {
            $this->settings->set('mail_password', $password, 'mail', true);
        }

        $this->settings->set('mail_encryption', (string) $this->request->input('mail_encryption', 'tls'), 'mail');
        $this->settings->set('mail_auth_method', (string) $this->request->input('mail_auth_method', 'login'), 'mail');
        $this->settings->set('mail_verify_peer', $this->request->input('mail_verify_peer') === '1' ? '1' : '0', 'mail');
        $this->settings->set('mail_from_address', trim((string) $this->request->input('mail_from_address', '')), 'mail');
        $this->settings->set('mail_from_name', trim((string) $this->request->input('mail_from_name', 'LyraAuth')), 'mail');

        $this->recordChange('mail');

        return $this->redirect('/admin/settings#mail');
    }

    /** 发送测试邮件，直接反馈 SMTP 的失败原因 */
    #[PostMapping(path: '/admin/settings/mail/test')]
    public function testMail(): ResponseInterface
    {
        $to = trim((string) $this->request->input('to', ''));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->guard->flash('error', '请填写有效的收件邮箱。');

            return $this->redirect('/admin/settings#mail');
        }

        $mail = $this->container->get(\App\Service\MailService::class);
        $ok = $mail->send($to, 'LyraAuth SMTP 测试', "这是一封测试邮件。\n发送时间：" . date('Y-m-d H:i:s'));

        /** @var User $user */
        $user = $this->guard->user();
        $this->audit->log('admin.settings.mail_tested', $ok ? 'success' : 'failure', $user, 'settings', 'mail', ['to' => $to]);

        if ($ok) {
            $this->guard->flash('success', "测试邮件已发送至 {$to}。");
        } else {
            $this->guard->flash('error', '发送失败。请检查 SMTP 配置，详细原因见 runtime/logs/hyperf.log。');
        }

        return $this->redirect('/admin/settings#mail');
    }

    private function recordChange(string $group): void
    {
        /** @var User $user */
        $user = $this->guard->user();
        $this->audit->log('admin.settings.updated', 'success', $user, 'settings', $group);
        $this->guard->flash('success', '设置已保存。');
    }

    private function render(array $errors): ResponseInterface
    {
        $mailPasswordSet = false;
        try {
            $mailPasswordSet = (string) $this->settings->get('mail_password', '') !== '';
        } catch (\Throwable) {
            $mailPasswordSet = false;
        }

        $oauth = $this->container->get(\App\Service\MicrosoftOAuthService::class);

        return $this->view->render('admin.settings.index', [
            'pageTitle' => '站点设置',
            'pageDescription' => '这些配置会立即生效，并写入审计日志。',
            'navSection' => 'admin-settings',
            'currentUser' => $this->guard->user(),
            'isAdmin' => true,
            'oauth' => [
                'configured' => $oauth->isConfigured(),
                'authorized' => $oauth->isAuthorized(),
                'client_id' => $oauth->clientId(),
                'secret_set' => $oauth->clientSecret() !== '',
                'tenant' => $oauth->tenant(),
                'account' => $oauth->accountEmail(),
                'authorized_at' => $oauth->authorizedAt(),
                'redirect_uri' => $oauth->redirectUri(),
            ],
            'values' => [
                'site_name' => (string) $this->settings->get('site_name', 'LyraAuth'),
                'support_email' => (string) $this->settings->get('support_email', ''),
                'registration_enabled' => $this->settings->registrationEnabled(),
                'require_email_verification' => $this->settings->requireEmailVerification(),
                'restrict_client_creation' => $this->settings->restrictClientCreation(),
                'mail_driver' => (string) $this->settings->get('mail_driver', 'log'),
                'mail_host' => (string) $this->settings->get('mail_host', ''),
                'mail_port' => (string) $this->settings->get('mail_port', '587'),
                'mail_username' => (string) $this->settings->get('mail_username', ''),
                'mail_encryption' => (string) $this->settings->get('mail_encryption', 'tls'),
                'mail_auth_method' => (string) $this->settings->get('mail_auth_method', 'login'),
                'mail_verify_peer' => $this->settings->getBool('mail_verify_peer', true),
                'mail_from_address' => (string) $this->settings->get('mail_from_address', 'no-reply@lyraauth.local'),
                'mail_from_name' => (string) $this->settings->get('mail_from_name', 'LyraAuth'),
            ],
            'mailPasswordSet' => $mailPasswordSet,
            'errors' => $errors,
        ]);
    }
}
