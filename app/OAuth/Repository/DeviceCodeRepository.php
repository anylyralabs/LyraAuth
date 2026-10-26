<?php

declare(strict_types=1);

namespace App\OAuth\Repository;

use App\Model\OauthDeviceCode;
use App\OAuth\Entity\DeviceCodeEntity;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;

class DeviceCodeRepository implements DeviceCodeRepositoryInterface
{
    public function getNewDeviceCode(): DeviceCodeEntityInterface
    {
        return new DeviceCodeEntity();
    }

    public function persistDeviceCode(DeviceCodeEntityInterface $deviceCodeEntity): void
    {
        $clientId = $deviceCodeEntity->getClient()->getIdentifier();
        $client = \App\Model\OauthClient::query()->where('client_id', $clientId)->first();
        if ($client === null) {
            return;
        }

        $identifier = $deviceCodeEntity->getIdentifier();
        $hash = hash('sha256', $identifier);

        // league 在轮询时可能重复 persist，这里以 device_code 为唯一键做幂等写入
        if (OauthDeviceCode::query()->where('device_code_hash', $hash)->exists()) {
            return;
        }

        OauthDeviceCode::create([
            'device_code_hash' => $hash,
            'user_code' => $deviceCodeEntity->getUserCode(),
            'client_id' => $client->id,
            'scopes' => implode(' ', array_map(
                static fn ($scope) => $scope->getIdentifier(),
                $deviceCodeEntity->getScopes()
            )),
            'status' => OauthDeviceCode::STATUS_PENDING,
            'interval' => $deviceCodeEntity->getInterval(),
            'expires_at' => $deviceCodeEntity->getExpiryDateTime()->format('Y-m-d H:i:s'),
        ]);
    }

    public function getDeviceCodeEntityByDeviceCode(string $deviceCode): ?DeviceCodeEntityInterface
    {
        $row = OauthDeviceCode::query()
            ->where('device_code_hash', hash('sha256', $deviceCode))
            ->first();

        if ($row === null) {
            return null;
        }

        $client = \App\Model\OauthClient::query()->find($row->client_id);
        if ($client === null) {
            return null;
        }

        $entity = new DeviceCodeEntity();
        $entity->setIdentifier($deviceCode);
        $entity->setUserCode($row->user_code);
        $entity->setClient($this->clientEntity($client));
        $entity->setExpiryDateTime(new \DateTimeImmutable($row->expires_at->format('Y-m-d H:i:s')));
        $entity->setInterval($row->interval);
        if ($row->last_polled_at !== null) {
            $entity->setLastPolledAt(new \DateTimeImmutable($row->last_polled_at->format('Y-m-d H:i:s')));
        }
        $entity->setUserApproved($row->status === OauthDeviceCode::STATUS_APPROVED);

        foreach (array_filter(explode(' ', (string) $row->scopes)) as $scope) {
            $entity->addScope(new \App\OAuth\Entity\ScopeEntity($scope));
        }

        if ($row->user_id !== null) {
            $uuid = \App\Model\User::query()->where('id', $row->user_id)->value('uuid');
            if ($uuid !== null) {
                $entity->setUserIdentifier((string) $uuid);
            }
        }

        $entity->setVerificationUri($this->verificationUri());

        return $entity;
    }

    public function revokeDeviceCode(string $codeId): void
    {
        OauthDeviceCode::query()
            ->where('device_code_hash', hash('sha256', $codeId))
            ->update(['status' => OauthDeviceCode::STATUS_USED]);
    }

    public function isDeviceCodeRevoked(string $codeId): bool
    {
        $status = OauthDeviceCode::query()
            ->where('device_code_hash', hash('sha256', $codeId))
            ->value('status');

        return $status === null || $status === OauthDeviceCode::STATUS_USED || $status === OauthDeviceCode::STATUS_DENIED;
    }

    /**
     * 设备码流程的会话上下文。
     *
     * 轮询请求同样不带 Cookie，需要从已批准的记录里取回 sid，
     * 让签发的访问令牌参与单点登出。
     *
     * @return array{sid: ?string, amr: list<string>, auth_time: int}|null
     */
    public function authContextForDeviceCode(string $deviceCode): ?array
    {
        $row = OauthDeviceCode::query()
            ->where('device_code_hash', hash('sha256', $deviceCode))
            ->first();

        if ($row === null || $row->session_id === null) {
            return null;
        }

        return [
            'sid' => $row->session_id,
            'amr' => ['pwd', 'device_code'],
            'auth_time' => $row->updated_at?->getTimestamp() ?: time(),
        ];
    }

    private function verificationUri(): string    {
        $config = \Hyperf\Context\ApplicationContext::getContainer()
            ->get(\Hyperf\Contract\ConfigInterface::class);

        return rtrim((string) $config->get('app_url', ''), '/') . '/device';
    }

    private function clientEntity(\App\Model\OauthClient $client): \App\OAuth\Entity\ClientEntity
    {
        $entity = new \App\OAuth\Entity\ClientEntity(
            $client->client_id,
            $client->name,
            (array) $client->redirect_uris,
            ! $client->isPublic()
        );
        $entity->grantTypes = (array) $client->grant_types;
        $entity->requirePkce = (bool) $client->require_pkce;

        return $entity;
    }
}
