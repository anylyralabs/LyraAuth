@extends('layouts.auth')

@section('title', $success ? '邮箱已验证' : '验证失败')

@section('content')
<div class="panel panel-body text-center">
    @if ($success)
        <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-success-soft">
            <svg class="h-5 w-5 text-success" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <path d="M3 8.4l3.2 3.2L13 4.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </span>
    @else
        <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-danger-soft">
            <svg class="h-5 w-5 text-danger" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.5"/>
                <path d="M8 4.8v4.4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                <circle cx="8" cy="11.6" r="0.8" fill="currentColor"/>
            </svg>
        </span>
    @endif

    <h1 class="mt-4 text-lg font-semibold text-ink">{{ $success ? '验证成功' : '验证未完成' }}</h1>
    <p class="mt-2 text-sm leading-6 text-muted">{{ $message }}</p>

    <a href="/login" class="btn btn-primary btn-block mt-6">{{ $success ? '去登录' : '返回登录' }}</a>
</div>
@endsection
