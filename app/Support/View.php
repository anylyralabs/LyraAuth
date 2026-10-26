<?php

declare(strict_types=1);

namespace App\Support;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Stringable\Str;

/**
 * 模板与控制器共用的视图辅助函数。
 *
 * view-engine 是 Blade 子集，未提供 asset() / csrf_field() 等 Laravel 辅助函数，
 * 这里集中实现，并在 ViewServiceProvider 中注册为全局函数。
 */
final class View
{
    /** 带版本号的静态资源地址，避免浏览器缓存旧文件 */
    public static function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = BASE_PATH . '/public/assets/' . $path;
        $version = is_file($file) ? (string) filemtime($file) : '1';

        return '/assets/' . $path . '?v=' . $version;
    }

    public static function url(string $path = '/'): string
    {
        $base = rtrim((string) self::config()->get('app_url', ''), '/');
        $path = '/' . ltrim($path, '/');

        return $base . $path;
    }

    /** 站内相对地址，用于重定向，杜绝开放重定向 */
    public static function safeRedirect(?string $target, string $fallback = '/dashboard'): string
    {
        $target = trim((string) $target);
        if ($target === '' || str_contains($target, "\n") || str_contains($target, "\r")) {
            return $fallback;
        }
        // 仅允许单个前导斜线，拒绝 //host、/\host、带协议或 scheme 的形式
        if (! str_starts_with($target, '/') || str_starts_with($target, '//') || str_starts_with($target, '/\\')) {
            return $fallback;
        }
        if (preg_match('#^/[a-z][a-z0-9+.\-]*:#i', $target) === 1) {
            return $fallback;
        }

        return $target;
    }

    public static function currentRequest(): ?RequestInterface
    {
        try {
            return ApplicationContext::getContainer()->get(RequestInterface::class);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function config(): ConfigInterface
    {
        return ApplicationContext::getContainer()->get(ConfigInterface::class);
    }

    /** 简单的相对时间输出，避免引入额外依赖 */
    public static function relativeTime(?int $timestamp): string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return '—';
        }
        $diff = time() - $timestamp;
        if ($diff < 60) {
            return '刚刚';
        }
        if ($diff < 3600) {
            return intdiv($diff, 60) . ' 分钟前';
        }
        if ($diff < 86400) {
            return intdiv($diff, 3600) . ' 小时前';
        }
        if ($diff < 2592000) {
            return intdiv($diff, 86400) . ' 天前';
        }

        return date('Y-m-d', $timestamp);
    }

    public static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') {
            return $email;
        }
        $visible = Str::substr($name, 0, 1);
        $tail = Str::substr($name, -1);

        return $visible . str_repeat('*', max(2, Str::length($name) - 2)) . $tail . '@' . $domain;
    }
}
