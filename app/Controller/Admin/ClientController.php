<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Middleware\AdminMiddleware;
use App\Middleware\AuthenticateMiddleware;
use App\Model\OauthClient;
use App\Model\OauthClientScope;
use App\Model\OauthScope;
use App\Model\User;
use App\Service\AuditService;
use App\Service\SessionGuard;
use App\Support\Support;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\View\RenderInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * 应用（OAuth2 客户端）管理。
 *
 * 密钥只在创建与轮换时展示一次；数据库存 SHA-256 摘要。
 */
#[Controller]
#[Middleware(AuthenticateMiddleware::class)]
#[Middleware(AdminMiddleware::class)]
class ClientController extends AbstractController
{
    public function __construct(
        private readonly RenderInterface $view,
        private readonly SessionGuard $guard,
        private readonly AuditService $audit
    ) {
    }

    #[GetMapping(path: '/admin/clients')]
    public function index(): ResponseInterface
    {
        $clients = OauthClient::query()->orderByDesc('id')->paginate(20);

        return $this->view->render('admin.clients.index', [
            'pageTitle' => '应用管理',
            'pageDescription' => '管理接入 LyraAuth 的 OAuth2 / OIDC 应用。',
            'navSection' => 'admin-clients',
            'currentUser' => $this->guard->user(),
            'isAdmin' => true,
            'clients' => $clients,
            'newSecret' => $this->guard->pullClientSecret(),
        ]);
    }

    #[GetMapping(path: '/admin/clients/create')]
    public function create(): ResponseInterface
    {
        return $this->view->render('admin.clients.form', [
            'pageTitle' => '新建应用',
            'pageDescription' => '填写基本信息后即可获得客户端凭据。',
            'navSection' => 'admin-clients',
            'currentUser' => $this->guard->user(),
            'isAdmin' => true,
            'client' => null,
            'scopes' => OauthScope::query()->orderBy('identifier')->get(),
            'errors' => [],
            'values' => [],
        ]);
    }

    #[PostMapping(path: '/admin/clients')]
    public function store(): ResponseInterface
    {
        $values = $this->formValues();
        $errors = $this->validate($values, true);

        if ($errors !== []) {
            return $this->renderForm(null, $values, $errors, 422);
        }

        $isPublic = $values['client_type'] === OauthClient::TYPE_PUBLIC;
        $secret = $isPublic ? null : Support::clientSecret();

        $grantTypes = ['authorization_code', 'refresh_token', 'urn:ietf:params:oauth:grant-type:device_code'];
        if (! $isPublic && $values['client_credentials'] === '1') {
            $grantTypes[] = 'client_credentials';
        }

        /** @var User $user */
        $user = $this->guard->user();

        $client = OauthClient::create([
            'uuid' => Support::uuid(),
            'client_id' => Support::clientId(),
            'client_secret_hash' => $secret !== null ? hash('sha256', $secret) : null,
            'name' => $values['name'],
            'description' => $values['description'] ?: null,
            'client_type' => $values['client_type'],
            'redirect_uris' => $values['redirect_uris'],
            'post_logout_redirect_uris' => $values['post_logout_uris'] ?: null,
            'grant_types' => $grantTypes,
            'token_endpoint_auth_method' => $isPublic ? OauthClient::AUTH_NONE : $values['auth_method'],
            'require_pkce' => $isPublic || $values['require_pkce'] === '1',
            'is_first_party' => $values['is_first_party'] === '1',
            'is_active' => true,
            'owner_id' => $user->id,
            'client_uri' => $values['client_uri'] ?: null,
            'logo_color' => $values['logo_color'],
        ]);

        $this->syncScopes($client, $values['scopes']);

        $this->audit->log('admin.client.created', 'success', $user, 'oauth_client', $client->client_id, [
            'name' => $client->name,
            'type' => $client->client_type,
        ]);

        if ($secret !== null) {
            $this->guard->rememberClientSecret($secret);
        }

        $this->guard->flash('success', '应用创建成功。');

        return $this->redirect('/admin/clients');
    }

    #[GetMapping(path: '/admin/clients/{id}/edit')]
    public function edit(int $id): ResponseInterface
    {
        $client = OauthClient::query()->find($id);
        if ($client === null) {
            return $this->redirect('/admin/clients');
        }

        $bound = OauthClientScope::query()->where('client_id', $id)->pluck('scope_identifier')->all();

        return $this->renderForm($client, [
            'name' => $client->name,
            'description' => (string) $client->description,
            'client_type' => $client->client_type,
            'redirect_uris' => implode("\n", (array) $client->redirect_uris),
            'post_logout_uris' => implode("\n", (array) ($client->post_logout_redirect_uris ?? [])),
            'auth_method' => $client->token_endpoint_auth_method,
            'require_pkce' => $client->require_pkce ? '1' : '0',
            'is_first_party' => $client->is_first_party ? '1' : '0',
            'client_credentials' => $client->allowsGrant(OauthClient::GRANT_CLIENT_CREDENTIALS) ? '1' : '0',
            'client_uri' => (string) $client->client_uri,
            'logo_color' => $client->logo_color,
            'scopes' => $bound,
        ]);
    }

    #[PostMapping(path: '/admin/clients/{id}')]
    public function update(int $id): ResponseInterface
    {
        $client = OauthClient::query()->find($id);
        if ($client === null) {
            return $this->redirect('/admin/clients');
        }

        $values = $this->formValues();
        $errors = $this->validate($values, false);

        if ($errors !== []) {
            return $this->renderForm($client, $values, $errors, 422);
        }

        $grantTypes = ['authorization_code', 'refresh_token', 'urn:ietf:params:oauth:grant-type:device_code'];
        if ($client->client_type === OauthClient::TYPE_CONFIDENTIAL && $values['client_credentials'] === '1') {
            $grantTypes[] = 'client_credentials';
        }

        $client->fill([
            'name' => $values['name'],
            'description' => $values['description'] ?: null,
            'redirect_uris' => $values['redirect_uris'],
            'post_logout_redirect_uris' => $values['post_logout_uris'] ?: null,
            'grant_types' => $grantTypes,
            'token_endpoint_auth_method' => $client->isPublic() ? OauthClient::AUTH_NONE : $values['auth_method'],
            'require_pkce' => $client->isPublic() || $values['require_pkce'] === '1',
            'is_first_party' => $values['is_first_party'] === '1',
            'client_uri' => $values['client_uri'] ?: null,
            'logo_color' => $values['logo_color'],
        ])->save();

        $this->syncScopes($client, $values['scopes']);

        /** @var User $user */
        $user = $this->guard->user();
        $this->audit->log('admin.client.updated', 'success', $user, 'oauth_client', $client->client_id);
        $this->guard->flash('success', '应用已更新。');

        return $this->redirect('/admin/clients');
    }

    /** 轮换密钥 */
    #[PostMapping(path: '/admin/clients/{id}/rotate-secret')]
    public function rotateSecret(int $id): ResponseInterface
    {
        $client = OauthClient::query()->find($id);
        if ($client === null || $client->isPublic()) {
            $this->guard->flash('error', '该应用不支持密钥轮换。');

            return $this->redirect('/admin/clients');
        }

        $secret = Support::clientSecret();
        $client->client_secret_hash = hash('sha256', $secret);
        $client->save();

        $this->guard->rememberClientSecret($secret);
        /** @var User $user */
        $user = $this->guard->user();
        $this->audit->log('admin.client.secret_rotated', 'success', $user, 'oauth_client', $client->client_id);
        $this->guard->flash('warning', '密钥已轮换，旧密钥立即失效。');

        return $this->redirect('/admin/clients');
    }

    /** 启用 / 停用 */
    #[PostMapping(path: '/admin/clients/{id}/toggle')]
    public function toggle(int $id): ResponseInterface
    {
        $client = OauthClient::query()->find($id);
        if ($client === null) {
            return $this->redirect('/admin/clients');
        }

        $client->is_active = ! $client->is_active;
        $client->save();

        /** @var User $user */
        $user = $this->guard->user();
        $this->audit->log('admin.client.toggled', 'success', $user, 'oauth_client', $client->client_id, [
            'is_active' => $client->is_active,
        ]);
        $this->guard->flash('success', $client->is_active ? '应用已启用。' : '应用已停用，现有令牌将无法刷新。');

        return $this->redirect('/admin/clients');
    }

    #[PostMapping(path: '/admin/clients/{id}/delete')]
    public function destroy(int $id): ResponseInterface
    {
        $client = OauthClient::query()->find($id);
        if ($client === null) {
            return $this->redirect('/admin/clients');
        }

        $name = $client->name;
        $clientId = $client->client_id;
        $client->delete();

        /** @var User $user */
        $user = $this->guard->user();
        $this->audit->log('admin.client.deleted', 'success', $user, 'oauth_client', $clientId, ['name' => $name]);
        $this->guard->flash('success', "应用「{$name}」已删除。");

        return $this->redirect('/admin/clients');
    }

    // ------------------------------------------------------------------ 内部

    /** @return array<string, mixed> */
    private function formValues(): array
    {
        return [
            'name' => trim((string) $this->request->input('name', '')),
            'description' => trim((string) $this->request->input('description', '')),
            'client_type' => (string) $this->request->input('client_type', OauthClient::TYPE_CONFIDENTIAL),
            'redirect_uris' => $this->lines((string) $this->request->input('redirect_uris', '')),
            'post_logout_uris' => $this->lines((string) $this->request->input('post_logout_uris', '')),
            'auth_method' => (string) $this->request->input('auth_method', OauthClient::AUTH_BASIC),
            'require_pkce' => (string) $this->request->input('require_pkce', '1'),
            'is_first_party' => (string) $this->request->input('is_first_party', '0'),
            'client_credentials' => (string) $this->request->input('client_credentials', '0'),
            'client_uri' => trim((string) $this->request->input('client_uri', '')),
            'logo_color' => $this->safeColor((string) $this->request->input('logo_color', '#2F4D5E')),
            'scopes' => array_values(array_filter((array) $this->request->input('scopes', []), 'is_string')),
        ];
    }

    /** @param array<string, mixed> $values */
    private function validate(array $values, bool $isCreate): array
    {
        $errors = [];

        if ($values['name'] === '' || mb_strlen($values['name']) > 100) {
            $errors['name'] = '请填写应用名称（不超过 100 字）。';
        }

        if ($values['redirect_uris'] === []) {
            $errors['redirect_uris'] = '至少填写一个重定向 URI。';
        }

        foreach (array_merge($values['redirect_uris'], $values['post_logout_uris']) as $uri) {
            if (! $this->isValidRedirect($uri)) {
                $errors['redirect_uris'] = "非法的地址：{$uri}。必须为绝对地址，除 localhost 外需使用 https，且不能包含 fragment。";
                break;
            }
        }

        if ($values['client_uri'] !== '' && ! $this->isValidRedirect($values['client_uri'])) {
            $errors['client_uri'] = '应用主页地址不合法。';
        }

        if ($isCreate && ! in_array($values['client_type'], [OauthClient::TYPE_CONFIDENTIAL, OauthClient::TYPE_PUBLIC], true)) {
            $errors['client_type'] = '客户端类型不合法。';
        }

        return $errors;
    }

    /** @param array<string, mixed>|null $client */
    private function renderForm(?OauthClient $client, array $values, array $errors = [], int $status = 200): ResponseInterface
    {
        return $this->view->render('admin.clients.form', [
            'pageTitle' => $client === null ? '新建应用' : '编辑应用',
            'pageDescription' => $client === null ? '填写基本信息后即可获得客户端凭据。' : '修改应用配置，凭据与授权记录保持不变。',
            'navSection' => 'admin-clients',
            'currentUser' => $this->guard->user(),
            'isAdmin' => true,
            'client' => $client,
            'scopes' => OauthScope::query()->orderBy('identifier')->get(),
            'errors' => $errors,
            'values' => $values,
        ])->withStatus($status);
    }

    /** @param list<string> $scopes */
    private function syncScopes(OauthClient $client, array $scopes): void
    {
        OauthClientScope::query()->where('client_id', $client->id)->delete();

        $valid = OauthScope::query()->whereIn('identifier', $scopes)->pluck('identifier')->all();
        foreach ($valid as $identifier) {
            OauthClientScope::create([
                'client_id' => $client->id,
                'scope_identifier' => $identifier,
            ]);
        }
    }

    /** @return list<string> */
    private function lines(string $value): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn ($v) => $v !== ''));
    }

    /** 只允许 http/https 绝对地址；非 loopback 必须 https */
    private function isValidRedirect(string $uri): bool
    {
        if ($uri === '' || strlen($uri) > 2000) {
            return false;
        }

        $parts = parse_url($uri);
        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || isset($parts['fragment'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower($parts['host']);
        $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);

        return $scheme === 'https' || $isLoopback;
    }

    private function safeColor(string $color): string
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? strtoupper($color) : '#2F4D5E';
    }
}
