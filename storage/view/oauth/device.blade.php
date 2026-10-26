@extends('layouts.auth')

@section('title', '设备登录')

@section('content')
<h1 class="text-[20px] font-semibold tracking-[-0.025em] text-ink">设备登录</h1>
<p class="mt-1.5 text-[13px] leading-[1.65] text-muted">
    在你的电视、命令行或其它设备上会显示一组代码，在下方输入即可完成登录授权。
</p>

@if (! empty($error))
    <div class="alert alert-error mt-5" role="alert">{{ $error }}</div>
@endif

<form method="POST" action="/device" class="mt-6 space-y-4" novalidate>
    @csrf
    <div>
        <label for="user_code" class="field-label">设备代码</label>
        <input id="user_code" name="user_code" type="text" value="{{ $prefill ?? '' }}"
               class="field-input text-center font-mono !text-[17px] tracking-[0.25em] uppercase"
               placeholder="XXXX-XXXX" autocomplete="off" autocapitalize="characters"
               maxlength="16" required autofocus>
        <p class="field-hint">代码不区分大小写，通常形如 ABCD-EFGH。</p>
    </div>

    <button type="submit" class="btn btn-primary btn-block !py-2">继续</button>
</form>

<div class="mt-6 text-center">
    <a href="/dashboard" class="text-[11.5px] text-muted transition-colors hover:text-ink">返回控制台</a>
</div>
@endsection
