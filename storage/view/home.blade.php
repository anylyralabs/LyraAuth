@extends('layouts.base')

@section('title', '统一身份认证平台')
@section('description', 'LyraAuth 为你的应用提供 OAuth 2.0 与 OpenID Connect 标准的统一登录、授权与单点登出能力。')

@section('body')
<div class="min-h-screen">

    {{-- 顶栏 --}}
    <header class="sticky top-0 z-30 border-b border-line bg-canvas/90 backdrop-blur">
        <div class="mx-auto flex h-14 w-full max-w-[1120px] items-center justify-between px-5 sm:px-8">
            <a href="/">@include('partials.brand', ['size' => 'sm', 'showTagline' => true])</a>
            <div class="flex items-center gap-1.5">
                <a href="/login" class="btn btn-ghost btn-sm">登录</a>
                <a href="/register" class="btn btn-primary btn-sm">创建账号</a>
            </div>
        </div>
    </header>

    <main id="main">

        {{-- 主视觉：左侧文字，右侧是实时签发的凭证示意 --}}
        <section class="border-b border-line">
            <div class="mx-auto grid w-full max-w-[1120px] items-center gap-12 px-5 py-16 sm:px-8 lg:grid-cols-[minmax(0,1fr)_380px] lg:py-24">

                <div>
                    <p class="inline-flex items-center gap-2 border border-brand-line bg-brand-soft px-2 py-0.5 font-mono text-[10.5px] tracking-[0.06em] text-brand">
                        OAuth 2.0 · OPENID CONNECT
                    </p>

                    <h1 class="mt-5 text-[38px] font-semibold leading-[1.14] tracking-[-0.035em] text-ink sm:text-[46px]" style="font-family: var(--font-display)">
                        身份只认证一次，<br>其余交给协议
                    </h1>

                    <p class="mt-5 max-w-[480px] text-[14px] leading-[1.75] text-muted">
                        把登录、授权、双因素与单点登出收敛成一套服务。应用只需接入标准协议，不必再自己处理密码、令牌与审计。
                    </p>

                    <div class="mt-8 flex flex-wrap items-center gap-2.5">
                        <a href="/login" class="btn btn-primary">开始使用</a>
                        <a href="/.well-known/openid-configuration" class="btn btn-secondary">查看发现文档</a>
                    </div>
                </div>

                {{-- 凭证示意：一个真实结构的 id_token 片段 --}}
                <div class="relative hidden lg:block">
                    <div class="panel overflow-hidden">
                        <div class="flex items-center justify-between border-b border-line bg-sunk px-3.5 py-2">
                            <span class="font-mono text-[10.5px] tracking-[0.06em] text-muted">ID TOKEN</span>
                            <span class="badge badge-brand">RS256</span>
                        </div>
                        <div class="space-y-2 p-3.5 font-mono text-[11px] leading-[1.9]">
                            <div class="flex gap-2">
                                <span class="w-[54px] shrink-0 text-faint">iss</span>
                                <span class="truncate text-ink-soft">http://localhost:9501</span>
                            </div>
                            <div class="flex gap-2">
                                <span class="w-[54px] shrink-0 text-faint">sub</span>
                                <span class="truncate text-ink-soft">6c16a0be-d530-44a6…</span>
                            </div>
                            <div class="flex gap-2">
                                <span class="w-[54px] shrink-0 text-faint">aud</span>
                                <span class="truncate text-ink-soft">lyra_c6549addd774…</span>
                            </div>
                            <div class="flex gap-2">
                                <span class="w-[54px] shrink-0 text-faint">amr</span>
                                <span class="truncate text-ink-soft">["pwd", "totp"]</span>
                            </div>
                            <div class="flex gap-2">
                                <span class="w-[54px] shrink-0 text-faint">sid</span>
                                <span class="truncate text-ink-soft">19c4b3d300076714…</span>
                            </div>
                        </div>
                        <div class="border-t border-line bg-sunk px-3.5 py-2 font-mono text-[10.5px] text-faint">
                            JWKS 公钥验签 · at_hash 绑定访问令牌
                        </div>
                    </div>

                    {{-- 角标：贴在面板内侧，不溢出 --}}
                    <div class="absolute right-3 top-[38px] border border-brand-line bg-brand-soft px-1.5 py-0.5 font-mono text-[9.5px] tracking-[0.06em] text-brand">
                        VERIFIED
                    </div>
                </div>
            </div>
        </section>

        {{-- 能力：用刻度线分隔，不用卡片 --}}
        <section class="border-b border-line">
            <div class="mx-auto w-full max-w-[1120px] px-5 sm:px-8">
                <div class="grid sm:grid-cols-2 lg:grid-cols-4">
                    <div class="border-b border-line py-8 sm:border-r lg:border-b-0 lg:pr-8">
                        <p class="font-mono text-[10.5px] tracking-[0.08em] text-brand">01 / 协议</p>
                        <h2 class="mt-2.5 text-[15px] font-semibold text-ink">标准且完整</h2>
                        <p class="mt-2 text-[13px] leading-[1.7] text-muted">
                            授权码 + PKCE、客户端凭证、设备码与刷新轮换；发现文档与 JWKS 开箱可用。
                        </p>
                    </div>
                    <div class="border-b border-line py-8 lg:border-b-0 lg:border-r lg:px-8">
                        <p class="font-mono text-[10.5px] tracking-[0.08em] text-brand">02 / 会话</p>
                        <h2 class="mt-2.5 text-[15px] font-semibold text-ink">单点登录与登出</h2>
                        <p class="mt-2 text-[13px] leading-[1.7] text-muted">
                            一次登录贯穿全部接入应用；单点登出会同时吊销该会话签发的令牌。
                        </p>
                    </div>
                    <div class="border-b border-line py-8 sm:border-r lg:border-b-0 lg:px-8">
                        <p class="font-mono text-[10.5px] tracking-[0.08em] text-brand">03 / 验证</p>
                        <h2 class="mt-2.5 text-[15px] font-semibold text-ink">多重因子</h2>
                        <p class="mt-2 text-[13px] leading-[1.7] text-muted">
                            TOTP 验证器与 Passkey 并行，恢复码兜底，信任设备可免重复验证。
                        </p>
                    </div>
                    <div class="py-8 lg:pl-8">
                        <p class="font-mono text-[10.5px] tracking-[0.08em] text-brand">04 / 治理</p>
                        <h2 class="mt-2.5 text-[15px] font-semibold text-ink">可追溯</h2>
                        <p class="mt-2 text-[13px] leading-[1.7] text-muted">
                            登录、授权、令牌签发与配置变更全部留痕，敏感字段自动脱敏。
                        </p>
                    </div>
                </div>
            </div>
        </section>

        {{-- 接入端点：等宽表格，面向开发者 --}}
        <section>
            <div class="mx-auto w-full max-w-[1120px] px-5 py-14 sm:px-8">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <h2 class="text-[19px] font-semibold tracking-[-0.02em] text-ink" style="font-family: var(--font-display)">接入端点</h2>
                    <p class="font-mono text-[11px] text-faint">OIDC Discovery 自动发现</p>
                </div>

                <div class="mt-5 overflow-hidden border border-line bg-surface">
                    <table class="w-full text-[12.5px]">
                        <tbody>
                            @foreach ([
                                ['issuer', '/.well-known/openid-configuration'],
                                ['authorize', '/oauth/authorize'],
                                ['token', '/oauth/token'],
                                ['userinfo', '/userinfo'],
                                ['jwks', '/.well-known/jwks.json'],
                                ['logout', '/oauth/logout'],
                            ] as [$name, $path])
                                <tr class="border-b border-line last:border-b-0">
                                    <td class="w-[120px] px-4 py-2.5 font-mono text-[11px] tracking-[0.04em] text-brand">{{ $name }}</td>
                                    <td class="px-4 py-2.5 font-mono text-[12.5px] text-ink-soft">{{ $path }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-line">
        <div class="mx-auto flex w-full max-w-[1120px] flex-col gap-1.5 px-5 py-6 font-mono text-[10.5px] tracking-[0.04em] text-faint sm:flex-row sm:items-center sm:justify-between sm:px-8">
            <span>&copy; {{ date('Y') }} LyraAuth</span>
            <span>OAuth 2.0 · OpenID Connect 1.0</span>
        </div>
    </footer>
</div>
@endsection
