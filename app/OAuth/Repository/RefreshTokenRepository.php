<?php

declare(strict_types=1);

namespace App\OAuth\Repository;

use App\Model\OauthRefreshToken;
use App\Model\User;
use App\OAuth\Entity\RefreshTokenEntity;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return new RefreshTokenEntity();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $clientId = $refreshTokenEntity->getAccessToken()->getClient()->getIdentifier();
        $client = \App\Model\OauthClient::query()->where('client_id', $clientId)->first();
        if ($client === null) {
            return;
        }

        $userIdentifier = $refreshTokenEntity->getAccessToken()->getUserIdentifier();
        $user = $userIdentifier !== null ? User::query()->where('uuid', $userIdentifier)->first() : null;
        if ($user === null) {
            return;
        }

        $accessJti = $refreshTokenEntity->getAccessToken()->getIdentifier();
        $access = \App\Model\OauthAccessToken::query()->where('jti', $accessJti)->first();

        $scopes = array_map(
            static fn ($scope) => $scope->getIdentifier(),
            $refreshTokenEntity->getAccessToken()->getScopes()
        );

        // 继承访问令牌绑定的会话，刷新后仍可单点登出
        $sessionId = $access?->session_id;
        if ($sessionId === null) {
            $context = \Hyperf\Context\ApplicationContext::getContainer()
                ->get(\App\Service\OAuthContext::class)
                ->authContext();
            $sessionId = is_array($context) ? ($context['sid'] ?? null) : null;
        }

        OauthRefreshToken::create([
            // 框架只给 jti，这里额外存一份摘要用于按明文反查
            'token_hash' => hash('sha256', $refreshTokenEntity->getIdentifier()),
            'jti' => $refreshTokenEntity->getIdentifier(),
            'client_id' => $client->id,
            'user_id' => $user->id,
            'access_token_id' => $access?->id,
            'session_id' => $sessionId ?: null,
            'parent_id' => $refreshTokenEntity instanceof RefreshTokenEntity
                ? $this->resolveParentId($refreshTokenEntity->getParentId())
                : null,
            'scopes' => implode(' ', $scopes),
            'expires_at' => $refreshTokenEntity->getExpiryDateTime()->format('Y-m-d H:i:s'),
        ]);
    }

    public function revokeRefreshToken(string $tokenId): void
    {
        OauthRefreshToken::query()
            ->where('jti', $tokenId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);
    }

    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $token = OauthRefreshToken::query()->where('jti', $tokenId)->first();

        if ($token === null) {
            return true;
        }

        return $token->revoked_at !== null;
    }

    /** 撤销同一条链上的所有令牌（检测到重放时使用） */
    public function revokeChain(string $tokenId): void
    {
        $token = OauthRefreshToken::query()->where('jti', $tokenId)->first();
        if ($token === null) {
            return;
        }

        $rootId = $token->id;
        while (true) {
            $parent = OauthRefreshToken::query()->where('id', $rootId)->value('parent_id');
            if ($parent === null || $parent === $rootId) {
                break;
            }
            $rootId = (int) $parent;
        }

        // 递归收集所有后代
        $ids = [$rootId];
        $frontier = [$rootId];
        while ($frontier !== []) {
            $children = OauthRefreshToken::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $children = array_map('intval', $children);
            $new = array_diff($children, $ids);
            if ($new === []) {
                break;
            }
            $ids = array_merge($ids, $new);
            $frontier = $new;
        }

        OauthRefreshToken::query()
            ->whereIn('id', $ids)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);
    }

    /** 按明文 jti 查记录（RefreshTokenGrant 已经解密出 jti） */
    public function findByJti(string $jti): ?OauthRefreshToken
    {
        return OauthRefreshToken::query()->where('jti', $jti)->first();
    }

    private function resolveParentId(?string $parentJti): ?int
    {
        if ($parentJti === null || $parentJti === '') {
            return null;
        }

        $id = OauthRefreshToken::query()->where('jti', $parentJti)->value('id');

        return $id !== null ? (int) $id : null;
    }
}
