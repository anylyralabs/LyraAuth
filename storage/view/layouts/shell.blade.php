@extends('layouts.base')

@section('body')
@php
    /** @var \App\Model\User|null $currentUser */
    $currentUser = $currentUser ?? null;
    $navSection = $navSection ?? '';
    $pageTitle = $pageTitle ?? '控制台';
    $pageDescription = $pageDescription ?? null;
    $isAdmin = $isAdmin ?? false;

    $accountNav = [
        ['key' => 'dashboard', 'href' => '/dashboard', 'label' => '概览'],
        ['key' => 'profile', 'href' => '/account/profile', 'label' => '个人资料'],
        ['key' => 'security', 'href' => '/account/security', 'label' => '账号安全'],
        ['key' => 'apps', 'href' => '/account/apps', 'label' => '已授权应用'],
        ['key' => 'organizations', 'href' => '/organizations', 'label' => '组织'],
    ];

    $adminNav = [
        ['key' => 'admin', 'href' => '/admin', 'label' => '概览'],
        ['key' => 'admin-users', 'href' => '/admin/users', 'label' => '用户'],
        ['key' => 'admin-clients', 'href' => '/admin/clients', 'label' => '应用'],
        ['key' => 'admin-orgs', 'href' => '/admin/organizations', 'label' => '组织'],
        ['key' => 'admin-scopes', 'href' => '/admin/scopes', 'label' => '权限范围'],
        ['key' => 'admin-audit', 'href' => '/admin/audit-logs', 'label' => '审计日志'],
        ['key' => 'admin-settings', 'href' => '/admin/settings', 'label' => '站点设置'],
    ];
@endphp

<div class="min-h-screen lg:grid lg:grid-cols-[228px_minmax(0,1fr)]">

    {{-- 侧栏 --}}
    <aside class="hidden border-r border-line bg-ink lg:flex lg:h-screen lg:flex-col lg:sticky lg:top-0">
        <div class="flex h-16 shrink-0 items-center px-5">
            <a href="/dashboard" class="focus:outline-none">
                <span class="inline-flex items-center gap-2.5">
                    <svg class="h-7 w-7" viewBox="0 0 32 32" fill="none" aria-hidden="true">
                        <rect x="0.5" y="0.5" width="31" height="31" rx="2.5" fill="#F5F5F2"/>
                        <path d="M10 23V9" stroke="#14171A" stroke-width="2.1" stroke-linecap="square"/>
                        <path d="M10 16.4L16.6 9" stroke="#14171A" stroke-width="2.1" stroke-linecap="square"/>
                        <path d="M10 16.4L17.2 23" stroke="#14171A" stroke-width="2.1" stroke-linecap="square"/>
                        <circle cx="22.6" cy="16" r="3.1" stroke="#96631C" stroke-width="1.7"/>
                    </svg>
                    <span class="text-[14px] font-semibold tracking-[-0.02em] text-[#F5F5F2]" style="font-family: var(--font-display)">LyraAuth</span>
                </span>
            </a>
        </div>

        <nav class="flex-1 overflow-y-auto px-3 py-3" aria-label="主导航">
            <p class="px-2 pb-1.5 font-mono text-[10px] uppercase tracking-[0.1em] text-[#8B9197]">账号</p>
            @foreach ($accountNav as $item)
                @php $active = $navSection === $item['key']; @endphp
                <a href="{{ $item['href'] }}"
                   class="relative flex items-center rounded-[3px] px-2 py-[7px] text-[13px] transition-colors {{ $active ? 'bg-[#22262B] text-[#F5F5F2]' : 'text-[#B4B9BE] hover:bg-[#1C2025] hover:text-[#EDEDE8]' }}"
                   @if ($active) aria-current="page" @endif>
                    @if ($active)
                        <span class="absolute left-0 top-1/2 h-3.5 w-[2px] -translate-y-1/2 bg-brand" aria-hidden="true"></span>
                    @endif
                    {{ $item['label'] }}
                </a>
            @endforeach

            @if ($isAdmin)
                <p class="px-2 pb-1.5 pt-5 font-mono text-[10px] uppercase tracking-[0.1em] text-[#8B9197]">管理</p>
                @foreach ($adminNav as $item)
                    @php $active = $navSection === $item['key']; @endphp
                    <a href="{{ $item['href'] }}"
                       class="relative flex items-center rounded-[3px] px-2 py-[7px] text-[13px] transition-colors {{ $active ? 'bg-[#22262B] text-[#F5F5F2]' : 'text-[#B4B9BE] hover:bg-[#1C2025] hover:text-[#EDEDE8]' }}"
                       @if ($active) aria-current="page" @endif>
                        @if ($active)
                            <span class="absolute left-0 top-1/2 h-3.5 w-[2px] -translate-y-1/2 bg-brand" aria-hidden="true"></span>
                        @endif
                        {{ $item['label'] }}
                    </a>
                @endforeach
            @endif
        </nav>

        <div class="shrink-0 border-t border-[#2A2E33] p-3">
            <div class="flex items-center gap-2.5 px-1 py-1.5">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[2px] bg-[#22262B] font-mono text-[11px] font-medium text-[#C8C8C0]">
                    {{ $currentUser?->initials() ?? '·' }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-[12.5px] font-medium text-[#E4E4DE]">{{ $currentUser?->display_name ?? '未登录' }}</p>
                    <p class="truncate font-mono text-[10.5px] text-[#8B9197]">{{ $currentUser?->email }}</p>
                </div>
            </div>
            <form method="POST" action="/logout" class="mt-1">
                {!! csrf_field() !!}
                <button type="submit" class="w-full rounded-[3px] px-2 py-[5px] text-left text-[12px] text-[#A0A6AB] transition-colors hover:bg-[#1C2025] hover:text-[#D5D5CE]">退出登录</button>
            </form>
        </div>
    </aside>

    {{-- 内容 --}}
    <div class="flex min-h-screen min-w-0 flex-col">
        {{-- 移动端顶栏 --}}
        <header class="sticky top-0 z-20 border-b border-line bg-canvas/95 backdrop-blur lg:hidden">
            <div class="flex h-14 items-center justify-between px-4">
                <a href="/dashboard">@include('partials.brand', ['size' => 'sm'])</a>
                <details class="relative">
                    <summary class="flex h-8 w-8 cursor-pointer list-none items-center justify-center rounded-[3px] border border-line-strong bg-surface" aria-label="打开菜单">
                        <svg class="h-4 w-4 text-ink-soft" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                            <path d="M2.5 4.5h11M2.5 8h11M2.5 11.5h11" stroke="currentColor" stroke-width="1.4" stroke-linecap="square"/>
                        </svg>
                    </summary>
                    <div class="panel absolute right-0 z-30 mt-2 w-56 overflow-hidden p-1.5">
                        @foreach ($accountNav as $item)
                            <a href="{{ $item['href'] }}" class="block rounded-[3px] px-2.5 py-[7px] text-[13px] {{ $navSection === $item['key'] ? 'bg-brand-soft font-medium text-brand' : 'text-ink-soft hover:bg-sunk' }}">{{ $item['label'] }}</a>
                        @endforeach
                        @if ($isAdmin)
                            <div class="my-1 border-t border-line"></div>
                            @foreach ($adminNav as $item)
                                <a href="{{ $item['href'] }}" class="block rounded-[3px] px-2.5 py-[7px] text-[13px] {{ $navSection === $item['key'] ? 'bg-brand-soft font-medium text-brand' : 'text-ink-soft hover:bg-sunk' }}">{{ $item['label'] }}</a>
                            @endforeach
                        @endif
                        <div class="my-1 border-t border-line"></div>
                        <form method="POST" action="/logout">
                            {!! csrf_field() !!}
                            <button type="submit" class="block w-full rounded-[3px] px-2.5 py-[7px] text-left text-[13px] text-ink-soft hover:bg-sunk">退出登录</button>
                        </form>
                    </div>
                </details>
            </div>
        </header>

        <main id="main" class="mx-auto flex w-full max-w-[1000px] flex-1 flex-col px-5 py-7 sm:px-8 lg:px-12 lg:py-10">
            <div class="page-head">
                <h1>{{ $pageTitle }}</h1>
                @if ($pageDescription)
                    <p>{{ $pageDescription }}</p>
                @endif
            </div>

            @include('partials.flash', ['flash' => flash(), 'errorBag' => $errorBag ?? null])

            @yield('content')

            <div class="flex-1" aria-hidden="true"></div>

            <footer class="mt-12 flex flex-wrap items-center justify-between gap-2 border-t border-line pt-4 font-mono text-[10.5px] tracking-[0.04em] text-faint">
                <span>LyraAuth · OIDC 1.0</span>
                <span>{{ date('Y-m-d') }}</span>
            </footer>
        </main>
    </div>
</div>
@endsection
