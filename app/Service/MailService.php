<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\User;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\TranslatorInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * 邮件发送。
 *
 * 驱动：
 * - log：写入 runtime/logs/mail.log（开发默认，无需 SMTP）
 * - smtp：直连 SMTP 服务器，支持三种加密方式
 *   - tls      STARTTLS（默认，通常 587 端口）
 *   - ssl      隐式 TLS（通常 465 端口）
 *   - 空字符串  明文（仅限内网或本地测试）
 *
 * 实现说明：
 * - 用 Swoole 协程客户端，避免阻塞 worker；
 * - 读取多行响应（SMTP 的 250-XXX\r\n250 OK 形式），逐行判断，避免误判；
 * - 所有异常路径都会关闭连接，防止协程内 fd 泄漏。
 */
class MailService
{
    /** 单条命令的读取超时（秒） */
    private const TIMEOUT = 15.0;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ConfigInterface $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function sendVerification(User $user, string $token): bool
    {
        $link = rtrim((string) $this->config->get('app_url'), '/') . '/email/verify/' . $token;

        return $this->send(
            $user->email,
            '验证你的邮箱地址',
            "你好 {$user->display_name}：\n\n"
            . "请点击下面的链接完成邮箱验证（24 小时内有效）：\n{$link}\n\n"
            . "如果这不是你本人的操作，请忽略本邮件。\n\nLyraAuth"
        );
    }

    public function sendPasswordReset(User $user, string $token): bool
    {
        $link = rtrim((string) $this->config->get('app_url'), '/') . '/password/reset/' . $token;

        return $this->send(
            $user->email,
            '重置你的密码',
            "你好 {$user->display_name}：\n\n"
            . "我们收到了重置密码的请求。请点击下面的链接设置新密码（1 小时内有效）：\n{$link}\n\n"
            . "如果这不是你本人的操作，请忽略本邮件，你的密码不会改变。\n\nLyraAuth"
        );
    }

    /** 通用发送入口 */
    public function send(string $to, string $subject, string $body): bool
    {
        // 优先使用管理后台配置，未配置时回退到 .env
        $driver = (string) $this->setting('mail_driver', $this->config->get('mail.driver', 'log'));

        if ($driver === 'log') {
            $this->writeToLog($to, $subject, $body);

            return true;
        }

        try {
            return $this->sendViaSmtp($to, $subject, $body);
        } catch (\Throwable $e) {
            $this->logger->error('邮件发送失败：' . $e->getMessage(), ['to' => $to]);

            return false;
        }
    }

    /**
     * 实际投递一封纯文本邮件。
     *
     * @throws \RuntimeException 连接、握手、认证或投递失败
     */
    private function sendViaSmtp(string $to, string $subject, string $body): bool
    {
        $host = (string) $this->setting('mail_host', $this->config->get('mail.host', ''));
        $port = (int) $this->setting('mail_port', $this->config->get('mail.port', 587));
        $encryption = strtolower((string) $this->setting('mail_encryption', $this->config->get('mail.encryption', 'tls')));

        if ($host === '') {
            throw new \RuntimeException('SMTP 主机未配置。');
        }

        $username = (string) $this->setting('mail_username', $this->config->get('mail.username', ''));
        $password = (string) $this->setting('mail_password', $this->config->get('mail.password', ''));
        $authMethod = strtolower((string) $this->setting('mail_auth_method', $this->config->get('mail.auth_method', 'login')));
        $fromAddress = trim((string) $this->setting('mail_from_address', $this->config->get('mail.from.address', 'no-reply@lyraauth.local')));
        $fromName = (string) $this->setting('mail_from_name', $this->config->get('mail.from.name', 'LyraAuth'));

        // XOAUTH2 模式下用户名是授权账号，密码字段不参与认证
        if ($authMethod === 'xoauth2') {
            $oauth = $this->container->get(MicrosoftOAuthService::class);
            $authorized = $oauth->accountEmail();
            if ($authorized !== '') {
                $username = $authorized;
            }
        }

        // 空 From 会被服务端拒绝（550），回退到认证账号，再回退到本地默认值
        if ($fromAddress === '') {
            $fromAddress = $username !== '' ? $username : 'no-reply@lyraauth.local';
        }

        // 465 通常是隐式 TLS；未显式指定时按端口推断，避免用户漏配
        if ($encryption === '' && $port === 465) {
            $encryption = 'ssl';
        }
        if ($encryption === '' && $port === 587) {
            $encryption = 'tls';
        }

        $socketType = SWOOLE_SOCK_TCP;
        if ($encryption === 'ssl') {
            $socketType = SWOOLE_SOCK_TCP | SWOOLE_SSL;
        }

        $verifyPeer = filter_var(
            $this->setting('mail_verify_peer', $this->config->get('mail.verify_peer', true)),
            FILTER_VALIDATE_BOOLEAN
        );

        $client = new \Swoole\Coroutine\Client($socketType);
        $client->set([
            'timeout' => self::TIMEOUT,
            'connect_timeout' => self::TIMEOUT,
            // 生产环境应保持开启；自签证书场景可在后台或 .env 关闭
            'ssl_verify_peer' => $verifyPeer,
            'ssl_allow_self_signed' => ! $verifyPeer,
        ]);

        try {
            if (! $client->connect($host, $port, self::TIMEOUT)) {
                throw new \RuntimeException(sprintf(
                    '无法连接 SMTP 服务器 %s:%d（errCode %d）',
                    $host,
                    $port,
                    $client->errCode
                ));
            }

            $expect = function (string $code, string $stage) use ($client): string {
                $response = $this->readResponse($client);
                if (! str_starts_with($response, $code)) {
                    throw new \RuntimeException(sprintf(
                        'SMTP %s阶段响应异常（期望 %s）：%s',
                        $stage,
                        $code,
                        $this->abbreviate($response)
                    ));
                }

                return $response;
            };

            $say = function (string $command, string $expectCode, string $stage) use ($client, $expect): string {
                $this->write($client, $command . "\r\n");

                return $expect($expectCode, $stage);
            };

            $expect('220', '连接');

            $hostname = (string) (parse_url((string) $this->config->get('app_url', 'localhost'), PHP_URL_HOST) ?: 'localhost');
            $ehlo = $say('EHLO ' . $hostname, '250', 'EHLO');

            // STARTTLS：明文握手后升级为加密连接
            if ($encryption === 'tls') {
                if (stripos($ehlo, 'STARTTLS') === false) {
                    throw new \RuntimeException('服务器未提供 STARTTLS，请改用 ssl（465）或关闭加密。');
                }

                $say('STARTTLS', '220', 'STARTTLS');

                if (! $client->enableSSL()) {
                    throw new \RuntimeException('TLS 握手失败，无法升级加密连接。');
                }

                // 升级后按 RFC 3207 重新 EHLO
                $say('EHLO ' . $hostname, '250', 'EHLO(TLS)');
            }

            if ($authMethod === 'xoauth2') {
                // RFC 7628 / 微软文档：AUTH XOAUTH2 base64("user=<email>\x01auth=Bearer <token>\x01\x01")
                $oauth = $this->container->get(MicrosoftOAuthService::class);
                $tokenResult = $oauth->accessToken();
                if (! ($tokenResult['ok'] ?? false)) {
                    throw new \RuntimeException('获取微软 OAuth2 访问令牌失败：' . ($tokenResult['error'] ?? '未知错误'));
                }

                $sasl = base64_encode(sprintf(
                    "user=%s\x01auth=Bearer %s\x01\x01",
                    $username,
                    (string) $tokenResult['token']
                ));

                $this->write($client, "AUTH XOAUTH2 {$sasl}\r\n");
                $response = $this->readResponse($client);

                if (! str_starts_with($response, '235')) {
                    // 失败时服务器会返回 base64 编码的 JSON 详情，解码后更有助于排查
                    $detail = $this->decodeXoauth2Error($response);
                    throw new \RuntimeException(sprintf(
                        'SMTP XOAUTH2 认证失败：%s%s',
                        $this->abbreviate($response),
                        $detail !== '' ? '（' . $detail . '）' : ''
                    ));
                }
            } elseif ($username !== '') {
                $say('AUTH LOGIN', '334', 'AUTH');
                $say(base64_encode($username), '334', 'AUTH 用户名');
                $say(base64_encode($password), '235', 'AUTH 密码');
            }

            $say('MAIL FROM:<' . $fromAddress . '>', '250', 'MAIL FROM');
            $say('RCPT TO:<' . $to . '>', '250', 'RCPT TO');
            $say('DATA', '354', 'DATA');

            $this->write($client, $this->buildMessage($to, $subject, $body, $fromName, $fromAddress) . "\r\n.\r\n");
            $expect('250', '邮件体');

            try {
                $this->write($client, "QUIT\r\n");
                $this->readResponse($client);
            } catch (\Throwable) {
                // QUIT 失败不影响投递结果
            }

            return true;
        } finally {
            if ($client->isConnected()) {
                $client->close();
            }
        }
    }

    /**
     * 读取一个完整的 SMTP 响应（可能是多行）。
     *
     * 多行响应格式：`250-First line\r\n250 Second line\r\n`，
     * 最后一行使用空格分隔，据此判断结束。
     */
    private function readResponse(\Swoole\Coroutine\Client $client): string
    {
        $buffer = '';

        while (true) {
            $chunk = $client->recv(self::TIMEOUT);

            if ($chunk === false || $chunk === '') {
                // 连接被对端关闭：若已收到部分内容（例如 535 认证失败后就断开），
                // 把内容交给上层判断，而不是笼统报「无响应」
                if ($buffer !== '') {
                    break;
                }

                $errCode = $client->errCode;
                throw new \RuntimeException(
                    $errCode === 0
                        ? 'SMTP 服务器无响应或已断开连接'
                        : sprintf('SMTP 连接读取出错（errCode %d）', $errCode)
                );
            }

            $buffer .= $chunk;

            // 逐行检查是否已收到结束行（形如 "250 xxx"）
            $lines = preg_split('/\r\n/', rtrim($buffer, "\r\n")) ?: [];
            $last = end($lines);
            if (is_string($last) && preg_match('/^\d{3} /', $last) === 1) {
                break;
            }
        }

        return trim($buffer);
    }

    private function write(\Swoole\Coroutine\Client $client, string $data): void
    {
        if ($client->send($data) === false) {
            throw new \RuntimeException('向 SMTP 服务器写入失败（连接可能已断开）');
        }
    }

    private function buildMessage(string $to, string $subject, string $body, string $fromName, string $fromAddress): string
    {
        $headers = [
            'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromAddress . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (parse_url($fromAddress, PHP_URL_HOST) ?: 'lyraauth.local') . '>',
        ];

        // 正文 base64 后每行 76 字符，避免超过 SMTP 行长度限制
        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
    }

    private function abbreviate(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return mb_substr($value, 0, 160);
    }

    /**
     * 解码 XOAUTH2 失败详情。
     *
     * 微软在 334/535 响应后会跟一段 base64 的 JSON：
     * {"status":"401","schemes":"bearer","scope":"https://outlook.office.com/SMTP.Send"}
     */
    private function decodeXoauth2Error(string $response): string
    {
        if (! preg_match('/([A-Za-z0-9+\/=]{20,})/', $response, $matches)) {
            return '';
        }

        $decoded = base64_decode($matches[1], true);
        if ($decoded === false) {
            return '';
        }

        $data = json_decode($decoded, true);
        if (! is_array($data)) {
            return '';
        }

        $parts = [];
        foreach (['status', 'schemes', 'scope', 'error'] as $key) {
            if (! empty($data[$key]) && is_string($data[$key])) {
                $parts[] = $key . '=' . $data[$key];
            }
        }

        return implode(' ', $parts);
    }

    /**
     * 读取站点设置，异常时回退到默认值。
     */
    private function setting(string $key, mixed $default): mixed
    {
        try {
            $row = \App\Model\Setting::query()->where('key', $key)->first();
            if ($row === null || $row->value === null || $row->value === '') {
                return $default;
            }

            if ($row->is_encrypted) {
                $crypto = $this->container->get(\App\Support\Crypto::class);

                return $crypto->decrypt($row->value);
            }

            return $row->value;
        } catch (\Throwable) {
            return $default;
        }
    }

    private function writeToLog(string $to, string $subject, string $body): void
    {
        $dir = BASE_PATH . '/runtime/logs';
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $entry = sprintf(
            "[%s] To: %s\nSubject: %s\n%s\n%s\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            str_repeat('-', 60),
            $body
        );

        @file_put_contents($dir . '/mail.log', $entry, FILE_APPEND | LOCK_EX);
    }
}
