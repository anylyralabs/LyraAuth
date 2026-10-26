@extends('layouts.auth')

@section('title', '登录')

@hasSection('aside-link')
    @section('aside-link')
        <span>还没有账号？<a href="/register" class="font-medium text-brand hover:underline">创建账号</a></span>
    @endsection
@endif

@section('content')
<h1 class="text-[20px] font-semibold tracking-[-0.025em] text-ink">登录</h1>
<p class="mt-1.5 text-[13px] leading-[1.6] text-muted">使用统一账号继续访问已接入的应用。</p>

@if (! empty($notice))
    <div class="alert alert-info mt-5" role="status">{{ $notice }}</div>
@endif

@if (! empty($error))
    <div class="alert alert-error mt-5" role="alert">{{ $error }}</div>
@endif

<form method="POST" action="/login" class="mt-6 space-y-4" novalidate>
    @csrf

    <div>
        <label for="identifier" class="field-label">邮箱或用户名</label>
        <input id="identifier" name="identifier" type="text" value="{{ $identifier ?? '' }}"
               class="field-input" placeholder="you@example.com"
               autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
    </div>

    <div>
        <div class="mb-1.5 flex items-baseline justify-between">
            <label for="password" class="field-label mb-0">密码</label>
            <a href="/password/forgot" class="text-[11.5px] text-muted hover:text-brand hover:underline">忘记密码</a>
        </div>
        <div class="relative">
            <input id="password" name="password" type="password"
                   class="field-input pr-14" placeholder="请输入密码"
                   autocomplete="current-password" required>
            <button type="button"
                    class="absolute inset-y-0 right-0 px-2.5 text-[11.5px] text-muted transition-colors hover:text-ink"
                    data-password-toggle="password" data-label-show="显示密码" data-label-hide="隐藏密码"
                    aria-label="显示密码" aria-pressed="false">显示</button>
        </div>
    </div>

    <label class="flex items-center gap-2 pt-0.5 text-[12.5px] text-ink-soft">
        <input type="checkbox" name="remember" value="1"
               class="h-3.5 w-3.5 rounded-[2px] border-line-strong accent-brand">
        <span>在此设备上记住我</span>
    </label>

    <button type="submit" class="btn btn-primary btn-block !py-2">登录</button>
</form>

<div class="mt-7 border-t border-line pt-5">
    <a href="/device" class="btn btn-secondary btn-block">使用设备码登录</a>
</div>

<p class="mt-7 text-center text-[11.5px] leading-[1.7] text-faint">
    登录即表示你同意本站的
    <a href="/legal/terms" class="text-muted underline decoration-line-strong underline-offset-2 hover:text-brand">服务条款</a>
    与
    <a href="/legal/privacy" class="text-muted underline decoration-line-strong underline-offset-2 hover:text-brand">隐私政策</a>
</p>
@endsection
