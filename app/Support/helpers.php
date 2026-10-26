<?php

declare(strict_types=1);
/**
 * 视图全局辅助函数。
 *
 * 在 Composer autoload.files 中加载，供 Blade 模板直接调用。
 */
if (! function_exists('asset')) {
    /**
     * 生成带缓存版本号的静态资源路径。
     */
    function asset(string $path): string
    {
        return \App\Support\View::asset($path);
    }
}

if (! function_exists('app_url')) {
    function app_url(string $path = '/'): string
    {
        return \App\Support\View::url($path);
    }
}

if (! function_exists('csrf_token')) {
    /**
     * 取当前会话的 CSRF 令牌，必要时生成。
     *
     * 会话由 SessionMiddleware 在请求进入时开启；命令行等无会话场景返回空串。
     */
    function csrf_token(): string
    {
        try {
            $session = \Hyperf\Context\ApplicationContext::getContainer()
                ->get(\Hyperf\Contract\SessionInterface::class);
        } catch (\Throwable) {
            return '';
        }

        if (! $session->isStarted()) {
            return '';
        }

        $token = $session->get('_csrf_token');
        if (! is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $session->set('_csrf_token', $token);
        }

        return $token;
    }
}

if (! function_exists('csrf_field')) {
    /**
     * 输出隐藏的 CSRF 字段。
     */
    function csrf_field(): string
    {
        $token = csrf_token();
        if ($token === '') {
            return '';
        }

        return '<input type="hidden" name="_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (! function_exists('flash')) {
    /**
     * 读取一次性提示消息，读取后清除。
     *
     * @return array{type?: string, message?: string}|null
     */
    function flash(string $key = 'flash'): ?array
    {
        try {
            $session = \Hyperf\Context\ApplicationContext::getContainer()
                ->get(\Hyperf\Contract\SessionInterface::class);
        } catch (\Throwable) {
            return null;
        }

        $value = $session->get($key);
        if (! is_array($value) || $value === []) {
            return null;
        }
        $session->forget($key);

        return $value;
    }
}

if (! function_exists('old')) {
    /**
     * 取上一次表单提交被回填的值。
     */
    function old(string $key, mixed $default = ''): mixed
    {
        try {
            $session = \Hyperf\Context\ApplicationContext::getContainer()
                ->get(\Hyperf\Contract\SessionInterface::class);
        } catch (\Throwable) {
            return $default;
        }

        $values = $session->get('_old_input');
        if (! is_array($values)) {
            return $default;
        }

        return $values[$key] ?? $default;
    }
}

if (! function_exists('error_for')) {
    /**
     * 取某个字段的校验错误信息。
     */
    function error_for(string $key): ?string
    {
        try {
            $session = \Hyperf\Context\ApplicationContext::getContainer()
                ->get(\Hyperf\Contract\SessionInterface::class);
        } catch (\Throwable) {
            return null;
        }

        $errors = $session->get('_errors');
        if (! is_array($errors)) {
            return null;
        }

        $value = $errors[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}

if (! function_exists('__text')) {
    /**
     * 轻量翻译包装：未配置语言包时回退到原文，保证模板始终可渲染。
     */
    function __text(string $key, array $replace = []): string
    {
        try {
            $translator = \Hyperf\Context\ApplicationContext::getContainer()
                ->get(\Hyperf\Contract\TranslatorInterface::class);
            $result = $translator->trans($key, $replace);
            if (is_string($result) && $result !== '' && $result !== $key) {
                return $result;
            }
        } catch (\Throwable) {
            // 翻译组件异常时忽略，使用原文
        }

        $text = $key;
        foreach ($replace as $name => $value) {
            $text = str_replace([':' . $name, '{' . $name . '}'], (string) $value, $text);
        }

        return $text;
    }
}
