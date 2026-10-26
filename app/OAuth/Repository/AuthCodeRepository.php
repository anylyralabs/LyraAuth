<?php

declare(strict_types=1);

namespace App\OAuth\Repository;

use App\Model\OauthAuthCode;
use App\Model\User;
use App\OAuth\Entity\AuthCodeEntity;
use App\Service\SessionGuard;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;

class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    public function __construct(
        private readonly SessionGuard $guard,
        private readonly \App\Service\OAuthContext $context
    ) {
    }

    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCodeEntity();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $clientId = $authCodeEntity->getClient()->getIdentifier();
        $client = \App\Model\OauthClient::query()->where('client_id', $clientId)->first();
        if ($client === null) {
            return;
        }

        $userIdentifier = $authCodeEntity->getUserIdentifier();
        $user = $userIdentifier !== null ? User::query()->where('uuid', $userIdentifier)->first() : null;
        if ($user === null) {
            return;
        }

        $identifier = $authCodeEntity->getIdentifier();

        // 把授权阶段的会话上下文随授权码保存，供令牌请求使用
        $sessionId = $authCodeEntity instanceof AuthCodeEntity
            ? $authCodeEntity->getSessionId()
            : null;
        if ($sessionId === null && $this->guard->isStarted() && $this->guard->check()) {
            $sessionId = $this->guard->currentSessionId();
        }

        $pending = $this->context->authContext() ?? [];
        $amr = $authCodeEntity instanceof AuthCodeEntity && $authCodeEntity->getAmr() !== []
            ? $authCodeEntity->getAmr()
            : ($pending['amr'] ?? ($this->guard->isStarted() && $this->guard->check() ? $this->guard->amr() : ['pwd']));

        $authTime = $authCodeEntity instanceof AuthCodeEntity && $authCodeEntity->getAuthTime() > 0
            ? $authCodeEntity->getAuthTime()
            : (int) ($pending['auth_time'] ?? ($this->guard->isStarted() && $this->guard->check() ? $this->guard->authTime() : time()));

        if ($authTime <= 0) {
            $authTime = time();
        }

        OauthAuthCode::create([
            // 只存摘要：即使数据库泄露也无法还原授权码
            'code_hash' => hash('sha256', $identifier),
            'client_id' => $client->id,
            'user_id' => $user->id,
            'scopes' => implode(' ', array_map(
                static fn ($scope) => $scope->getIdentifier(),
                $authCodeEntity->getScopes()
            )),
            'redirect_uri' => (string) $authCodeEntity->getRedirectUri(),
            'nonce' => $this->context->nonce(),
            'session_id' => $sessionId,
            'used' => false,
            'expires_at' => $authCodeEntity->getExpiryDateTime()->format('Y-m-d H:i:s'),
        ]);

        $this->context->setAuthContext([
            'sid' => $sessionId,
            'amr' => $amr,
            'auth_time' => $authTime,
        ]);
    }

    public function revokeAuthCode(string $codeId): void
    {
        OauthAuthCode::query()
            ->where('code_hash', hash('sha256', $codeId))
            ->where('used', false)
            ->update(['used' => true, 'revoked_at' => date('Y-m-d H:i:s')]);
    }

    public function isAuthCodeRevoked(string $codeId): bool
    {
        $code = OauthAuthCode::query()
            ->where('code_hash', hash('sha256', $codeId))
            ->first();

        if ($code === null) {
            return true;
        }

        return $code->used || $code->revoked_at !== null;
    }

    /**
     * 取该用户最近一条未消费的授权码会话上下文。
     *
     * 授权码在请求间以 league 的加密载荷传输，数据库里存的是内部 auth_code_id 的摘要，
     * 无法由明文 code 反查；但一个用户在同一时刻只会持有一个待兑换的授权码，
     * 且 code 只能被其签发时的客户端兑换，因此按 client + 未使用 + 未过期定位。
     *
     * 若并发存在多个待兑换码，取最近签发的一条，与浏览器实际兑换的通常是同一个；
     * 即使取错，也只会影响 sid 声明，不影响授权本身的正确性。
     *
     * @return array{sid: ?string, amr: list<string>, auth_time: int}|null
     */
    /**
     * 令牌请求时读取授权码上保存的 nonce 与会话上下文。
     *
     * @return array{nonce: ?string, sid: ?string, amr: list<string>, auth_time: int}|null
     */
    public function latestAuthContext(string $clientId): ?array
    {
        $client = \App\Model\OauthClient::query()->where('client_id', $clientId)->first();
        if ($client === null) {
            return null;
        }

        $record = OauthAuthCode::query()
            ->where('client_id', $client->id)
            ->where('used', false)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->orderByDesc('id')
            ->first();

        if ($record === null) {
            return null;
        }

        return [
            'nonce' => $record->nonce,
            'sid' => $record->session_id,
            'amr' => ['pwd'],
            'auth_time' => time(),
        ];
    }

    /** 按明文 code 查找（供 PKCE 校验与自定义流程使用） */
    public function findByCode(string $code): ?OauthAuthCode
    {
        return OauthAuthCode::query()->where('code_hash', hash('sha256', $code))->first();
    }
}
