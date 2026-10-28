<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use App\Controller\AbstractController;
use App\Model\OauthClient;
use App\Model\OauthConsent;
use App\Model\OauthScope;
use App\Model\User;
use App\OAuth\Entity\ClientEntity;
use App\Service\AuditService;
use App\Service\OAuthServerService;
use App\Service\SettingsService;
use App\Service\SessionGuard;
use App\Support\Csp;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\View\RenderInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 授权端点与同意页。
 *
 * 流程：校验请求 → 未登录跳登录 → 是否需要重新认证 → 是否需要同意 →
 * 完成授权并回跳 redirect_uri（带 code 或错误码）。
 *
 * 已登录且此前同意过时静默签发，实现 SSO；prompt=login 或 max_age 不满足时
 * 强制重新认证。
 */
#[Controller]
class AuthorizeController extends AbstractController
{
    private const SESSION_AUTH_REQUEST = 'oauth.auth_request';

    private const SESSION_AUTH_REQUEST_AT = 'oauth.auth_request_at';

    /** 授权请求在会话中的有效期（秒） */
    private const PENDING_TTL = 600;

    public function __construct(
        private readonly RenderInterface $view,
        private readonly OAuthServerService $oauth,
        private readonly SessionGuard $guard,
        private readonly SettingsService $settings,
        private readonly AuditService $audit
    ) {
    }

    #[GetMapping(path: '/oauth/authorize')]
    public function authorize(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $authRequest = $this->oauth->validateAuthorizationRequest($request);
        } catch (OAuthServerException $e) {
            return $this->oauthError($e, $request);
        }

        $client = $this->clientEntity($authRequest);
        if ($client === null) {
            return $this->oauthError(OAuthServerException::invalidClient($request), $request);
        }

        // 1. 未登录：记住来源并跳登录页
        $user = $this->guard->user();
        if ($user === null) {
            $this->guard->rememberIntended($this->currentUrl($request));

            return $this->redirect('/login');
        }

        // 2. prompt=login 或 max_age 超时：强制重新认证
        if ($this->requiresReauthentication($request, $authRequest)) {
            $this->storePending($authRequest);
            $this->guard->rememberIntended($this->currentUrl($request));

            // 结束当前 IdP 会话但保留来源，避免死循环
            $this->guard->logout();

            return $this->redirect('/login?reauth=1');
        }

        // 3. 是否需要展示同意页
        $scopes = array_map(static fn ($scope) => $scope->getIdentifier(), $authRequest->getScopes());
        $prompt = $this->promptValues($request);

        $needsConsent = in_array('consent', $prompt, true)
            || (! $client->isFirstParty && ! $this->hasConsent($user, $client));

        if ($needsConsent) {
            $nonce = $request->getQueryParams()['nonce'] ?? null;

            return $this->renderConsent(
                $authRequest,
                $client,
                $user,
                $scopes,
                is_string($nonce) && $nonce !== '' ? mb_substr($nonce, 0, 255) : null
            );
        }

        // 4. 静默授权
        $this->oauth->approveAuthorization($authRequest, $user);
        $this->prepareAuthCodeContext($request, $user);
        $this->recordConsent($user, $client, $scopes);
        $this->audit->log('oauth.authorize.approved', 'success', $user, 'oauth_client', $client->getIdentifier(), [
            'scopes' => $scopes,
            'silent' => true,
        ]);

        return $this->oauth->completeAuthorizationRequest(
            $authRequest,
            \Hyperf\Context\ResponseContext::get()
        );
    }

    /** 同意页提交 */
    #[PostMapping(path: '/oauth/authorize')]
    public function decide(): ResponseInterface
    {
        $user = $this->guard->user();
        if ($user === null) {
            return $this->redirect('/login');
        }

        $authRequest = $this->guard->pullOAuthRequest(self::SESSION_AUTH_REQUEST, self::SESSION_AUTH_REQUEST_AT, self::PENDING_TTL);
        if (! $authRequest instanceof AuthorizationRequestInterface) {
            return $this->view->render('oauth.error-page', [
                'pageTitle' => '授权已过期',
                'heading' => '授权请求已过期',
                'detail' => '页面停留时间过长，授权请求已失效。请回到应用重新发起登录。',
            ])->withStatus(400);
        }

        $client = $this->clientEntity($authRequest);
        if ($client === null) {
            return $this->view->render('oauth.error-page', [
                'pageTitle' => '应用不可用',
                'heading' => '应用不可用',
                'detail' => '该应用已被停用或删除，无法完成授权。',
            ])->withStatus(403);
        }

        $decision = (string) $this->request->input('decision', 'deny');

        if ($decision !== 'allow') {
            $this->audit->log('oauth.authorize.denied', 'denied', $user, 'oauth_client', $client->getIdentifier());

            return $this->oauthError(
                OAuthServerException::accessDenied('用户拒绝了授权请求'),
                \Hyperf\Context\ResponseContext::get(),
                $authRequest
            );
        }

        $scopes = array_map(static fn ($scope) => $scope->getIdentifier(), $authRequest->getScopes());

        $this->oauth->approveAuthorization($authRequest, $user);
        $this->prepareAuthCodeContext(\Hyperf\Context\Context::get(\Psr\Http\Message\ServerRequestInterface::class), $user);
        $this->recordConsent($user, $client, $scopes);
        $this->audit->log('oauth.authorize.approved', 'success', $user, 'oauth_client', $client->getIdentifier(), [
            'scopes' => $scopes,
            'silent' => false,
        ]);

        return $this->oauth->completeAuthorizationRequest(
            $authRequest,
            \Hyperf\Context\ResponseContext::get()
        );
    }

    // ------------------------------------------------------------------ 内部

    /**
     * 把 nonce 与认证上下文交给授权码仓库，随授权码一起落库。
     *
     * nonce 必须在 id_token 中回传（OIDC Core §3.1.2.1），而授权与兑换令牌是两个请求，
     * 因此这里写入仓库，兑换时再取出。
     */
    private function prepareAuthCodeContext(?ServerRequestInterface $request, User $user): void
    {
        $nonce = $request?->getQueryParams()['nonce'] ?? null;
        $nonce = is_string($nonce) && $nonce !== '' ? mb_substr($nonce, 0, 255) : null;

        // 无论走哪条分支都清空会话里的 nonce；同意页提交走 POST、没有 query string，
        // 此时回退到渲染同意页时保存的值。
        $storedNonce = $this->guard->pullAuthNonce();
        if ($nonce === null) {
            $nonce = $storedNonce;
        }

        // 通过独立持有者传递：Hyperf 容器默认非单例，不能依赖 repo 实例内的状态
        $context = $this->container->get(\App\Service\OAuthContext::class);
        $context->setNonce($nonce);
        $context->setAuthContext([
            'sid' => $this->guard->currentSessionId(),
            'amr' => $this->guard->amr(),
            'auth_time' => $this->guard->authTime(),
        ]);
    }

    private function renderConsent(
        AuthorizationRequestInterface $authRequest,
        ClientEntity $client,
        User $user,
        array $scopes,
        ?string $nonce = null
    ): ResponseInterface {
        $this->storePending($authRequest);

        // nonce 需要在同意页提交（POST，无 query string）之后仍能取到，先存入会话
        if ($nonce !== null && $nonce !== '') {
            $this->guard->rememberAuthNonce($nonce);
        }

        $scopeModels = OauthScope::query()
            ->whereIn('identifier', $scopes)
            ->get()
            ->keyBy('identifier');

        $clientModel = OauthClient::query()->where('client_id', $client->getIdentifier())->first();

        // 同意页提交后直接 302 到客户端回调地址，需放行该源
        Csp::allowFormActionForClient($clientModel);

        return $this->view->render('oauth.consent', [
            'pageTitle' => '授权 ' . $client->getName(),
            'client' => $clientModel,
            'clientName' => $client->getName(),
            'clientColor' => $client->logoColor,
            'isFirstParty' => $client->isFirstParty,
            'user' => $user,
            'scopes' => array_values(array_map(
                static fn (string $identifier) => [
                    'identifier' => $identifier,
                    'name' => $scopeModels[$identifier]->name ?? $identifier,
                    'description' => $scopeModels[$identifier]->description ?? '',
                ],
                $scopes
            )),
        ]);
    }

    private function storePending(AuthorizationRequestInterface $authRequest): void
    {
        $this->guard->rememberOAuthRequest(self::SESSION_AUTH_REQUEST, self::SESSION_AUTH_REQUEST_AT, $authRequest);
    }

    /** 是否要求用户重新认证 */
    private function requiresReauthentication(ServerRequestInterface $request, AuthorizationRequestInterface $authRequest): bool
    {
        $prompt = $this->promptValues($request);

        // prompt=none 表示不得与用户交互：已登录则静默，否则报 login_required
        if (in_array('login', $prompt, true)) {
            return true;
        }

        $maxAge = $request->getQueryParams()['max_age'] ?? null;
        if ($maxAge !== null && is_numeric($maxAge)) {
            $authTime = $this->guard->authTime();

            return $authTime === 0 || (time() - $authTime) > (int) $maxAge;
        }

        return false;
    }

    /** @return list<string> */
    private function promptValues(ServerRequestInterface $request): array
    {
        $prompt = $request->getQueryParams()['prompt'] ?? '';

        return array_values(array_filter(explode(' ', (string) $prompt), static fn ($v) => $v !== ''));
    }

    private function hasConsent(User $user, ClientEntity $client): bool
    {
        $clientModel = OauthClient::query()->where('client_id', $client->getIdentifier())->first();
        if ($clientModel === null) {
            return false;
        }

        return OauthConsent::query()
            ->where('user_id', $user->id)
            ->where('client_id', $clientModel->id)
            ->whereNull('revoked_at')
            ->exists();
    }

    private function recordConsent(User $user, ClientEntity $client, array $scopes): void
    {
        $clientModel = OauthClient::query()->where('client_id', $client->getIdentifier())->first();
        if ($clientModel === null) {
            return;
        }

        OauthConsent::query()->updateOrCreate(
            ['user_id' => $user->id, 'client_id' => $clientModel->id],
            [
                'scopes' => implode(' ', $scopes),
                'last_granted_at' => date('Y-m-d H:i:s'),
                'revoked_at' => null,
            ]
        );
    }

    private function clientEntity(AuthorizationRequestInterface $authRequest): ?ClientEntity
    {
        $client = $authRequest->getClient();

        return $client instanceof ClientEntity ? $client : null;
    }

    /**
     * OAuth 错误响应。
     *
     * 有可信 redirect_uri 时按规范回跳并带 error 参数，否则直接展示错误页，
     * 避免把用户导向未注册地址。
     */
    private function oauthError(
        OAuthServerException $exception,
        ServerRequestInterface $request,
        ?AuthorizationRequestInterface $authRequest = null
    ): ResponseInterface {
        $response = $exception->generateHttpResponse(\Hyperf\Context\ResponseContext::get());

        if ($exception->getHttpStatusCode() === 302) {
            return $response;
        }

        return $this->view->render('oauth.error-page', [
            'pageTitle' => '授权失败',
            'heading' => '无法完成授权',
            'detail' => $exception->getHint() ?: $exception->getMessage(),
        ])->withStatus($exception->getHttpStatusCode());
    }

    private function currentUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $query = $uri->getQuery();

        return $uri->getPath() . ($query !== '' ? '?' . $query : '');
    }
}
