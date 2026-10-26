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

{{-- 读数：一个整体面板，内部用竖线分隔 --}}
<div class="panel grid grid-cols-2 lg:grid-cols-4">
    <div class="readout border-b border-r border-line lg:border-b-0">
        <span class="readout-label">双因素</span>
        <span class="readout-value">{{ $summary['totp'] ? '已启用' : '未启用' }}</span>
        <a href="/account/security" class="readout-note underline decoration-line-strong underline-offset-2 hover:text-brand">{{ $summary['totp'] ? '管理' : '立即启用' }}</a>
    </div>
    <div class="readout border-b border-line lg:border-b-0 lg:border-r">
        <span class="readout-label">Passkey</span>
        <span class="readout-value">{{ $summary['passkey_count'] }}</span>
        <a href="/account/security" class="readout-note underline decoration-line-strong underline-offset-2 hover:text-brand">管理凭据</a>
    </div>
    <div class="readout border-r border-line">
        <span class="readout-label">活跃会话</span>
        <span class="readout-value">{{ $summary['active_sessions'] }}</span>
        <a href="/account/security#sessions" class="readout-note underline decoration-line-strong underline-offset-2 hover:text-brand">查看设备</a>
    </div>
    <div class="readout">
        <span class="readout-label">授权应用</span>
        <span class="readout-value">{{ $consents->count() }}</span>
        <a href="/account/apps" class="readout-note underline decoration-line-strong underline-offset-2 hover:text-brand">管理授权</a>
    </div>
</div>

{{-- 最近登录 --}}
<section class="mt-8">
    <div class="section-title">
        <span>最近登录</span>
        <a href="/account/security#sessions" class="text-[11.5px] font-normal text-muted hover:text-brand hover:underline">全部设备</a>
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
                            <span class="text-ink">{{ $session->device_label ?: '未知设备' }}</span>
                            @if ($session->session_id === $currentSessionId)
                                <span class="badge badge-brand ml-2">当前</span>
                            @endif
                        </td>
                        <td class="hidden font-mono text-[12px] sm:table-cell">{{ $session->ip_address ?: '—' }}</td>
                        <td class="text-right text-[12px] text-muted">{{ \App\Support\View::relativeTime($session->last_activity_at?->getTimestamp()) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="py-8 text-center text-[13px] text-muted">暂无登录记录。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

{{-- 已授权应用 --}}
<section class="mt-8">
    <div class="section-title">
        <span>已授权应用</span>
        <a href="/account/apps" class="text-[11.5px] font-normal text-muted hover:text-brand hover:underline">全部应用</a>
    </div>
    <div class="panel overflow-hidden">
        @forelse ($consents as $consent)
            @php $client = $clients[$consent->client_id] ?? null; @endphp
            @if ($client)
                <div class="flex items-center gap-3 border-b border-line px-4 py-2.5 last:border-b-0">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[2px] font-mono text-[11px] font-medium"
                          style="background-color: {{ $client->logo_color }}; color: {{ $client->logoInk() }}">
                        {{ $client->initial() }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[13px] text-ink">{{ $client->name }}</p>
                        <p class="truncate font-mono text-[11px] text-muted">{{ implode(' ', array_filter(explode(' ', (string) $consent->scopes))) }}</p>
                    </div>
                    <span class="shrink-0 font-mono text-[11px] text-faint">{{ \App\Support\View::relativeTime($consent->last_granted_at?->getTimestamp()) }}</span>
                </div>
            @endif
        @empty
            <p class="px-4 py-8 text-center text-[13px] text-muted">还没有应用获得授权。</p>
        @endforelse
    </div>
</section>

{{-- 组织 --}}
@if ($organizations->isNotEmpty())
    <section class="mt-8">
        <div class="section-title"><span>我的组织</span></div>
        <div class="panel overflow-hidden">
            @foreach ($organizations as $organization)
                <a href="/organizations/{{ $organization->slug }}"
                   class="flex items-center justify-between gap-3 border-b border-line px-4 py-2.5 transition-colors last:border-b-0 hover:bg-sunk">
                    <div class="min-w-0">
                        <p class="truncate text-[13px] text-ink">{{ $organization->name }}</p>
                        <p class="truncate text-[11.5px] text-muted">{{ $organization->description ?: $organization->slug }}</p>
                    </div>
                    <span class="shrink-0 font-mono text-[11px] text-faint">{{ $organization->slug }}</span>
                </a>
            @endforeach
        </div>
    </section>
@endif
@endsection
