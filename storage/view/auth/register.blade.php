@extends('layouts.auth')

@section('title', '创建账号')

@hasSection('aside-link')
    @section('aside-link')
        <span>已有账号？<a href="/login" class="font-medium text-brand hover:underline">去登录</a></span>
    @endsection
@endif

@section('content')
<div class="mb-7">
    <h1 class="text-[22px] font-semibold tracking-tight text-ink">创建 LyraAuth 账号</h1>
    <p class="mt-1.5 text-sm leading-6 text-muted">一个账号即可访问所有已接入的单点登录应用。</p>
</div>

@if (! empty($closed))
    <div class="alert alert-warning mb-5" role="alert">
        <span>当前站点未开放自助注册，请联系管理员开通账号。</span>
    </div>
@else
    <form method="POST" action="/register" class="space-y-4" novalidate>
        @csrf

        <div>
            <label for="email" class="field-label">邮箱</label>
            <input id="email" name="email" type="email" value="{{ $values['email'] ?? '' }}"
                   class="field-input {{ isset($errors['email']) ? 'border-danger' : '' }}"
                   placeholder="you@example.com" autocomplete="email" autocapitalize="none" spellcheck="false" required>
            @if (! empty($errors['email']))
                <p class="field-error">{{ $errors['email'] }}</p>
            @endif
        </div>

        <div>
            <label for="username" class="field-label">用户名</label>
            <input id="username" name="username" type="text" value="{{ $values['username'] ?? '' }}"
                   class="field-input {{ isset($errors['username']) ? 'border-danger' : '' }}"
                   placeholder="例如 zhangsan" autocomplete="username" autocapitalize="none" spellcheck="false" required>
            @if (! empty($errors['username']))
                <p class="field-error">{{ $errors['username'] }}</p>
            @else
                <p class="field-hint">用于登录与提及，创建后不建议频繁修改。</p>
            @endif
        </div>

        <div>
            <label for="display_name" class="field-label">显示名称</label>
            <input id="display_name" name="display_name" type="text" value="{{ $values['display_name'] ?? '' }}"
                   class="field-input {{ isset($errors['display_name']) ? 'border-danger' : '' }}"
                   placeholder="你的名字" autocomplete="name" required>
            @if (! empty($errors['display_name']))
                <p class="field-error">{{ $errors['display_name'] }}</p>
            @endif
        </div>

        <div>
            <label for="password" class="field-label">密码</label>
            <div class="relative">
                <input id="password" name="password" type="password"
                       class="field-input pr-16 {{ isset($errors['password']) ? 'border-danger' : '' }}"
                       placeholder="至少 10 位，含字母与数字" autocomplete="new-password" required>
                <button type="button"
                        class="absolute inset-y-0 right-0 px-3 text-xs font-medium text-muted hover:text-ink"
                        data-password-toggle="password" data-label-show="显示密码" data-label-hide="隐藏密码"
                        aria-label="显示密码" aria-pressed="false">显示</button>
            </div>
            @if (! empty($errors['password']))
                <p class="field-error">{{ $errors['password'] }}</p>
            @else
                <p class="field-hint">至少 10 位，需同时包含字母与数字。</p>
            @endif
        </div>

        <div>
            <label for="password_confirmation" class="field-label">确认密码</label>
            <input id="password_confirmation" name="password_confirmation" type="password"
                   class="field-input {{ isset($errors['password_confirmation']) ? 'border-danger' : '' }}"
                   placeholder="再次输入密码" autocomplete="new-password" required>
            @if (! empty($errors['password_confirmation']))
                <p class="field-error">{{ $errors['password_confirmation'] }}</p>
            @endif
        </div>

        <button type="submit" class="btn btn-primary btn-block">创建账号</button>
    </form>
@endif

<p class="mt-6 text-center text-xs leading-5 text-muted">
    注册即表示你同意本站的
    <a href="/legal/terms" class="text-ink-soft underline decoration-line-strong underline-offset-2 hover:text-brand">服务条款</a>
    与
    <a href="/legal/privacy" class="text-ink-soft underline decoration-line-strong underline-offset-2 hover:text-brand">隐私政策</a>。
</p>
@endsection
