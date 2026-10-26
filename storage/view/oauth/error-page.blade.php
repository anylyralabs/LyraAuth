@extends('layouts.base')

@section('title', $pageTitle ?? '授权失败')

@section('body')
<div class="flex min-h-screen flex-col">
    <header class="flex h-14 items-center justify-between border-b border-line px-5 sm:px-8">
        <a href="/">@include('partials.brand', ['size' => 'sm'])</a>
        <span class="font-mono text-[10.5px] tracking-[0.06em] text-faint">AUTHORIZATION</span>
    </header>

    <main id="main" class="flex flex-1 items-center justify-center px-5 py-16">
        <div class="w-full max-w-[440px]">
            <p class="font-mono text-[11px] tracking-[0.08em] text-danger">无法完成</p>
            <h1 class="mt-2 text-[22px] font-semibold tracking-[-0.025em] text-ink">{{ $heading ?? '无法完成授权' }}</h1>
            <p class="mt-3 text-[13px] leading-[1.7] text-muted">{{ $detail ?? '请求未能完成，请稍后再试。' }}</p>

            <a href="/dashboard" class="btn btn-secondary mt-7">返回控制台</a>
        </div>
    </main>
</div>
@endsection
