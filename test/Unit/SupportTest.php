<?php

declare(strict_types=1);

namespace HyperfTest\Unit;

use App\Support\Crypto;
use App\Support\Support;
use App\Support\View;
use PHPUnit\Framework\TestCase;

/**
 * 纯函数与工具类测试（不依赖数据库与网络）。
 *
 * @internal
 * @coversNothing
 */
class SupportTest extends TestCase
{
    public function testUuidIsVersion4(): void
    {
        $uuid = Support::uuid();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid
        );
        $this->assertNotSame($uuid, Support::uuid());
    }

    public function testRandomPasswordMeetsOwnPolicy(): void
    {
        $password = Support::randomPassword();

        $this->assertGreaterThanOrEqual(10, strlen($password));
        $this->assertMatchesRegularExpression('/[A-Za-z]/', $password);
        $this->assertMatchesRegularExpression('/\d/', $password);
    }

    public function testSafeRedirectAcceptsRelativePaths(): void
    {
        $this->assertSame('/dashboard', View::safeRedirect('/dashboard'));
        $this->assertSame('/account/security?tab=totp', View::safeRedirect('/account/security?tab=totp'));
    }

    public function testSafeRedirectRejectsOpenRedirect(): void
    {
        foreach ([
            'https://evil.example.com/',
            '//evil.example.com/',
            '/\\evil.example.com',
            'javascript:alert(1)',
            '',
            null,
        ] as $target) {
            $this->assertSame('/dashboard', View::safeRedirect($target), 'Should reject: ' . var_export($target, true));
        }
    }

    public function testSafeRedirectTrimsLeadingWhitespace(): void
    {
        // 前导空格不应绕过校验，最终应指向合法相对路径
        $result = View::safeRedirect(' /spaces');
        $this->assertStringStartsWith('/', $result);
        $this->assertStringNotContainsString(' ', $result);
    }

    public function testCryptoRoundTrip(): void
    {
        $crypto = $this->makeCrypto();

        $plaintext = 'JBSWY3DPEHPK3PXP';
        $cipher = $crypto->encrypt($plaintext);

        $this->assertStringStartsWith('v1.', $cipher);
        $this->assertNotSame($plaintext, $cipher);
        $this->assertSame($plaintext, $crypto->decrypt($cipher));
    }

    public function testCryptoRejectsTamperedPayload(): void
    {
        $crypto = $this->makeCrypto();
        $cipher = $crypto->encrypt('secret');

        $this->expectException(\RuntimeException::class);
        $crypto->decrypt(substr($cipher, 0, -4) . 'AAAA');
    }

    public function testHashIsStableAndNotReversible(): void
    {
        $this->assertSame(Crypto::hash('token'), Crypto::hash('token'));
        $this->assertSame(64, strlen(Crypto::hash('token')));
        $this->assertNotSame('token', Crypto::hash('token'));
    }

    public function testContrastTextKeepsInitialsReadable(): void
    {
        $this->assertSame('#14171A', Support::contrastText('#FFFFFF'));
        $this->assertSame('#14171A', Support::contrastText('#fff'));
        $this->assertSame('#FFFFFF', Support::contrastText('#14171A'));
        $this->assertSame('#FFFFFF', Support::contrastText('#96631C'));
        // 非法输入回退到浅色前景，避免生成无法阅读的文字
        $this->assertSame('#FFFFFF', Support::contrastText(''));
        $this->assertSame('#FFFFFF', Support::contrastText('not-a-color'));
    }

    public function testRandomTokenIsUrlSafe(): void
    {
        $token = Crypto::randomToken(32);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
    }

    public function testMaskEmailKeepsDomain(): void
    {
        $this->assertSame('a***n@example.com', View::maskEmail('admin@example.com'));
        $this->assertStringEndsWith('@example.com', View::maskEmail('a@example.com'));
    }

    public function testRelativeTimeBuckets(): void
    {
        $now = time();

        $this->assertSame('刚刚', View::relativeTime($now - 10));
        $this->assertStringContainsString('分钟前', View::relativeTime($now - 120));
        $this->assertStringContainsString('小时前', View::relativeTime($now - 7200));
        $this->assertStringContainsString('天前', View::relativeTime($now - 172800));
        $this->assertSame('—', View::relativeTime(null));
    }

    private function makeCrypto(): Crypto
    {
        $container = \Hyperf\Context\ApplicationContext::getContainer();

        return new Crypto($container);
    }
}
