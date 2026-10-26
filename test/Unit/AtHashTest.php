<?php

declare(strict_types=1);

namespace HyperfTest\Unit;

use App\Service\IdTokenService;
use PHPUnit\Framework\TestCase;

/**
 * at_hash 计算规则测试（OIDC Core §3.1.3.6）。
 *
 * @internal
 * @coversNothing
 */
class AtHashTest extends TestCase
{
    public function testAtHashAlgorithm(): void
    {
        $service = $this->createService();

        $token = 'header.payload.signature';
        $expected = rtrim(strtr(base64_encode(substr(hash('sha256', $token, true), 0, 16)), '+/', '-_'), '=');

        $this->assertSame($expected, $service->atHash($token));
    }

    public function testAtHashIsDeterministic(): void
    {
        $service = $this->createService();

        $this->assertSame($service->atHash('a.b.c'), $service->atHash('a.b.c'));
        $this->assertNotSame($service->atHash('a.b.c'), $service->atHash('a.b.d'));
    }

    public function testAtHashIsBase64UrlWithoutPadding(): void
    {
        $hash = $this->createService()->atHash('some.jwt.value');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', $hash);
    }

    private function createService(): IdTokenService
    {
        // atHash 不依赖密钥与配置，用反射构造即可避免额外依赖
        $reflection = new \ReflectionClass(IdTokenService::class);

        return $reflection->newInstanceWithoutConstructor();
    }
}
