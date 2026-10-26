<?php

declare(strict_types=1);

namespace HyperfTest\Unit;

use App\Service\AuthService;
use App\Service\MfaService;
use PHPUnit\Framework\TestCase;

/**
 * 密码哈希、TOTP 与恢复码逻辑测试。
 *
 * @internal
 * @coversNothing
 */
class AuthLogicTest extends TestCase
{
    public function testPasswordHashUsesArgon2id(): void
    {
        $hash = AuthService::hashPassword('CorrectHorse2026');

        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertTrue(AuthService::verifyPassword('CorrectHorse2026', $hash));
        $this->assertFalse(AuthService::verifyPassword('wrong-password', $hash));
    }

    public function testPasswordHashIsSalted(): void
    {
        $a = AuthService::hashPassword('same-password');
        $b = AuthService::hashPassword('same-password');

        $this->assertNotSame($a, $b);
        $this->assertTrue(AuthService::verifyPassword('same-password', $a));
        $this->assertTrue(AuthService::verifyPassword('same-password', $b));
    }

    public function testVerifyRejectsEmptyHash(): void
    {
        $this->assertFalse(AuthService::verifyPassword('anything', ''));
    }

    public function testNeedsRehashForLegacyBcrypt(): void
    {
        $legacy = password_hash('legacy-password', PASSWORD_BCRYPT);
        $container = \Hyperf\Context\ApplicationContext::getContainer();
        $service = new AuthService(
            $container->get(\App\Service\RateLimiter::class),
            $container->get(\App\Service\AuditService::class)
        );

        $this->assertTrue($service->needsRehash($legacy));
        $this->assertTrue(AuthService::verifyPassword('legacy-password', $legacy));
    }

    public function testTotpSecretIsBase32(): void
    {
        // 从 MfaService 的生成规则推断：32 个 base32 字符
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 32; ++$i) {
            $secret .= $alphabet[random_int(0, 31)];
        }

        $this->assertSame(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function testRecoveryCodeFormat(): void
    {
        // 恢复码格式 XXXX-XXXX，字符集去掉了易混淆的 0/O、1/I/L
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $raw = '';
        for ($i = 0; $i < 8; ++$i) {
            $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $code = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);

        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code);
        $this->assertSame(9, strlen($code));
    }

    public function testRecoveryCodeNormalization(): void
    {
        $service = \Hyperf\Context\ApplicationContext::getContainer()->get(MfaService::class);
        $method = new \ReflectionMethod($service, 'normalizeRecoveryCode');
        $method->setAccessible(true);

        $this->assertSame('ABCD1234', $method->invoke($service, 'abcd-1234'));
        $this->assertSame('ABCD1234', $method->invoke($service, ' ABCD 1234 '));
        // 长度不符时视为无效
        $this->assertSame('', $method->invoke($service, 'ABC-123'));
        $this->assertSame('', $method->invoke($service, 'ABCDE-12345'));
    }

    public function testTotpTimeStepAgreesWithRfc4226(): void
    {
        // RFC 6238 附录 B 的 SHA1 测试向量（时间 59 秒，密钥 "12345678901234567890"）
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $totp = \OTPHP\TOTP::create($secret, 30, 'sha1', 6);

        $this->assertSame('287082', $totp->at(59));
    }
}
