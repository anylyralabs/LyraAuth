@extends('layouts.auth')

@section('title', $success ? '邮箱已验证' : '验证失败')

@section('content')
<div class="border border-line bg-surface p-6">
    @if ($success)
        <span class="flex h-9 w-9 items-center justify-center border border-success-line bg-success-soft">
            <svg class="h-4 w-4 text-success" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <path d="M3.2 8.4l3.1 3.1L12.8 4.9" stroke="currentColor" stroke-width="1.8" stroke-linecap="square"/>
            </svg>
        </span>
    @else
        <span class="flex h-9 w-9 items-center justify-center border border-danger-line bg-danger-soft">
            <svg class="h-4 w-4 text-danger" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <path d="M8 4.4v4.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="square"/>
                <rect x="7.2" y="11.2" width="1.6" height="1.6" fill="currentColor"/>
            </svg>
        </span>
    @endif

    <h1 class="mt-4 text-[20px] font-semibold tracking-[-0.025em] text-ink">{{ $success ? '验证成功' : '验证未完成' }}</h1>
    <p class="mt-2 text-[13px] leading-[1.7] text-muted">{{ $message }}</p>

    <a href="/login" class="btn btn-primary btn-block mt-6">{{ $success ? '去登录' : '返回登录' }}</a>
</div>
@endsection
