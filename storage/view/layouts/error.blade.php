@extends('layouts.base')

@section('title', $pageTitle ?? '提示')

@section('body')
<div class="flex min-h-screen flex-col items-center justify-center px-6 py-16">
    <div class="w-full max-w-md">
        <div class="mb-8 flex justify-center">
            <a href="/">@include('partials.brand', ['size' => 'md'])</a>
        </div>

        <div class="panel panel-body text-center">
            <p class="text-[44px] font-semibold leading-none tracking-tight text-line-strong">{{ $statusCode ?? '500' }}</p>
            <h1 class="mt-4 text-lg font-semibold text-ink">{{ $heading ?? '出现了一点问题' }}</h1>
            <p class="mt-2 text-sm leading-6 text-muted">{{ $detail ?? '请求未能完成，请稍后再试。' }}</p>

            <div class="mt-7 flex flex-wrap items-center justify-center gap-2">
                <a href="/" class="btn btn-primary btn-sm">返回首页</a>
                @if (! empty($showLogin))
                    <a href="/login" class="btn btn-secondary btn-sm">去登录</a>
                @endif
            </div>
        </div>

        @if (! empty($requestId))
            <p class="mt-5 text-center text-xs text-muted">请求编号：<span class="font-mono text-ink-soft">{{ $requestId }}</span></p>
        @endif
    </div>
</div>
@endsection
