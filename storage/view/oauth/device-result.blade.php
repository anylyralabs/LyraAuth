@extends('layouts.auth')

@section('title', $approved ? '已批准登录' : '已拒绝登录')

@section('content')
<div class="panel p-6">
    @if ($approved)
        <span class="flex h-9 w-9 items-center justify-center border border-success-line bg-success-soft">
            <svg class="h-4 w-4 text-success" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <path d="M3.2 8.4l3.1 3.1L12.8 4.9" stroke="currentColor" stroke-width="1.8" stroke-linecap="square"/>
            </svg>
        </span>
        <h1 class="mt-4 text-[20px] font-semibold tracking-[-0.025em] text-ink">设备已获授权</h1>
        <p class="mt-2 text-[13px] leading-[1.7] text-muted">请回到你的设备，它会自动完成登录。现在可以关闭此页面。</p>
    @else
        <span class="flex h-9 w-9 items-center justify-center border border-danger-line bg-danger-soft">
            <svg class="h-4 w-4 text-danger" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <path d="M4.6 4.6l6.8 6.8M11.4 4.6l-6.8 6.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="square"/>
            </svg>
        </span>
        <h1 class="mt-4 text-[20px] font-semibold tracking-[-0.025em] text-ink">已拒绝登录</h1>
        <p class="mt-2 text-[13px] leading-[1.7] text-muted">该设备不会获得访问权限。如果这是误操作，可以重新输入代码。</p>
    @endif

    <a href="/dashboard" class="btn btn-secondary btn-block mt-6">返回控制台</a>
</div>
@endsection
