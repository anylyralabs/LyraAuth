@extends('layouts.auth')

@section('title', '退出登录')

@section('content')
<h1 class="text-[20px] font-semibold tracking-[-0.025em] text-ink">退出登录</h1>
<p class="mt-1.5 text-[13px] leading-[1.65] text-muted">
    @if ($hasClient)
        {{ $clientName }} 请求结束你在 LyraAuth 的登录状态。退出后，该应用以及其他已接入应用都需要重新登录。
    @else
        退出后，所有已接入应用都需要重新登录。
    @endif
</p>

<form method="POST" action="/oauth/logout" class="mt-6">
    @csrf
    <input type="hidden" name="client_id" value="{{ $clientId ?? '' }}">
    <input type="hidden" name="post_logout_redirect_uri" value="{{ $redirect }}">
    <input type="hidden" name="state" value="{{ $state }}">
    <button type="submit" class="btn btn-primary btn-block !py-2">确认退出</button>
</form>

<a href="/dashboard" class="btn btn-ghost btn-block mt-2">取消</a>
@endsection
