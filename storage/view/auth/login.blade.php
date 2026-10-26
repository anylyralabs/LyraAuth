@extends('layouts.auth')

@section('title', '登录')

@hasSection('aside-link')
    @section('aside-link')
        <span>还没有账号？<a href="/register" class="font-medium text-brand hover:underline">创建账号</a></span>
    @endsection
@endif

@section('content')
<div class="mb-7">
    <h1 class="text-[22px] font-semibold tracking-tight text-ink">登录 LyraAuth</h1>
    <p class="mt-1.5 text-sm leading-6 text-muted">使用你的统一账号继续访问已接入的应用。</p>
</div>

@if (! empty($notice))
    <div class="alert alert-info mb-5" role="status">{{ $notice }}</div>
@endif

@if (! empty($error))
    <div class="alert alert-error mb-5" role="alert">
        <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.4"/>
            <path d="M8 4.8v4.4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
            <circle cx="8" cy="11.6" r="0.8" fill="currentColor"/>
        </svg>
        <span>{{ $error }}</span>
    </div>
@endif

<form method="POST" action="/login" class="space-y-4" novalidate>
    @csrf

    <div>
        <label for="identifier" class="field-label">邮箱或用户名</label>
        <input id="identifier" name="identifier" type="text" value="{{ $identifier ?? '' }}"
               class="field-input" placeholder="you@example.com"
               autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
    </div>

    <div>
        <div class="flex items-baseline justify-between">
            <label for="password" class="field-label">密码</label>
            <a href="/password/forgot" class="text-xs text-muted hover:text-brand hover:underline">忘记密码？</a>
        </div>
        <div class="relative">
            <input id="password" name="password" type="password"
                   class="field-input pr-16" placeholder="请输入密码"
                   autocomplete="current-password" required>
            <button type="button"
                    class="absolute inset-y-0 right-0 px-3 text-xs font-medium text-muted hover:text-ink"
                    data-password-toggle="password" data-label-show="显示密码" data-label-hide="隐藏密码"
                    aria-label="显示密码" aria-pressed="false">显示</button>
        </div>
    </div>

    <label class="flex items-center gap-2 pt-0.5 text-sm text-ink-soft">
        <input type="checkbox" name="remember" value="1"
               class="h-3.5 w-3.5 rounded border-line-strong text-brand focus:ring-brand">
        <span>在此设备上记住我</span>
    </label>

    <button type="submit" class="btn btn-primary btn-block pt-2.5 pb-2.5">登录</button>
</form>

<div class="mt-6 flex items-center gap-3" aria-hidden="true">
    <span class="h-px flex-1 bg-line"></span>
    <span class="text-[11px] uppercase tracking-wider text-muted">或</span>
    <span class="h-px flex-1 bg-line"></span>
</div>

<a href="/device" class="btn btn-secondary btn-block mt-4">使用设备码登录</a>

<p class="mt-6 text-center text-xs leading-5 text-muted">
    登录即表示你同意本站的
    <a href="/legal/terms" class="text-ink-soft underline decoration-line-strong underline-offset-2 hover:text-brand">服务条款</a>
    与
    <a href="/legal/privacy" class="text-ink-soft underline decoration-line-strong underline-offset-2 hover:text-brand">隐私政策</a>。
</p>
@endsection
