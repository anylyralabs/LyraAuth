@extends('layouts.base')

@section('title', '统一身份认证平台')
@section('description', 'LyraAuth 为你的应用提供 OAuth 2.0 与 OpenID Connect 标准的统一登录、授权与单点登出能力。')

@section('body')
<div class="min-h-screen">
    <header class="border-b border-line bg-surface">
        <div class="mx-auto flex h-16 w-full max-w-6xl items-center justify-between px-5 sm:px-8">
            <a href="/">@include('partials.brand', ['size' => 'sm', 'showTagline' => true])</a>
            <div class="flex items-center gap-2">
                <a href="/login" class="btn btn-ghost btn-sm">登录</a>
                <a href="/register" class="btn btn-primary btn-sm">创建账号</a>
            </div>
        </div>
    </header>

    <main id="main">
        <section class="relative overflow-hidden border-b border-line bg-surface">
            <div class="grid-lines absolute inset-0 opacity-50" aria-hidden="true"></div>
            <div class="relative mx-auto w-full max-w-6xl px-5 py-12 sm:px-8 sm:py-16 lg:py-28">
                <div class="max-w-2xl">
                    <p class="inline-flex items-center gap-2 rounded-full border border-brand-line bg-brand-soft px-3 py-1 text-xs font-medium text-brand">
                        基于 OAuth 2.0 · OpenID Connect
                    </p>
                    <h1 class="mt-5 text-[30px] font-semibold leading-[1.25] tracking-tight text-ink text-balance sm:text-[40px] sm:leading-[1.2] lg:text-[44px]">
                        为多套系统，提供一套可信的身份入口
                    </h1>
                    <p class="mt-5 max-w-xl text-sm leading-7 text-muted sm:text-[15px]">
                        LyraAuth 将登录、授权、双因素与单点登出收敛到同一处。应用只需接入标准协议，剩下的交给平台。
                    </p>
                    <div class="mt-8 flex flex-wrap items-center gap-3">
                        <a href="/login" class="btn btn-primary">开始使用</a>
                        <a href="/.well-known/openid-configuration" class="btn btn-secondary">查看发现文档</a>
                    </div>
                </div>
            </div>
        </section>

        <section class="mx-auto w-full max-w-6xl px-5 py-10 sm:px-8 sm:py-16">
            <div class="grid gap-px overflow-hidden rounded-[10px] border border-line bg-line sm:grid-cols-2 lg:grid-cols-4">
                <div class="bg-surface p-5 sm:p-6">
                    <p class="text-sm font-medium text-ink">标准协议</p>
                    <p class="mt-2 text-[13px] leading-6 text-muted">授权码 + PKCE、客户端凭证、设备码与刷新令牌轮换，OIDC 发现与 JWKS 开箱可用。</p>
                </div>
                <div class="bg-surface p-5 sm:p-6">
                    <p class="text-sm font-medium text-ink">单点登录</p>
                    <p class="mt-2 text-[13px] leading-6 text-muted">一次登录贯穿全部接入应用；支持静默授权、强制重认证与会话时长控制。</p>
                </div>
                <div class="bg-surface p-5 sm:p-6">
                    <p class="text-sm font-medium text-ink">多重验证</p>
                    <p class="mt-2 text-[13px] leading-6 text-muted">TOTP 验证器与 Passkey 免密登录并行，恢复码防止设备丢失后失联。</p>
                </div>
                <div class="bg-surface p-5 sm:p-6">
                    <p class="text-sm font-medium text-ink">可审计</p>
                    <p class="mt-2 text-[13px] leading-6 text-muted">登录、授权、令牌签发与配置变更均留有记录，便于追溯与合规检查。</p>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-line bg-surface">
        <div class="mx-auto flex w-full max-w-6xl flex-col gap-2 px-5 py-6 text-xs text-muted sm:flex-row sm:items-center sm:justify-between sm:px-8">
            <span>&copy; {{ date('Y') }} LyraAuth</span>
            <span class="font-mono">OAuth 2.0 · OpenID Connect 1.0</span>
        </div>
    </footer>
</div>
@endsection
