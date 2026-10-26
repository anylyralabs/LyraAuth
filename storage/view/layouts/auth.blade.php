@extends('layouts.base')

@section('title', $pageTitle ?? '登录')

@section('body')
<div class="grid min-h-screen lg:grid-cols-[minmax(0,1fr)_minmax(0,460px)]">

    {{-- 左侧：品牌区，仅大屏显示 --}}
    <aside class="relative hidden overflow-hidden border-r border-line bg-surface lg:flex lg:flex-col lg:justify-center lg:p-12">
        <div class="grid-lines absolute inset-0 opacity-50" aria-hidden="true"></div>
        <div class="absolute left-12 top-12">
            @include('partials.brand', ['size' => 'md', 'showTagline' => true])
        </div>

        <div class="relative max-w-md">
            <h1 class="text-[26px] font-semibold leading-snug tracking-tight text-ink text-balance">
                一个账号，通行全部应用
            </h1>
            <p class="mt-3 text-sm leading-6 text-muted">
                LyraAuth 以 OAuth 2.0 与 OpenID Connect 为核心，为你的内部系统与第三方应用提供统一的登录、授权与单点登出能力。
            </p>

            <ul class="mt-7 space-y-3">
                <li class="flex items-start gap-3">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-brand" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <path d="M3 8.4l3.2 3.2L13 4.8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="text-sm leading-5 text-ink-soft">标准协议，兼容任意 OIDC 客户端</span>
                </li>
                <li class="flex items-start gap-3">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-brand" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <path d="M3 8.4l3.2 3.2L13 4.8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="text-sm leading-5 text-ink-soft">TOTP 双因素与 Passkey 免密登录</span>
                </li>
                <li class="flex items-start gap-3">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-brand" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <path d="M3 8.4l3.2 3.2L13 4.8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="text-sm leading-5 text-ink-soft">细粒度授权与完整审计留痕</span>
                </li>
            </ul>
        </div>

        <p class="absolute bottom-12 left-12 text-xs text-muted">
            &copy; {{ date('Y') }} LyraAuth
        </p>
    </aside>

    {{-- 右侧：表单区 --}}
    <main id="main" class="flex flex-col bg-canvas">
        <div class="flex items-center justify-between px-6 py-5 lg:justify-end">
            <div class="lg:hidden">
                @include('partials.brand', ['size' => 'sm'])
            </div>
            @hasSection('aside-link')
                <div class="text-sm text-muted">
                    @yield('aside-link')
                </div>
            @endif
        </div>

        <div class="flex flex-1 items-center justify-center px-6 pb-16">
            <div class="w-full max-w-[360px]">
                @include('partials.flash', ['flash' => flash(), 'errorBag' => $errorBag ?? null])

                @yield('content')
            </div>
        </div>

        <footer class="px-6 pb-6 text-center text-xs text-muted">
            <span class="lg:hidden">&copy; {{ date('Y') }} LyraAuth</span>
        </footer>
    </main>
</div>
@endsection
