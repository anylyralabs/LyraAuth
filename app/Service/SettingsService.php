<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\OauthClient;
use App\Model\OauthScope;
use App\Model\User;
use Hyperf\Database\Model\Collection;

/**
 * 站点设置与表单校验的共用规则。
 */
class SettingsService
{
    /** 允许自助注册 */
    public function registrationEnabled(): bool
    {
        return $this->getBool('registration_enabled', true);
    }

    /** 注册后是否需要邮箱验证才能登录 */
    public function requireEmailVerification(): bool
    {
        return $this->getBool('require_email_verification', true);
    }

    /** 只有管理员能创建应用 */
    public function restrictClientCreation(): bool
    {
        return $this->getBool('restrict_client_creation', false);
    }

    public function siteName(): string
    {
        return (string) $this->get('site_name', 'LyraAuth');
    }

    public function supportEmail(): string
    {
        return (string) $this->get('support_email', '');
    }

    public function defaultScopes(): array
    {
        return OauthScope::query()->where('is_default', true)->pluck('identifier')->all();
    }

    /** 客户端可申请的 scope：绑定关系为空则视为全部可用 */
    public function scopesForClient(OauthClient $client): array
    {
        $bound = \App\Model\OauthClientScope::query()
            ->where('client_id', $client->id)
            ->pluck('scope_identifier')
            ->all();

        if ($bound === []) {
            return OauthScope::query()->pluck('identifier')->all();
        }

        return $bound;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $row = \App\Model\Setting::query()->where('key', $key)->first();
            if ($row === null) {
                return $default;
            }

            return $row->is_encrypted ? $this->decryptValue($row->value) : $row->value;
        } catch (\Throwable) {
            return $default;
        }
    }

    public function getBool(string $key, bool $default): bool
    {
        $value = $this->get($key, null);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    public function set(string $key, ?string $value, string $group = 'general', bool $encrypt = false): void
    {
        $stored = $encrypt && $value !== null && $value !== ''
            ? \Hyperf\Context\ApplicationContext::getContainer()->get(\App\Support\Crypto::class)->encrypt($value)
            : $value;

        \App\Model\Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $stored, 'is_encrypted' => $encrypt, 'group' => $group]
        );
    }

    private function decryptValue(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return \Hyperf\Context\ApplicationContext::getContainer()->get(\App\Support\Crypto::class)->decrypt($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
