@extends('layouts.base')

@section('title', $heading ?? '提示')

@section('body')
<div class="flex min-h-screen flex-col">
    <header class="flex h-14 items-center justify-between border-b border-line px-5 sm:px-8">
        <a href="/">@include('partials.brand', ['size' => 'sm'])</a>
        <span class="font-mono text-[10.5px] tracking-[0.06em] text-faint">{{ $statusCode ?? '500' }}</span>
    </header>

    <main id="main" class="flex flex-1 items-center justify-center px-5 py-16">
        <div class="w-full max-w-[440px]">
            <p class="font-mono text-[11px] tracking-[0.08em] text-brand">{{ $statusCode ?? '500' }}</p>
            <h1 class="mt-2 text-[24px] font-semibold tracking-[-0.025em] text-ink">{{ $heading ?? '出现了一点问题' }}</h1>
            <p class="mt-3 text-[13.5px] leading-[1.7] text-muted">{{ $detail ?? '请求未能完成，请稍后再试。' }}</p>

            <div class="mt-7 flex flex-wrap items-center gap-2">
                <a href="/" class="btn btn-primary">返回首页</a>
                @if (! empty($showLogin))
                    <a href="/login" class="btn btn-secondary">去登录</a>
                @endif
            </div>

            <p class="mt-10 border-t border-line pt-4 font-mono text-[10.5px] tracking-[0.04em] text-faint">LyraAuth · OIDC 1.0</p>
        </div>
    </main>
</div>
@endsection
