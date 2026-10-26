<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\MailService;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Contract\ConfigInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * 发送测试邮件，用于验证 SMTP 配置是否正确。
 *
 *   php bin/hyperf.php mail:test --to=you@example.com
 *   php bin/hyperf.php mail:test --to=you@example.com --port=2587 --encryption=tls --verify=false
 *
 * 参数优先级：命令行 > 站点设置 > .env。
 */
#[Command]
class MailTestCommand extends HyperfCommand
{
    protected ?string $name = 'mail:test';

    public function __construct(
        private readonly ConfigInterface $config,
        private readonly MailService $mail
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        parent::configure();
        $this->setDescription('发送一封测试邮件以验证邮件配置');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, '收件人地址');
        $this->addOption('host', null, InputOption::VALUE_REQUIRED, 'SMTP 主机（覆盖配置）');
        $this->addOption('port', null, InputOption::VALUE_REQUIRED, 'SMTP 端口');
        $this->addOption('encryption', null, InputOption::VALUE_REQUIRED, 'tls / ssl / none');
        $this->addOption('username', null, InputOption::VALUE_REQUIRED, 'SMTP 用户名');
        $this->addOption('password', null, InputOption::VALUE_REQUIRED, 'SMTP 密码');
        $this->addOption('verify', null, InputOption::VALUE_REQUIRED, '是否校验证书：true / false');
    }

    public function handle(): int
    {
        $to = (string) $this->input->getOption('to');
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('请用 --to 指定有效的收件人地址。');

            return 1;
        }

        // 命令行覆盖写入配置，MailService 会优先读取数据库设置，
        // 因此这里同时清空 settings 中的对应键，避免互相干扰。
        $overrides = [
            'mail.host' => $this->input->getOption('host'),
            'mail.port' => $this->input->getOption('port'),
            'mail.username' => $this->input->getOption('username'),
            'mail.password' => $this->input->getOption('password'),
            'mail.encryption' => $this->input->getOption('encryption'),
        ];

        foreach ($overrides as $key => $value) {
            if ($value !== null) {
                $this->config->set($key, $value);
                $this->clearSetting(str_replace('mail.', 'mail_', $key));
            }
        }

        $verify = $this->input->getOption('verify');
        if ($verify !== null) {
            $this->config->set('mail.verify_peer', filter_var($verify, FILTER_VALIDATE_BOOLEAN));
        }

        // 强制使用 smtp 驱动，避免误用 log
        $this->config->set('mail.driver', 'smtp');
        $this->clearSetting('mail_driver');

        $this->line(sprintf(
            '使用 %s:%s（%s），发件人 %s',
            (string) $this->config->get('mail.host', ''),
            (string) $this->config->get('mail.port', '587'),
            (string) $this->config->get('mail.encryption', 'tls') ?: 'plain',
            (string) $this->config->get('mail.from.address', '')
        ));

        $started = microtime(true);
        $ok = $this->mail->send($to, 'LyraAuth SMTP 测试', "这是一封测试邮件，用于验证 SMTP 配置。\n发送时间：" . date('Y-m-d H:i:s'));
        $elapsed = round((microtime(true) - $started) * 1000);

        if (! $ok) {
            $this->error('发送失败，请查看 runtime/logs/hyperf.log 中的详细错误。');

            return 1;
        }

        $this->info(sprintf('发送成功（耗时 %d ms）。', $elapsed));

        return 0;
    }

    private function clearSetting(string $key): void
    {
        try {
            \App\Model\Setting::query()->where('key', $key)->delete();
        } catch (\Throwable) {
            // 忽略：数据库不可用时仍可按配置发送
        }
    }
}
