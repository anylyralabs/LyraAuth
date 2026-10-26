<?php

declare(strict_types=1);

namespace App\Model;

/**
 * 登录会话记录。
 *
 * 与 Redis 中的会话数据同步维护，用于“登录设备”列表与远程下线。
 *
 * @property int $id
 * @property int $user_id
 * @property string $session_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $device_label
 */
class UserSession extends Model
{
    protected ?string $table = 'user_sessions';

    protected array $casts = [
        'last_activity_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->getTimestamp() > time();
    }

    /** 从 UA 粗略推断设备描述，避免展示整串 UA */
    public static function describe(?string $userAgent): string
    {
        $ua = strtolower((string) $userAgent);
        if ($ua === '') {
            return '未知设备';
        }

        $platform = match (true) {
            str_contains($ua, 'iphone') => 'iPhone',
            str_contains($ua, 'ipad') => 'iPad',
            str_contains($ua, 'android') => 'Android',
            str_contains($ua, 'macintosh'), str_contains($ua, 'mac os x') => 'macOS',
            str_contains($ua, 'windows') => 'Windows',
            str_contains($ua, 'linux') => 'Linux',
            default => '未知系统',
        };

        $browser = match (true) {
            str_contains($ua, 'edg/') => 'Edge',
            str_contains($ua, 'opr/'), str_contains($ua, 'opera') => 'Opera',
            str_contains($ua, 'firefox') => 'Firefox',
            str_contains($ua, 'chrome'), str_contains($ua, 'chromium') => 'Chrome',
            str_contains($ua, 'safari') => 'Safari',
            str_contains($ua, 'curl') => '命令行',
            default => '其他浏览器',
        };

        return $platform . ' · ' . $browser;
    }
}
