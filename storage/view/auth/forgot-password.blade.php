@extends('layouts.auth')

@section('title', '找回密码')

@hasSection('aside-link')
    @section('aside-link')
        <span>想起密码了？<a href="/login" class="font-medium text-brand hover:underline">返回登录</a></span>
    @endsection
@endif

@section('content')
<h1 class="text-[20px] font-semibold tracking-[-0.025em] text-ink">找回密码</h1>
<p class="mt-1.5 text-[13px] leading-[1.65] text-muted">
    输入注册邮箱，我们会发送一封包含重置链接的邮件。为保护账号安全，无论邮箱是否存在，提示信息都相同。
</p>

@if (! empty($error))
    <div class="alert alert-error mt-5" role="alert">{{ $error }}</div>
@endif

@if (! empty($sent))
    <div class="alert alert-success mt-5" role="status">
        <span>如果该邮箱对应一个有效账号，重置邮件已经发出，链接 1 小时内有效。</span>
    </div>
    <a href="/login" class="btn btn-secondary btn-block mt-5">返回登录</a>
@else
    <form method="POST" action="/password/forgot" class="mt-6 space-y-4" novalidate>
        @csrf
        <div>
            <label for="email" class="field-label">注册邮箱</label>
            <input id="email" name="email" type="email" value="{{ $email ?? '' }}" class="field-input"
                   placeholder="you@example.com" autocomplete="email" autocapitalize="none" spellcheck="false" required autofocus>
        </div>
        <button type="submit" class="btn btn-primary btn-block !py-2">发送重置邮件</button>
    </form>
@endif
@endsection
