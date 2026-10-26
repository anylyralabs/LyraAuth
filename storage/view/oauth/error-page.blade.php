@extends('layouts.base')

@section('title', $pageTitle ?? '授权失败')

@section('body')
<div class="flex min-h-screen flex-col items-center justify-center px-6 py-16">
    <div class="w-full max-w-md">
        <div class="mb-8 flex justify-center">
            <a href="/">@include('partials.brand', ['size' => 'md'])</a>
        </div>

        <div class="panel panel-body text-center">
            <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-danger-soft">
                <svg class="h-5 w-5 text-danger" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                    <circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.5"/>
                    <path d="M8 4.8v4.4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    <circle cx="8" cy="11.6" r="0.8" fill="currentColor"/>
                </svg>
            </span>

            <h1 class="mt-4 text-lg font-semibold text-ink">{{ $heading ?? '无法完成授权' }}</h1>
            <p class="mt-2 text-sm leading-6 text-muted">{{ $detail ?? '请求未能完成，请稍后再试。' }}</p>

            <div class="mt-6 flex flex-col gap-2">
                <a href="/dashboard" class="btn btn-secondary btn-block">返回控制台</a>
            </div>
        </div>
    </div>
</div>
@endsection
