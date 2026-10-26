<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Middleware\AdminMiddleware;
use App\Middleware\AuthenticateMiddleware;
use App\Model\User;
use App\Service\AuditService;
use App\Service\MicrosoftOAuthService;
use App\Service\SessionGuard;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Psr\Http\Message\ResponseInterface;

/**
 * 微软账号 OAuth2 授权（用于 SMTP XOAUTH2）。
 *
 * 授权链接由管理员手动点击，在微软页面登录并同意后回调本控制器。
 * state 与 PKCE verifier 存会话，一次性消费，10 分钟过期。
 */
#[Controller]
#[Middleware(AuthenticateMiddleware::class)]
#[Middleware(AdminMiddleware::class)]
class MailOAuthController extends AbstractController
{
    private const SESSION_STATE = 'mail_oauth_state';

    private const SESSION_VERIFIER = 'mail_oauth_verifier';

    private const SESSION_AT = 'mail_oauth_at';

    /** 授权请求有效期（秒） */
    private const TTL = 600;

    public function __construct(
        private readonly MicrosoftOAuthService $oauth,
        private readonly SessionGuard $guard,
        private readonly AuditService $audit
    ) {
    }

    /** 保存应用凭据（client_id / secret / tenant） */
    #[PostMapping(path: '/admin/settings/mail/oauth/application')]
    public function saveApplication(): ResponseInterface
    {
        $clientId = trim((string) $this->request->input('client_id', ''));
        $secret = (string) $this->request->input('client_secret', '');
        $tenant = trim((string) $this->request->input('tenant', ''));

        if ($clientId === '' || preg_match('/^[0-9a-fA-F-]{36}$/', $clientId) !== 1) {
            $this->guard->flash('error', 'Client ID 应为 36 位 UUID（在 Azure 门户的「应用注册」里可以找到）。');

            return $this->redirect('/admin/settings#mail-oauth');
        }

        $this->oauth->saveApplication($clientId, $secret, $tenant);

        /** @var User $user */
        $user = $this->guard->user();
        $this->audit->log('admin.settings.mail_oauth_application_saved', 'success', $user, 'settings', 'mail', [
            'client_id' => $clientId,
            'tenant' => $tenant !== '' ? $tenant : MicrosoftOAuthService::DEFAULT_TENANT,
        ]);
        $this->guard->flash('success', '应用凭据已保存。下一步点击「开始授权」并登录微软账号。');

        return $this->redirect('/admin/settings#mail-oauth');
    }

    /** 跳转到微软授权页 */
    #[GetMapping(path: '/admin/settings/mail/oauth/authorize')]
    public function authorize(): ResponseInterface
    {
        if (! $this->oauth->isConfigured()) {
            $this->guard->flash('error', '请先填写 Client ID。');

            return $this->redirect('/admin/settings#mail-oauth');
        }

        $payload = $this->oauth->buildAuthorizationUrl();

        $this->guard->rememberMailOAuth(
            self::SESSION_STATE,
            self::SESSION_VERIFIER,
            self::SESSION_AT,
            $payload['state'],
            $payload['code_verifier']
        );

        return $this->redirect($payload['url']);
    }

    /** 微软回调 */
    #[GetMapping(path: '/admin/settings/mail/oauth/callback')]
    public function callback(): ResponseInterface
    {
        $error = (string) $this->request->query('error', '');
        if ($error !== '') {
            $description = (string) $this->request->query('error_description', '');
            $this->guard->flash('error', '微软返回错误：' . $error . ($description !== '' ? '（' . $description . '）' : ''));

            return $this->redirect('/admin/settings#mail-oauth');
        }

        $code = (string) $this->request->query('code', '');
        $state = (string) $this->request->query('state', '');

        if ($code === '' || $state === '') {
            $this->guard->flash('error', '回调缺少 code 或 state 参数。');

            return $this->redirect('/admin/settings#mail-oauth');
        }

        $pending = $this->guard->pullMailOAuth(
            self::SESSION_STATE,
            self::SESSION_VERIFIER,
            self::SESSION_AT,
            self::TTL
        );

        if ($pending === null) {
            $this->guard->flash('error', '授权会话已过期，请重新点击「开始授权」。');

            return $this->redirect('/admin/settings#mail-oauth');
        }

        // 校验 state，防 CSRF
        if (! hash_equals($pending['state'], $state)) {
            $this->guard->flash('error', 'state 不匹配，授权请求可能被篡改，请重试。');

            return $this->redirect('/admin/settings#mail-oauth');
        }

        $result = $this->oauth->exchangeCode($code, $pending['verifier']);

        /** @var User $user */
        $user = $this->guard->user();

        if (! ($result['ok'] ?? false)) {
            $this->audit->log('admin.settings.mail_oauth_failed', 'failure', $user, 'settings', 'mail', [
                'error' => $result['error'] ?? '',
            ]);
            $this->guard->flash('error', '换取令牌失败：' . ($result['error'] ?? '未知错误'));

            return $this->redirect('/admin/settings#mail-oauth');
        }

        $this->audit->log('admin.settings.mail_oauth_authorized', 'success', $user, 'settings', 'mail', [
            'account' => $result['email'] ?? '',
        ]);
        $this->guard->flash('success', '微软账号授权成功' . (! empty($result['email']) ? '：' . $result['email'] : '') . '。现在可以发送测试邮件了。');

        return $this->redirect('/admin/settings#mail-oauth');
    }

    /** 撤销授权 */
    #[PostMapping(path: '/admin/settings/mail/oauth/revoke')]
    public function revoke(): ResponseInterface
    {
        $this->oauth->revokeAuthorization();

        /** @var User $user */
        $user = $this->guard->user();
        $this->audit->log('admin.settings.mail_oauth_revoked', 'success', $user, 'settings', 'mail');
        $this->guard->flash('success', '已撤销微软账号授权。');

        return $this->redirect('/admin/settings#mail-oauth');
    }
}
