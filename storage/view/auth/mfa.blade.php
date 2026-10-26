@extends('layouts.auth')

@section('title', '两步验证')

@section('content')
<h1 class="text-[20px] font-semibold tracking-[-0.025em] text-ink">两步验证</h1>
<p class="mt-1.5 text-[13px] leading-[1.65] text-muted">
    账号 <span class="font-mono text-[12.5px] text-ink-soft">{{ $email }}</span> 已启用双重验证，请再完成一步。
</p>

@if (! empty($error))
    <div class="alert alert-error mt-5" role="alert">{{ $error }}</div>
@endif

@if ($method === 'totp')
    <form method="POST" action="/mfa/totp" class="mt-6 space-y-4" novalidate>
        @csrf
        <div>
            <label for="code" class="field-label">验证器验证码</label>
            <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                   class="field-input text-center font-mono !text-[18px] tracking-[0.35em]"
                   placeholder="000000" autocomplete="one-time-code" required autofocus>
            <p class="field-hint">打开验证器 App，输入当前显示的 6 位数字。</p>
        </div>

        <label class="flex items-center gap-2 text-[12.5px] text-ink-soft">
            <input type="checkbox" name="remember_device" value="1"
                   class="h-3.5 w-3.5 rounded-[2px] border-line-strong accent-brand">
            <span>在此设备上不再询问（30 天）</span>
        </label>

        <button type="submit" class="btn btn-primary btn-block !py-2">验证并登录</button>
    </form>

    <details class="mt-5 border-t border-line pt-4">
        <summary class="cursor-pointer text-[12px] text-muted transition-colors hover:text-ink">无法使用验证器？使用恢复码</summary>
        <form method="POST" action="/mfa/recovery" class="mt-3 space-y-3">
            @csrf
            <div>
                <label for="recovery-code" class="field-label">恢复码</label>
                <input id="recovery-code" name="code" type="text" class="field-input font-mono uppercase"
                       placeholder="XXXX-XXXX" autocomplete="off" required>
            </div>
            <label class="flex items-center gap-2 text-[12.5px] text-ink-soft">
                <input type="checkbox" name="remember_device" value="1"
                       class="h-3.5 w-3.5 rounded-[2px] border-line-strong accent-brand">
                <span>在此设备上不再询问（30 天）</span>
            </label>
            <button type="submit" class="btn btn-secondary btn-block">使用恢复码登录</button>
        </form>
    </details>
@else
    <form method="POST" action="/mfa/recovery" class="mt-6 space-y-4" novalidate>
        @csrf
        <div>
            <label for="code" class="field-label">恢复码</label>
            <input id="code" name="code" type="text" class="field-input font-mono uppercase"
                   placeholder="XXXX-XXXX" autocomplete="off" required autofocus>
            <p class="field-hint">输入此前保存的任一恢复码，每个只能使用一次。</p>
        </div>

        <label class="flex items-center gap-2 text-[12.5px] text-ink-soft">
            <input type="checkbox" name="remember_device" value="1"
                   class="h-3.5 w-3.5 rounded-[2px] border-line-strong accent-brand">
            <span>在此设备上不再询问（30 天）</span>
        </label>

        <button type="submit" class="btn btn-primary btn-block !py-2">验证并登录</button>
    </form>
@endif

@if ($hasPasskey)
    <div class="mt-6 border-t border-line pt-5">
        <button type="button" class="btn btn-secondary btn-block" data-passkey-login>使用 Passkey 验证</button>
        <p class="mt-2 hidden text-center text-[11.5px] text-muted" data-passkey-status></p>
    </div>
@endif

<div class="mt-6 text-center">
    <a href="/login" class="text-[11.5px] text-muted transition-colors hover:text-ink">返回登录页</a>
</div>
@endsection
