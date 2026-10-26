@extends('layouts.shell')

@section('content')
@if (! $emailVerified)
    <div class="alert alert-warning mb-5">
        <span>你的邮箱尚未验证，部分功能可能受限。请到注册邮箱查收验证邮件。</span>
    </div>
@endif

@if ($mustChangePassword)
    <div class="alert alert-warning mb-5">
        <span>你正在使用初始密码，请立即前往「账号安全」修改。</span>
    </div>
@endif

{{-- 顶部指标 --}}
<div class="grid gap-px overflow-hidden rounded-[10px] border border-line bg-line sm:grid-cols-2 lg:grid-cols-4">
    <div class="bg-surface p-5">
        <p class="text-xs font-medium text-muted">双因素认证</p>
        <p class="mt-1.5 text-[15px] font-semibold text-ink">{{ $summary['totp'] ? '已启用' : '未启用' }}</p>
        <a href="/account/security" class="mt-1 inline-block text-xs text-brand hover:underline">{{ $summary['totp'] ? '管理' : '立即启用' }}</a>
    </div>
    <div class="bg-surface p-5">
        <p class="text-xs font-medium text-muted">Passkey</p>
        <p class="mt-1.5 text-[15px] font-semibold text-ink">{{ $summary['passkey_count'] }} 个</p>
        <a href="/account/security" class="mt-1 inline-block text-xs text-brand hover:underline">管理</a>
    </div>
    <div class="bg-surface p-5">
        <p class="text-xs font-medium text-muted">活跃会话</p>
        <p class="mt-1.5 text-[15px] font-semibold text-ink">{{ $summary['active_sessions'] }}</p>
        <a href="/account/security#sessions" class="mt-1 inline-block text-xs text-brand hover:underline">查看设备</a>
    </div>
    <div class="bg-surface p-5">
        <p class="text-xs font-medium text-muted">授权应用</p>
        <p class="mt-1.5 text-[15px] font-semibold text-ink">{{ $consents->count() }}</p>
        <a href="/account/apps" class="mt-1 inline-block text-xs text-brand hover:underline">管理授权</a>
    </div>
</div>

{{-- 最近会话 --}}
<section class="mt-8">
    <div class="mb-3 flex items-baseline justify-between">
        <h2 class="text-sm font-semibold text-ink">最近登录</h2>
        <a href="/account/security#sessions" class="text-xs text-muted hover:text-brand hover:underline">全部设备</a>
    </div>
    <div class="panel overflow-hidden">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="w-[46%]">设备</th>
                    <th class="hidden w-[30%] sm:table-cell">IP 地址</th>
                    <th class="w-[24%] text-right">最近活动</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sessions as $session)
                    <tr>
                        <td>
                            <span class="text-[13px] text-ink">{{ $session->device_label ?: '未知设备' }}</span>
                            @if ($session->session_id === $currentSessionId)
                                <span class="badge badge-brand ml-2">当前</span>
                            @endif
                        </td>
                        <td class="hidden font-mono text-xs sm:table-cell">{{ $session->ip_address ?: '—' }}</td>
                        <td class="text-right text-xs">{{ \App\Support\View::relativeTime($session->last_activity_at?->getTimestamp()) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="py-6 text-center text-sm text-muted">暂无登录记录。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

{{-- 已授权应用 --}}
<section class="mt-8">
    <div class="mb-3 flex items-baseline justify-between">
        <h2 class="text-sm font-semibold text-ink">已授权应用</h2>
        <a href="/account/apps" class="text-xs text-muted hover:text-brand hover:underline">全部应用</a>
    </div>
    <div class="panel overflow-hidden">
        @forelse ($consents as $consent)
            @php $client = $clients[$consent->client_id] ?? null; @endphp
            @if ($client)
                <div class="flex items-center gap-3 border-b border-line px-4 py-3 last:border-b-0">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-[6px] text-xs font-semibold text-white"
                          style="background-color: {{ $client->logo_color }}">
                        {{ $client->initial() }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[13px] font-medium text-ink">{{ $client->name }}</p>
                        <p class="truncate text-xs text-muted">{{ implode(' · ', (array) explode(' ', (string) $consent->scopes)) }}</p>
                    </div>
                    <span class="text-xs text-muted">{{ \App\Support\View::relativeTime($consent->last_granted_at?->getTimestamp()) }}</span>
                </div>
            @endif
        @empty
            <p class="px-4 py-6 text-center text-sm text-muted">还没有应用获得授权。</p>
        @endforelse
    </div>
</section>

{{-- 组织 --}}
@if ($organizations->isNotEmpty())
    <section class="mt-8">
        <h2 class="mb-3 text-sm font-semibold text-ink">我的组织</h2>
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($organizations as $organization)
                <a href="/organizations/{{ $organization->slug }}" class="panel flex items-center gap-3 p-4 transition-colors hover:border-line-strong">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-[6px] bg-brand-soft text-xs font-semibold text-brand">
                        {{ mb_strtoupper(mb_substr($organization->name, 0, 1)) }}
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-[13px] font-medium text-ink">{{ $organization->name }}</p>
                        <p class="truncate text-xs text-muted">{{ $organization->description ?: $organization->slug }}</p>
                    </div>
                </a>
            @endforeach
        </div>
    </section>
@endif
@endsection
