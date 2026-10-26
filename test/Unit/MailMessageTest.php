<?php

declare(strict_types=1);

namespace HyperfTest\Unit;

use App\Service\MailService;
use PHPUnit\Framework\TestCase;

/**
 * 邮件报文构造测试（不发起网络请求）。
 *
 * @internal
 * @coversNothing
 */
class MailMessageTest extends TestCase
{
    public function testBuildMessageEncodesChineseHeaders(): void
    {
        $service = $this->createService();
        $method = new \ReflectionMethod($service, 'buildMessage');
        $method->setAccessible(true);

        $message = $method->invoke(
            $service,
            'receiver@example.com',
            '验证你的邮箱地址',
            '点击链接完成验证',
            'LyraAuth',
            'no-reply@example.com'
        );

        // 头与正文之间是空行
        $this->assertStringContainsString("\r\n\r\n", $message);

        [$headers, $body] = explode("\r\n\r\n", $message, 2);

        $this->assertStringContainsString('To: <receiver@example.com>', $headers);
        $this->assertStringContainsString('MIME-Version: 1.0', $headers);
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $headers);
        $this->assertStringContainsString('Content-Transfer-Encoding: base64', $headers);
        $this->assertStringContainsString('Message-ID:', $headers);

        // 中文主题与发件人必须做 RFC 2047 编码
        $this->assertMatchesRegularExpression('/Subject: =\?UTF-8\?B\?[A-Za-z0-9+\/=]+\?=/', $headers);
        $this->assertMatchesRegularExpression('/From: =\?UTF-8\?B\?[A-Za-z0-9+\/=]+\?= </', $headers);
        $this->assertStringContainsString('no-reply@example.com', $headers);

        // 正文 base64 解码后应与原文一致
        $decoded = base64_decode(str_replace(["\r\n", "\n"], '', $body), true);
        $this->assertSame('点击链接完成验证', $decoded);
    }

    public function testBuildMessageWrapsBodyAt76Chars(): void
    {
        $service = $this->createService();
        $method = new \ReflectionMethod($service, 'buildMessage');
        $method->setAccessible(true);

        $body = str_repeat('这是一段较长的中文正文，用于验证换行处理。', 20);
        $message = $method->invoke($service, 'a@b.c', 'Subject', $body, 'LyraAuth', 'no-reply@example.com');

        [, $payload] = explode("\r\n\r\n", $message, 2);

        foreach (explode("\r\n", trim($payload)) as $line) {
            $this->assertLessThanOrEqual(76, strlen($line), 'Base64 行超过 76 字符');
        }

        $decoded = base64_decode(str_replace(["\r\n", "\n"], '', $payload), true);
        $this->assertSame($body, $decoded);
    }

    public function testAbbreviateTruncatesLongResponse(): void
    {
        $service = $this->createService();
        $method = new \ReflectionMethod($service, 'abbreviate');
        $method->setAccessible(true);

        $long = str_repeat("550 error line\n", 40);
        $result = $method->invoke($service, $long);

        $this->assertLessThanOrEqual(161, mb_strlen($result));
        $this->assertStringNotContainsString("\n", $result);
    }

    private function createService(): MailService
    {
        $container = \Hyperf\Context\ApplicationContext::getContainer();

        return $container->get(MailService::class);
    }
}
