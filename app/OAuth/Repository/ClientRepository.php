<?php

declare(strict_types=1);

namespace App\OAuth\Repository;

use App\Model\OauthClient;
use App\OAuth\Entity\ClientEntity;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;

class ClientRepository implements ClientRepositoryInterface
{
    public function getClientEntity(string $clientIdentifier): ?ClientEntity
    {
        $client = OauthClient::query()
            ->where('client_id', $clientIdentifier)
            ->where('is_active', true)
            ->first();

        if ($client === null) {
            return null;
        }

        $entity = new ClientEntity(
            $client->client_id,
            $client->name,
            (array) $client->redirect_uris,
            ! $client->isPublic()
        );
        $entity->grantTypes = (array) $client->grant_types;
        $entity->requirePkce = (bool) $client->require_pkce;
        $entity->isFirstParty = (bool) $client->is_first_party;
        $entity->postLogoutRedirectUris = (array) ($client->post_logout_redirect_uris ?? []);
        $entity->logoColor = (string) $client->logo_color;
        $entity->description = $client->description;

        return $entity;
    }

    /**
     * 校验客户端密钥。
     *
     * 密钥以 SHA-256 摘要存储，使用 hash_equals 做恒定时间比较。
     */
    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        $client = OauthClient::query()
            ->where('client_id', $clientIdentifier)
            ->where('is_active', true)
            ->first();

        if ($client === null) {
            return false;
        }

        // 公共客户端不得使用密钥认证
        if ($client->isPublic()) {
            return $clientSecret === null || $clientSecret === '';
        }

        if ($clientSecret === null || $clientSecret === '') {
            return false;
        }

        $stored = (string) $client->client_secret_hash;
        if ($stored === '') {
            return false;
        }

        return hash_equals($stored, hash('sha256', $clientSecret));
    }

    /** 记录客户端最近活跃时间 */
    public function touchLastUsed(string $clientIdentifier): void
    {
        OauthClient::query()
            ->where('client_id', $clientIdentifier)
            ->update(['last_used_at' => date('Y-m-d H:i:s')]);
    }
}
