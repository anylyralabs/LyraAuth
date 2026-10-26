@extends('layouts.auth')

@section('title', $approved ? '已批准登录' : '已拒绝登录')

@section('content')
<div class="panel panel-body text-center">
    @if ($approved)
        <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-success-soft">
            <svg class="h-5 w-5 text-success" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <path d="M3 8.4l3.2 3.2L13 4.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </span>
        <h1 class="mt-4 text-lg font-semibold text-ink">设备已获授权</h1>
        <p class="mt-2 text-sm leading-6 text-muted">请回到你的设备，它会自动完成登录。现在可以关闭此页面。</p>
    @else
        <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-danger-soft">
            <svg class="h-5 w-5 text-danger" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <path d="M5 5l6 6M11 5l-6 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
            </svg>
        </span>
        <h1 class="mt-4 text-lg font-semibold text-ink">已拒绝登录</h1>
        <p class="mt-2 text-sm leading-6 text-muted">该设备不会获得访问权限。如果这是误操作，可以重新输入代码。</p>
    @endif

    <a href="/dashboard" class="btn btn-secondary btn-block mt-6">返回控制台</a>
</div>
@endsection
