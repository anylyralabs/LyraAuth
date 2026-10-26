<?php

declare(strict_types=1);

namespace App\OAuth\ResponseType;

use App\Model\OauthClient;
use App\Model\User;
use App\Service\IdTokenService;
use App\Service\OAuthContext;
use Hyperf\Context\ApplicationContext;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;
use Psr\Log\LoggerInterface;

/**
 * 在标准 Bearer 响应里补充 OIDC 所需的 id_token。
 *
 * league 不提供 id_token 支持；这里继承 BearerTokenResponse，
 * 在访问令牌字符串生成后计算 at_hash 并签发 id_token。
 *
 * 关键点：父类先调用 $accessToken->toString() 生成 access_token 字段，
 * 这里必须使用完全相同的字符串计算 at_hash，否则客户端校验会失败。
 */
class OidcBearerTokenResponse extends BearerTokenResponse
{
    protected function getExtraParams(AccessTokenEntityInterface $accessToken): array
    {
        $scopeIdentifiers = array_map(
            static fn ($scope) => $scope->getIdentifier(),
            $accessToken->getScopes()
        );

        // 未申请 openid 时不返回 id_token（纯 OAuth2 场景）
        if (! in_array('openid', $scopeIdentifiers, true)) {
            return [];
        }

        $userIdentifier = $accessToken->getUserIdentifier();
        if ($userIdentifier === null) {
            return [];
        }

        $container = ApplicationContext::getContainer();

        $user = User::query()->where('uuid', $userIdentifier)->first();
        $client = OauthClient::query()
            ->where('client_id', $accessToken->getClient()->getIdentifier())
            ->first();

        if ($user === null || $client === null) {
            return [];
        }

        // toString() 在父类的 generateHttpResponse 里已调用一次，结果确定且幂等
        $accessTokenString = $accessToken->toString();

        /** @var OAuthContext $context */
        $context = $container->get(OAuthContext::class);

        try {
            $idToken = $container->get(IdTokenService::class)->issue(
                $user,
                $client,
                $accessTokenString,
                $accessToken->getExpiryDateTime()->getTimestamp(),
                $context->authContext() ?? [],
                $context->nonce()
            );
        } catch (\Throwable $e) {
            // id_token 签发失败不应让整个令牌响应失败：记录后仍返回访问令牌
            $container->get(LoggerInterface::class)->error('id_token 签发失败：' . $e->getMessage());

            return [];
        }

        return ['id_token' => $idToken];
    }
}
