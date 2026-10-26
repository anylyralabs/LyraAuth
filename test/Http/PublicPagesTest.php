<?php

declare(strict_types=1);

namespace HyperfTest\Http;

use function Hyperf\Config\config;

use Hyperf\Testing\Client;
use HyperfTest\HttpTestCase;

/**
 * 公开页面与安全头的 HTTP 级测试。
 *
 * 注意：Hyperf\Testing\Client::get() 会把响应体解包成数组，
 * 需要访问状态码与响应头时改用 request()，它返回 TestResponse。
 *
 * 需要数据库与 Redis 可用（与开发环境共用 lyraauth 库）。
 *
 * @internal
 * @coversNothing
 */
class PublicPagesTest extends HttpTestCase
{
    public function testHomePageRenders(): void
    {
        $response = $this->request('GET', '/', ['headers' => ['Accept' => 'text/html']]);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('LyraAuth', $body);
        $this->assertStringContainsString('OpenID Connect', $body);
    }

    public function testLoginPageRendersWithCsrfToken(): void
    {
        $response = $this->request('GET', '/login', ['headers' => ['Accept' => 'text/html']]);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('name="_token"', $body);
        $this->assertStringContainsString('邮箱或用户名', $body);
    }

    public function testSecurityHeadersAreSet(): void
    {
        $response = $this->request('GET', '/', ['headers' => ['Accept' => 'text/html']]);

        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringContainsString("frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'));
        $this->assertStringContainsString("script-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testDiscoveryDocumentIsPublic(): void
    {
        $response = $this->request('GET', '/.well-known/openid-configuration');
        $data = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertIsArray($data);

        // issuer 跟随 APP_URL，不写死域名：换部署地址时测试仍然成立
        $expectedIssuer = rtrim((string) \Hyperf\Config\config('app_url', ''), '/');
        $this->assertSame($expectedIssuer, $data['issuer']);
        $this->assertSame($expectedIssuer . '/oauth/authorize', $data['authorization_endpoint']);
        $this->assertStringStartsWith($expectedIssuer, $data['jwks_uri']);

        $this->assertContains('S256', $data['code_challenge_methods_supported']);
        $this->assertContains('authorization_code', $data['grant_types_supported']);
        $this->assertContains('urn:ietf:params:oauth:grant-type:device_code', $data['grant_types_supported']);
    }

    public function testJwksReturnsRsaKey(): void
    {
        $response = $this->request('GET', '/.well-known/jwks.json');
        $data = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('keys', $data);
        $this->assertNotEmpty($data['keys']);
        $this->assertSame('RSA', $data['keys'][0]['kty']);
        $this->assertSame('RS256', $data['keys'][0]['alg']);
    }

    public function testProtectedPageRedirectsToLogin(): void
    {
        $response = $this->request('GET', '/dashboard', ['headers' => ['Accept' => 'text/html']]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testUserinfoRequiresToken(): void
    {
        $response = $this->request('GET', '/userinfo');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('invalid_token', (string) $response->getBody());
    }

    public function testUnknownPathReturnsHtmlErrorPage(): void
    {
        $response = $this->request('GET', '/this-path-does-not-exist', ['headers' => ['Accept' => 'text/html']]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('页面不存在', (string) $response->getBody());
    }

    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $response = $this->request('POST', '/login', [
            'headers' => ['Accept' => 'text/html'],
            'form_params' => ['identifier' => 'a', 'password' => 'b'],
        ]);

        $this->assertSame(419, $response->getStatusCode());
    }
}
