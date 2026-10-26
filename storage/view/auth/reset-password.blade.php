@extends('layouts.auth')

@section('title', '重置密码')

@section('content')
<div class="mb-7">
    <h1 class="text-[22px] font-semibold tracking-tight text-ink">设置新密码</h1>
    <p class="mt-1.5 text-sm leading-6 text-muted">设置完成后，该账号此前登录的所有设备都会退出登录。</p>
</div>

@if (! $valid)
    <div class="alert alert-error mb-5" role="alert">
        <span>重置链接无效或已过期。请重新发起找回密码请求。</span>
    </div>
    <a href="/password/forgot" class="btn btn-primary btn-block">重新发送邮件</a>
@else
    <form method="POST" action="/password/reset/{{ $token }}" class="space-y-4" novalidate>
        @csrf
        <div>
            <label for="password" class="field-label">新密码</label>
            <div class="relative">
                <input id="password" name="password" type="password"
                       class="field-input pr-16 {{ isset($errors['password']) ? 'border-danger' : '' }}"
                       placeholder="至少 10 位，含字母与数字" autocomplete="new-password" required autofocus>
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
            <label for="password_confirmation" class="field-label">确认新密码</label>
            <input id="password_confirmation" name="password_confirmation" type="password"
                   class="field-input {{ isset($errors['password_confirmation']) ? 'border-danger' : '' }}"
                   placeholder="再次输入新密码" autocomplete="new-password" required>
            @if (! empty($errors['password_confirmation']))
                <p class="field-error">{{ $errors['password_confirmation'] }}</p>
            @endif
        </div>

        <button type="submit" class="btn btn-primary btn-block">保存新密码</button>
    </form>
@endif
@endsection
