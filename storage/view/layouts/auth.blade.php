@extends('layouts.base')

@section('body')
<div class="grid min-h-screen lg:grid-cols-[minmax(0,1fr)_480px]">

    {{-- 左侧：品牌与说明。深色区域，作为唯一的"重"色块 --}}
    <aside class="relative hidden overflow-hidden bg-ink lg:flex lg:flex-col lg:justify-between lg:px-14 lg:py-12">
        {{-- 细网格，暗示坐标系与仪器刻度 --}}
        <div class="pointer-events-none absolute inset-0 opacity-[0.13]"
             style="background-image: linear-gradient(to right, #f5f5f2 1px, transparent 1px), linear-gradient(to bottom, #f5f5f2 1px, transparent 1px); background-size: 48px 48px;"></div>

        {{-- 右上角的刻度标记 --}}
        <div class="pointer-events-none absolute right-10 top-10 flex items-start gap-1.5" aria-hidden="true">
            @for ($i = 0; $i < 6; $i++)
                <span class="block w-px bg-[#f5f5f2]" style="height: {{ $i % 3 === 0 ? 14 : 7 }}px; opacity: 0.28"></span>
            @endfor
        </div>

        <div class="relative">
            <a href="/" class="inline-flex items-center gap-2.5">
                <svg class="h-8 w-8" viewBox="0 0 32 32" fill="none" aria-hidden="true">
                    <rect x="0.5" y="0.5" width="31" height="31" rx="2.5" fill="#F5F5F2"/>
                    <path d="M10 23V9" stroke="#14171A" stroke-width="2.1" stroke-linecap="square"/>
                    <path d="M10 16.4L16.6 9" stroke="#14171A" stroke-width="2.1" stroke-linecap="square"/>
                    <path d="M10 16.4L17.2 23" stroke="#14171A" stroke-width="2.1" stroke-linecap="square"/>
                    <circle cx="22.6" cy="16" r="3.1" stroke="#96631C" stroke-width="1.7"/>
                </svg>
                <span class="text-[15px] font-semibold tracking-[-0.02em] text-[#F5F5F2]" style="font-family: var(--font-display)">LyraAuth</span>
            </a>
        </div>

        <div class="relative max-w-[420px]">
            <h1 class="text-[30px] font-semibold leading-[1.22] tracking-[-0.03em] text-[#F5F5F2]" style="font-family: var(--font-display)">
                一个账号<br>通行全部应用
            </h1>
            <p class="mt-4 text-[13.5px] leading-[1.7] text-[#9AA0A5]">
                以 OAuth 2.0 与 OpenID Connect 为核心，把登录、授权、双因素与单点登出收敛到一处。
            </p>

            {{-- 三项能力，用刻度线分隔而不是卡片 --}}
            <dl class="mt-9 space-y-0">
                <div class="flex items-baseline gap-4 border-t border-[#2A2E33] py-3.5">
                    <dt class="w-[86px] shrink-0 font-mono text-[10.5px] uppercase tracking-[0.08em] text-[#6E747A]">Protocol</dt>
                    <dd class="text-[13px] text-[#C8C8C0]">授权码 + PKCE · 设备码 · 单点登出</dd>
                </div>
                <div class="flex items-baseline gap-4 border-t border-[#2A2E33] py-3.5">
                    <dt class="w-[86px] shrink-0 font-mono text-[10.5px] uppercase tracking-[0.08em] text-[#6E747A]">Factors</dt>
                    <dd class="text-[13px] text-[#C8C8C0]">密码 · TOTP 验证器 · Passkey</dd>
                </div>
                <div class="flex items-baseline gap-4 border-y border-[#2A2E33] py-3.5">
                    <dt class="w-[86px] shrink-0 font-mono text-[10.5px] uppercase tracking-[0.08em] text-[#6E747A]">Audit</dt>
                    <dd class="text-[13px] text-[#C8C8C0]">登录、授权与配置变更全程留痕</dd>
                </div>
            </dl>
        </div>

        <div class="relative flex items-center justify-between font-mono text-[10.5px] tracking-[0.04em] text-[#6E747A]">
            <span>&copy; {{ date('Y') }} LyraAuth</span>
            <span>OIDC 1.0</span>
        </div>
    </aside>

    {{-- 右侧：表单 --}}
    <main id="main" class="flex flex-col bg-canvas">
        <div class="flex h-14 items-center justify-between border-b border-line px-6 lg:justify-end lg:border-b-0">
            <div class="lg:hidden">
                @include('partials.brand', ['size' => 'sm'])
            </div>
            @hasSection('aside-link')
                <div class="text-[12.5px] text-muted">
                    @yield('aside-link')
                </div>
            @endif
        </div>

        <div class="flex flex-1 items-center justify-center px-6 py-10 lg:px-12">
            <div class="w-full max-w-[340px]">
                @include('partials.flash', ['flash' => flash(), 'errorBag' => $errorBag ?? null])

                @yield('content')
            </div>
        </div>

        <footer class="flex items-center justify-between px-6 pb-6 font-mono text-[10.5px] tracking-[0.04em] text-faint lg:hidden">
            <span>&copy; {{ date('Y') }} LyraAuth</span>
            <span>OIDC 1.0</span>
        </footer>
    </main>
</div>
@endsection
