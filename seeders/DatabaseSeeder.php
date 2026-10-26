<?php

declare(strict_types=1);

use App\Model\OauthScope;
use App\Model\Role;
use App\Model\User;
use App\Service\AuthService;
use App\Support\Support;
use Hyperf\Database\Seeders\Seeder;

/**
 * 初始数据：角色、OIDC scope、管理员账号。
 *
 * Hyperf 的 db:seed 通过文件名解析类名且不做命名空间处理，
 * 因此这里不能声明 namespace。
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedRoles();
        $this->seedScopes();
        $this->seedAdmin();
    }

    private function seedRoles(): void
    {
        $roles = [
            [Role::ADMIN, '管理员', '可访问管理后台并管理全部资源'],
            [Role::USER, '普通用户', '可管理自己的账号与已授权应用'],
        ];

        foreach ($roles as [$slug, $name, $description]) {
            Role::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'description' => $description, 'is_system' => true]
            );
        }
    }

    private function seedScopes(): void
    {
        $scopes = [
            [OauthScope::OPENID, 'OpenID', '以 OpenID Connect 方式认证用户身份', true, true],
            [OauthScope::PROFILE, '基本资料', '读取显示名称、头像等公开资料', true, true],
            [OauthScope::EMAIL, '邮箱地址', '读取邮箱地址与验证状态', true, true],
            [OauthScope::ROLES, '角色', '读取平台角色列表', false, true],
            [OauthScope::ORGANIZATIONS, '组织', '读取所属组织与成员角色', false, true],
            [OauthScope::OFFLINE_ACCESS, '离线访问', '允许在用户离线时刷新令牌', false, true],
        ];

        foreach ($scopes as [$identifier, $name, $description, $default, $system]) {
            OauthScope::query()->updateOrCreate(
                ['identifier' => $identifier],
                ['name' => $name, 'description' => $description, 'is_default' => $default, 'is_system' => $system]
            );
        }
    }

    private function seedAdmin(): void
    {
        $email = 'admin@lyraauth.local';

        if (User::withTrashed()->where('email', $email)->exists()) {
            return;
        }

        $password = Support::randomPassword();

        User::create([
            'uuid' => Support::uuid(),
            'username' => 'admin',
            'email' => $email,
            'display_name' => '平台管理员',
            'password_hash' => AuthService::hashPassword($password),
            'status' => User::STATUS_ACTIVE,
            'platform_role' => User::ROLE_ADMIN,
            'email_verified_at' => date('Y-m-d H:i:s'),
            'must_change_password' => true,
            'password_changed_at' => date('Y-m-d H:i:s'),
            'avatar_color' => '#2F4D5E',
        ]);

        $this->writeCredentialsLog($email, $password);
    }

    private function writeCredentialsLog(string $email, string $password): void
    {
        $dir = BASE_PATH . '/runtime/logs';
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            $dir . '/seed-credentials.log',
            sprintf("[%s] 管理员账号：%s  初始密码：%s\n", date('Y-m-d H:i:s'), $email, $password),
            FILE_APPEND | LOCK_EX
        );

        // Seeder 基类没有 output 属性，直接写 STDOUT
        fwrite(STDOUT, sprintf(
            "\n管理员账号：%s\n初始密码：%s\n（同样写入 runtime/logs/seed-credentials.log，请首次登录后立即修改）\n\n",
            $email,
            $password
        ));
    }
}
