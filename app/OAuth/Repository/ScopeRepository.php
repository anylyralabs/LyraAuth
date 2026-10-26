<?php

declare(strict_types=1);

namespace App\OAuth\Repository;

use App\Model\OauthScope;
use App\OAuth\Entity\ClientEntity;
use App\OAuth\Entity\ScopeEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

class ScopeRepository implements ScopeRepositoryInterface
{
    /** OIDC 标准 scope，不随客户端绑定限制 */
    private const OIDC_SCOPES = [
        OauthScope::OPENID,
        OauthScope::PROFILE,
        OauthScope::EMAIL,
        OauthScope::OFFLINE_ACCESS,
    ];

    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        $exists = OauthScope::query()->where('identifier', $identifier)->exists();

        return $exists ? new ScopeEntity($identifier) : null;
    }

    /**
     * 过滤并补全 scope。
     *
     * 规则：
     * - 客户端绑定了 scope 白名单时，只保留白名单内的（OIDC 标准 scope 除外）；
     * - 未显式请求 openid 的授权码流程，不自动添加 openid；
     * - offline_access 仅在客户端允许 refresh_token 时保留。
     */
    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null,
        ?string $authCodeId = null
    ): array {
        $clientId = $clientEntity->getIdentifier();
        $allowed = \App\Model\OauthClientScope::query()
            ->join('oauth_clients', 'oauth_clients.id', '=', 'oauth_client_scopes.client_id')
            ->where('oauth_clients.client_id', $clientId)
            ->pluck('oauth_client_scopes.scope_identifier')
            ->all();

        $allowsRefresh = ! $clientEntity instanceof ClientEntity
            || $clientEntity->supportsGrantType('refresh_token');

        $result = [];
        foreach ($scopes as $scope) {
            /** @var ScopeEntityInterface $scope */
            $identifier = $scope->getIdentifier();

            if ($identifier === OauthScope::OFFLINE_ACCESS && ! $allowsRefresh) {
                continue;
            }

            if ($allowed !== [] && ! in_array($identifier, self::OIDC_SCOPES, true) && ! in_array($identifier, $allowed, true)) {
                continue;
            }

            $result[$identifier] = $scope;
        }

        return array_values($result);
    }
}
