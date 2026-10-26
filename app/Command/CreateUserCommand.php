<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\User;
use App\Service\AuthService;
use App\Support\Support;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * 创建账号，用于本地测试或管理员手工开通。
 *
 *   php bin/hyperf.php user:create --email=a@b.c --password=... --admin
 */
#[Command]
class CreateUserCommand extends HyperfCommand
{
    protected ?string $name = 'user:create';

    public function configure(): void
    {
        parent::configure();
        $this->setDescription('创建一个用户账号');
        $this->addOption('email', null, InputOption::VALUE_REQUIRED, '邮箱');
        $this->addOption('password', null, InputOption::VALUE_REQUIRED, '密码，留空则随机生成');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, '显示名称');
        $this->addOption('admin', null, InputOption::VALUE_NONE, '授予管理员权限');
        $this->addOption('verified', null, InputOption::VALUE_NONE, '直接标记邮箱已验证');
    }

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->input->getOption('email')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('请提供有效的 --email。');

            return 1;
        }

        if (User::withTrashed()->where('email', $email)->exists()) {
            $this->error('该邮箱已存在。');

            return 1;
        }

        $password = (string) ($this->input->getOption('password') ?: Support::randomPassword());
        $displayName = (string) ($this->input->getOption('name') ?: explode('@', $email)[0]);
        $username = $this->uniqueUsername($displayName);

        $user = User::create([
            'uuid' => Support::uuid(),
            'username' => $username,
            'email' => $email,
            'display_name' => $displayName,
            'password_hash' => AuthService::hashPassword($password),
            'status' => User::STATUS_ACTIVE,
            'platform_role' => $this->input->getOption('admin') ? User::ROLE_ADMIN : User::ROLE_USER,
            'email_verified_at' => $this->input->getOption('verified') ? date('Y-m-d H:i:s') : null,
            'password_changed_at' => date('Y-m-d H:i:s'),
            'must_change_password' => false,
        ]);

        $this->line('');
        $this->info('账号已创建：');
        $this->line('  邮箱:   ' . $user->email);
        $this->line('  用户名: ' . $user->username);
        $this->line('  密码:   ' . $password);
        $this->line('');

        return 0;
    }

    private function uniqueUsername(string $base): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9_.-]/', '', $base) ?: 'user';
        $slug = substr($slug, 0, 48);
        $candidate = $slug;
        $i = 1;

        while (User::withTrashed()->where('username', $candidate)->exists()) {
            $candidate = $slug . $i;
            ++$i;
        }

        return $candidate;
    }
}
