<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\User;
use App\Model\WebauthnCredential;
use Hyperf\Contract\ConfigInterface;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Psr\Container\ContainerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Passkey / WebAuthn。
 *
 * 依赖 webauthn-lib 5.3 的 CredentialRecord 模型；挑战值保存在会话中，
 * 一次性使用，服务端校验来源、RP ID、签名与计数器。
 *
 * 约束：RP ID 必须是域名（localhost 可用），不能用 IP，否则浏览器拒绝创建凭据。
 */
class WebauthnService
{
    /** 挑战有效期（秒） */
    private const CHALLENGE_TTL = 300;

    private const SESSION_REGISTER = 'webauthn.register_options';

    private const SESSION_LOGIN = 'webauthn.login_options';

    private readonly string $rpId;

    private readonly string $origin;

    private readonly AttestationStatementSupportManager $attestationManager;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ConfigInterface $config,
        private readonly SessionGuard $guard
    ) {
        $appUrl = rtrim((string) $config->get('app_url', 'http://localhost:9501'), '/');
        $host = parse_url($appUrl, PHP_URL_HOST) ?: 'localhost';
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'http';

        // RP ID 只取主机名（含子域），不带端口
        $this->rpId = $host;
        $this->origin = $scheme . '://' . $host . (parse_url($appUrl, PHP_URL_PORT) ? ':' . parse_url($appUrl, PHP_URL_PORT) : '');

        $this->attestationManager = new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport(),
        ]);
    }

    public function rpId(): string
    {
        return $this->rpId;
    }

    /**
     * 生成注册选项，同时把挑战写入会话。
     *
     * @return array<string, mixed> 可直接 JSON 序列化给浏览器的结构
     */
    public function creationOptions(User $user, ?string $deviceName = null): array
    {
        $challenge = random_bytes(32);

        $exclude = [];
        foreach ($user->webauthnCredentials()->get() as $credential) {
            $exclude[] = PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                base64_decode($credential->credential_id, true) ?: ''
            );
        }

        $options = new PublicKeyCredentialCreationOptions(
            rp: new PublicKeyCredentialRpEntity('LyraAuth', $this->rpId),
            user: new PublicKeyCredentialUserEntity(
                $this->userHandle($user),
                $user->email,
                $user->display_name
            ),
            challenge: $challenge,
            pubKeyCredParams: [
                PublicKeyCredentialParameters::create('public-key', -7),   // ES256
                PublicKeyCredentialParameters::create('public-key', -257), // RS256
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                authenticatorAttachment: null,
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $exclude,
            timeout: 120000,
        );

        $this->guard->rememberWebauthnChallenge(self::SESSION_REGISTER, $options, $deviceName);

        return [
            'challenge' => Base64UrlSafe::encodeUnpadded($challenge),
            'rp' => ['name' => 'LyraAuth', 'id' => $this->rpId],
            'user' => [
                'id' => Base64UrlSafe::encodeUnpadded($this->userHandle($user)),
                'name' => $user->email,
                'displayName' => $user->display_name,
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'authenticatorSelection' => [
                'userVerification' => 'preferred',
                'residentKey' => 'preferred',
            ],
            'attestation' => 'none',
            'excludeCredentials' => array_map(
                fn (WebauthnCredential $credential) => [
                    'type' => 'public-key',
                    'id' => $credential->credential_id,
                ],
                $user->webauthnCredentials()->get()->all()
            ),
            'timeout' => 120000,
        ];
    }

    /**
     * 校验注册响应并落库。
     *
     * @param array<string, mixed> $payload 浏览器提交的 JSON
     */
    public function register(User $user, array $payload): ?WebauthnCredential
    {
        $stored = $this->guard->pullWebauthnChallenge(self::SESSION_REGISTER);
        if ($stored === null) {
            return null;
        }

        /** @var PublicKeyCredentialCreationOptions $options */
        [$options, $deviceName, $createdAt] = $stored;
        if (time() - $createdAt > self::CHALLENGE_TTL) {
            return null;
        }

        try {
            $serializer = $this->serializer();
            /** @var PublicKeyCredential $credential */
            $credential = $serializer->deserialize(
                json_encode($payload, JSON_THROW_ON_ERROR),
                PublicKeyCredential::class,
                'json'
            );

            if (! $credential->response instanceof AuthenticatorAttestationResponse) {
                return null;
            }

            $validator = AuthenticatorAttestationResponseValidator::create(
                $this->ceremonyFactory()->creationCeremony()
            );

            $record = $validator->check($credential->response, $options, $this->rpId);
        } catch (\Throwable) {
            return null;
        }

        return WebauthnCredential::create([
            'user_id' => $user->id,
            'credential_id' => Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId),
            'public_key' => base64_encode($record->credentialPublicKey),
            'aaguid' => $record->aaguid->toRfc4122(),
            'transports' => implode(',', array_filter((array) ($credential->response->transports ?? []))),
            'sign_count' => $record->counter,
            'backup_eligible' => (bool) $record->backupEligible,
            'backup_state' => (bool) $record->backupStatus,
            'device_name' => $deviceName !== null ? mb_substr($deviceName, 0, 100) : null,
            'last_used_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 生成登录选项；$user 为空时使用无用户名（discoverable）模式。
     *
     * @return array<string, mixed>
     */
    public function requestOptions(?User $user = null): array
    {
        $challenge = random_bytes(32);

        $allow = [];
        if ($user !== null) {
            foreach ($user->webauthnCredentials()->get() as $credential) {
                $allow[] = PublicKeyCredentialDescriptor::create(
                    PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                    base64_decode($credential->credential_id, true) ?: ''
                );
            }
        }

        $options = new PublicKeyCredentialRequestOptions(
            challenge: $challenge,
            rpId: $this->rpId,
            allowCredentials: $allow,
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            timeout: 120000,
        );

        $this->guard->rememberWebauthnChallenge(self::SESSION_LOGIN, $options, $user?->id, []);

        return [
            'challenge' => Base64UrlSafe::encodeUnpadded($challenge),
            'rpId' => $this->rpId,
            'allowCredentials' => array_map(
                fn (WebauthnCredential $credential) => [
                    'type' => 'public-key',
                    'id' => $credential->credential_id,
                ],
                $user?->webauthnCredentials()->get()->all() ?? []
            ),
            'userVerification' => 'preferred',
            'timeout' => 120000,
        ];
    }

    /**
     * 校验断言，成功返回对应用户。
     *
     * @param array<string, mixed> $payload
     */
    public function assert(array $payload): ?User
    {
        $stored = $this->guard->pullWebauthnChallenge(self::SESSION_LOGIN);
        if ($stored === null) {
            return null;
        }

        [$options, $userId, $createdAt] = $stored;
        if (time() - $createdAt > self::CHALLENGE_TTL) {
            return null;
        }

        try {
            $serializer = $this->serializer();
            /** @var PublicKeyCredential $credential */
            $credential = $serializer->deserialize(
                json_encode($payload, JSON_THROW_ON_ERROR),
                PublicKeyCredential::class,
                'json'
            );

            $rawId = Base64UrlSafe::encodeUnpadded($credential->rawId);
            $record = WebauthnCredential::query()->where('credential_id', $rawId)->first();
            if ($record === null) {
                return null;
            }

            $user = User::query()->find($record->user_id);
            if ($user === null || ! $user->isActive()) {
                return null;
            }

            $validator = AuthenticatorAssertionResponseValidator::create(
                $this->ceremonyFactory()->requestCeremony()
            );

            $updated = $validator->check(
                $this->toCredentialRecord($record),
                $credential->response,
                $options,
                $this->rpId,
                $this->userHandle($user)
            );

            $record->sign_count = max($record->sign_count, $updated->counter);
            $record->last_used_at = date('Y-m-d H:i:s');
            $record->save();

            return $user;
        } catch (\Throwable $e) {
            $this->guard->rememberWebauthnError($e->getMessage());

            return null;
        }
    }

    /** 该用户名下是否存在对应凭据 */
    public function hasCredentialFor(string $credentialId): bool
    {
        return WebauthnCredential::query()->where('credential_id', $credentialId)->exists();
    }

    // -------------------------------------------------------------- 内部工具

    /** 稳定的用户句柄：sha256(uuid) 前 32 字节，避免暴露自增 ID */
    private function userHandle(User $user): string
    {
        return substr(hash('sha256', $user->uuid, true), 0, 32);
    }

    private function ceremonyFactory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins([$this->origin]);

        return $factory;
    }

    private function serializer(): \Symfony\Component\Serializer\SerializerInterface
    {
        return (new WebauthnSerializerFactory($this->attestationManager))->create();
    }

    private function toCredentialRecord(WebauthnCredential $credential): CredentialRecord
    {
        return CredentialRecord::create(
            publicKeyCredentialId: base64_decode($credential->credential_id, true) ?: '',
            type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            transports: array_values(array_filter(explode(',', (string) $credential->transports))),
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: \Symfony\Component\Uid\Uuid::fromString($credential->aaguid ?: '00000000-0000-0000-0000-000000000000'),
            credentialPublicKey: $credential->publicKeyBytes(),
            userHandle: '',
            counter: $credential->sign_count,
        );
    }
}
