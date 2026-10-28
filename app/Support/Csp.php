<?php

declare(strict_types=1);

namespace App\Support;

use App\Model\OauthClient;
use Hyperf\Context\Context;

/**
 * 内容安全策略（CSP）的请求级扩展。
 *
 * 背景：CSP 的 `form-action` 不只校验表单的 action 属性，浏览器还会校验
 * **整条重定向链的终点**。LyraAuth 的登录与同意表单提交后最终要跳回客户端的
 * redirect_uri，那是跨源地址，因此会被 `form-action 'self'` 拦截：
 *
 *   POST /login → 302 /oauth/authorize → 302 https://rp.example.com/cb   ← 被拦截
 *
 * 表现是「点了登录没反应、地址不变」，但服务端其实已经登录成功（会话值已轮换），
 * 只有浏览器把这次导航丢弃了。
 *
 * 解决方式不是放宽成 `form-action *`，而是只把当前流程涉及的那个客户端的
 * 回调源（含 post_logout_redirect_uri）精确加进白名单。白名单随请求存于协程
 * 上下文，由 SecurityHeaderMiddleware 汇总输出。
 */
final class Csp
{
    private const CONTEXT_KEY = 'csp.form_action_origins';

    /**
     * 允许这些地址所在的源作为表单提交（含重定向链）的终点。
     *
     * 只接受绝对 http/https 地址；非法值静默忽略，避免把垃圾内容写进响应头。
     */
    public static function allowFormAction(string|array|null ...$urls): void
    {
        $origins = self::current();

        foreach (self::flatten($urls) as $url) {
            $origin = self::originOf($url);
            if ($origin !== null) {
                $origins[$origin] = true;
            }
        }

        Context::set(self::CONTEXT_KEY, $origins);
    }

    /** 允许某个客户端注册的全部回调地址（含登出后回跳） */
    public static function allowFormActionForClient(?OauthClient $client): void
    {
        if ($client === null) {
            return;
        }

        self::allowFormAction(
            (array) $client->redirect_uris,
            (array) ($client->post_logout_redirect_uris ?? []),
        );
    }

    /**
     * 从 /oauth/authorize、/oauth/logout 这类地址中解析 client_id 并登记其回调源。
     *
     * 只认本站授权/登出端点，避免任意 URL 扩大白名单。
     */
    public static function allowForAuthorizationUrl(?string $url): void
    {
        if ($url === null || $url === '') {
            return;
        }

        $parts = parse_url($url);
        $path = $parts['path'] ?? '';
        if ($path !== '/oauth/authorize' && $path !== '/oauth/logout') {
            return;
        }

        $query = $parts['query'] ?? '';
        if ($query === '') {
            return;
        }

        parse_str($query, $params);
        $clientId = $params['client_id'] ?? null;
        if (! is_string($clientId) || $clientId === '') {
            return;
        }

        self::allowFormActionForClient(
            OauthClient::query()->where('client_id', $clientId)->where('is_active', true)->first()
        );
    }

    /** @return list<string> 供 Content-Security-Policy 使用的 form-action 取值 */
    public static function formActionSources(): array
    {
        return array_merge(["'self'"], array_keys(self::current()));
    }

    /** 清理上下文，避免常驻内存下串到下一个请求 */
    public static function reset(): void
    {
        Context::destroy(self::CONTEXT_KEY);
    }

    /** @return array<string, true> */
    private static function current(): array
    {
        $value = Context::get(self::CONTEXT_KEY, []);

        return is_array($value) ? $value : [];
    }

    /** @return list<string> */
    private static function flatten(array $values): array
    {
        $flat = [];
        foreach ($values as $value) {
            if (is_array($value)) {
                $flat = array_merge($flat, self::flatten($value));
            } elseif (is_string($value) && $value !== '') {
                $flat[] = $value;
            }
        }

        return $flat;
    }

    /** 取 scheme://host[:port]；不接受相对地址与非法端口 */
    private static function originOf(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = strtolower($parts['host']);
        if ($host === '') {
            return null;
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }
}
