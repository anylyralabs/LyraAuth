@extends('layouts.auth')

@section('title', '设备登录')

@section('content')
<div class="mb-7">
    <h1 class="text-[22px] font-semibold tracking-tight text-ink">设备登录</h1>
    <p class="mt-1.5 text-sm leading-6 text-muted">
        在你的电视、命令行或其它设备上会显示一组代码，在下方输入即可完成登录授权。
    </p>
</div>

@if (! empty($error))
    <div class="alert alert-error mb-5" role="alert">{{ $error }}</div>
@endif

<form method="POST" action="/device" class="space-y-4" novalidate>
    @csrf
    <div>
        <label for="user_code" class="field-label">设备代码</label>
        <input id="user_code" name="user_code" type="text" value="{{ $prefill ?? '' }}"
               class="field-input text-center font-mono text-lg tracking-[0.3em] uppercase"
               placeholder="XXXX-XXXX" autocomplete="off" autocapitalize="characters"
               maxlength="16" required autofocus>
        <p class="field-hint">代码不区分大小写，通常形如 ABCD-EFGH。</p>
    </div>

    <button type="submit" class="btn btn-primary btn-block">继续</button>
</form>

<div class="mt-6 text-center">
    <a href="/dashboard" class="text-xs text-muted hover:text-ink">返回控制台</a>
</div>
@endsection
