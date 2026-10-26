@extends('layouts.base')

@section('body')
@php
    /** @var \App\Model\User|null $currentUser */
    $currentUser = $currentUser ?? null;
    $navSection = $navSection ?? '';
    $pageTitle = $pageTitle ?? '控制台';
    $pageDescription = $pageDescription ?? null;
    $isAdmin = $isAdmin ?? false;

    $navItems = [
        ['key' => 'dashboard', 'href' => '/dashboard', 'label' => '概览'],
        ['key' => 'profile', 'href' => '/account/profile', 'label' => '个人资料'],
        ['key' => 'security', 'href' => '/account/security', 'label' => '账号安全'],
        ['key' => 'apps', 'href' => '/account/apps', 'label' => '已授权应用'],
        ['key' => 'organizations', 'href' => '/organizations', 'label' => '组织'],
    ];
@endphp

<div class="min-h-screen lg:flex">

    {{-- 侧栏 --}}
    <aside class="hidden w-60 shrink-0 border-r border-line bg-surface lg:flex lg:flex-col">
        <div class="flex h-16 items-center px-5">
            <a href="/dashboard" class="focus:outline-none">
                @include('partials.brand', ['size' => 'sm'])
            </a>
        </div>

        <nav class="flex-1 space-y-0.5 px-3 py-2" aria-label="主导航">
            @foreach ($navItems as $item)
                @php $active = $navSection === $item['key']; @endphp
                <a href="{{ $item['href'] }}"
                   class="flex items-center rounded-[7px] px-3 py-2 text-sm transition-colors {{ $active ? 'bg-brand-soft font-medium text-brand' : 'text-ink-soft hover:bg-[#f1f3f5] hover:text-ink' }}"
                   @if ($active) aria-current="page" @endif>
                    {{ $item['label'] }}
                </a>
            @endforeach

            @if ($isAdmin)
                <div class="px-3 pb-1.5 pt-5 text-[11px] font-semibold uppercase tracking-wider text-muted">管理</div>
                @foreach ([
                    ['key' => 'admin', 'href' => '/admin', 'label' => '管理概览'],
                    ['key' => 'admin-users', 'href' => '/admin/users', 'label' => '用户'],
                    ['key' => 'admin-clients', 'href' => '/admin/clients', 'label' => '应用'],
                    ['key' => 'admin-orgs', 'href' => '/admin/organizations', 'label' => '组织'],
                    ['key' => 'admin-scopes', 'href' => '/admin/scopes', 'label' => '权限范围'],
                    ['key' => 'admin-audit', 'href' => '/admin/audit-logs', 'label' => '审计日志'],
                    ['key' => 'admin-settings', 'href' => '/admin/settings', 'label' => '站点设置'],
                ] as $item)
                    @php $active = $navSection === $item['key']; @endphp
                    <a href="{{ $item['href'] }}"
                       class="flex items-center rounded-[7px] px-3 py-2 text-sm transition-colors {{ $active ? 'bg-brand-soft font-medium text-brand' : 'text-ink-soft hover:bg-[#f1f3f5] hover:text-ink' }}"
                       @if ($active) aria-current="page" @endif>
                        {{ $item['label'] }}
                    </a>
                @endforeach
            @endif
        </nav>

        <div class="border-t border-line p-3">
            <div class="flex items-center gap-2.5 rounded-[7px] px-2 py-2">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-soft text-xs font-semibold text-brand">
                    {{ $currentUser?->initials() ?? '·' }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-[13px] font-medium text-ink">{{ $currentUser?->display_name ?? '未登录' }}</p>
                    <p class="truncate text-[11px] text-muted">{{ $currentUser?->email }}</p>
                </div>
            </div>
            <form method="POST" action="/logout" class="mt-1">
                {!! csrf_field() !!}
                <button type="submit" class="btn btn-ghost btn-sm w-full justify-start">退出登录</button>
            </form>
        </div>
    </aside>

    {{-- 主区域 --}}
    <div class="min-w-0 flex-1">
        <header class="sticky top-0 z-20 border-b border-line bg-surface/95 backdrop-blur lg:hidden">
            <div class="flex h-14 items-center justify-between px-4">
                <a href="/dashboard">@include('partials.brand', ['size' => 'sm'])</a>
                <details class="relative" data-menu>
                    <summary class="btn btn-secondary btn-sm cursor-pointer list-none">菜单</summary>
                    <div class="panel absolute right-0 z-30 mt-2 w-52 p-1.5">
                        @foreach ($navItems as $item)
                            <a href="{{ $item['href'] }}" class="block rounded-[6px] px-3 py-2 text-sm text-ink-soft hover:bg-[#f1f3f5]">{{ $item['label'] }}</a>
                        @endforeach
                        @if ($isAdmin)
                            <a href="/admin" class="block rounded-[6px] px-3 py-2 text-sm text-ink-soft hover:bg-[#f1f3f5]">管理后台</a>
                        @endif
                        <form method="POST" action="/logout" class="border-t border-line pt-1.5 mt-1.5">
                            {!! csrf_field() !!}
                            <button type="submit" class="w-full rounded-[6px] px-3 py-2 text-left text-sm text-ink-soft hover:bg-[#f1f3f5]">退出登录</button>
                        </form>
                    </div>
                </details>
            </div>
        </header>

        <main id="main" class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6 lg:px-10 lg:py-10">
            <div class="mb-7">
                <h1 class="text-[22px] font-semibold tracking-tight text-ink">{{ $pageTitle }}</h1>
                @if ($pageDescription)
                    <p class="mt-1.5 text-sm leading-6 text-muted">{{ $pageDescription }}</p>
                @endif
            </div>

            @include('partials.flash', ['flash' => flash(), 'errorBag' => $errorBag ?? null])

            @yield('content')
        </main>
    </div>
</div>
@endsection
