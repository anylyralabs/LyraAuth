<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\OAuthKeyService;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * 轮换 OAuth2 / OIDC 签名密钥。
 *
 * 新密钥立即用于签发；旧公钥保留在 JWKS 中（默认保留最近 3 把），
 * 已签发的令牌在过期前仍可被验证。
 */
#[Command]
class RotateKeysCommand extends HyperfCommand
{
    protected ?string $name = 'oauth:keys:rotate';

    public function __construct(private readonly OAuthKeyService $keys)
    {
        parent::__construct();
    }

    public function configure(): void
    {
        parent::configure();
        $this->setDescription('生成新的 OAuth2 / OIDC 签名密钥');
        $this->addOption('bits', null, InputOption::VALUE_REQUIRED, 'RSA 密钥长度', '2048');
        $this->addOption('force', null, InputOption::VALUE_NONE, '跳过确认');
    }

    public function handle(): int
    {
        $current = $this->keys->activeKeyId();
        $this->line('当前密钥：' . ($current ?? '（尚无）'));

        if (! $this->input->getOption('force') && $current !== null) {
            if (! $this->confirm('轮换后新令牌将使用新密钥签发，确认继续？')) {
                $this->comment('已取消。');

                return 0;
            }
        }

        $bits = (int) $this->input->getOption('bits');
        if ($bits < 2048) {
            $this->error('RSA 密钥长度不得小于 2048 位。');

            return 1;
        }

        $kid = $this->keys->rotate($bits);

        $this->info('新密钥已生效：' . $kid);
        $this->line('JWKS 中保留的公钥数量：' . count($this->keys->availableKeyIds()));
        $this->comment('已签发的令牌在过期前仍可通过旧公钥验证。');

        return 0;
    }
}
