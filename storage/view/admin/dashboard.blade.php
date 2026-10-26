@extends('layouts.shell')

@section('content')
{{-- 读数 --}}
<div class="panel grid grid-cols-2 lg:grid-cols-4">
    <div class="readout border-b border-r border-line lg:border-b-0">
        <span class="readout-label">用户总数</span>
        <span class="readout-value">{{ number_format($stats['users']) }}</span>
        <span class="readout-note">活跃 <span class="accent">{{ number_format($stats['active_users']) }}</span></span>
    </div>
    <div class="readout border-b border-line lg:border-b-0 lg:border-r">
        <span class="readout-label">应用</span>
        <span class="readout-value">{{ number_format($stats['clients']) }}</span>
        <span class="readout-note">组织 {{ number_format($stats['organizations']) }}</span>
    </div>
    <div class="readout border-r border-line">
        <span class="readout-label">活跃会话</span>
        <span class="readout-value">{{ number_format($stats['sessions']) }}</span>
        <span class="readout-note">刷新令牌 {{ number_format($stats['tokens']) }}</span>
    </div>
    <div class="readout">
        <span class="readout-label">近 24 小时登录</span>
        <span class="readout-value">{{ number_format($loginStats['success'] + $loginStats['failed']) }}</span>
        <span class="readout-note">
            成功 <span class="accent">{{ $loginStats['success'] }}</span>
            · 失败 {{ $loginStats['failed'] }}
        </span>
    </div>
</div>

<div class="mt-7 grid gap-5 lg:grid-cols-2">
    {{-- 最近登录 --}}
    <section>
        <div class="section-title">
            <span>最近登录尝试</span>
            <a href="/admin/audit-logs?tab=logins" class="text-[11.5px] font-normal text-muted hover:text-brand hover:underline">全部</a>
        </div>
        <div class="panel overflow-hidden">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="w-[52%]">账号</th>
                        <th class="w-[24%]">结果</th>
                        <th class="w-[24%] text-right">时间</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentLogins as $attempt)
                        <tr>
                            <td class="max-w-0 truncate font-mono text-[12px]">{{ $attempt->identifier ?: '—' }}</td>
                            <td>
                                @if ($attempt->result === 'success')
                                    <span class="badge badge-success">成功</span>
                                @elseif (in_array($attempt->result, ['bad_credentials', 'mfa_failed'], true))
                                    <span class="badge badge-danger">失败</span>
                                @else
                                    <span class="badge badge-warning">拦截</span>
                                @endif
                            </td>
                            <td class="text-right font-mono text-[11.5px] text-muted">{{ \Carbon\Carbon::parse($attempt->created_at)->format('H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-8 text-center text-[13px] text-muted">暂无记录。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- 最近操作 --}}
    <section>
        <div class="section-title">
            <span>最近操作</span>
            <a href="/admin/audit-logs" class="text-[11.5px] font-normal text-muted hover:text-brand hover:underline">全部</a>
        </div>
        <div class="panel overflow-hidden">
            @forelse ($recentAudit as $log)
                <div class="flex items-baseline justify-between gap-3 border-b border-line px-4 py-2 last:border-b-0">
                    <div class="min-w-0">
                        <p class="truncate font-mono text-[11.5px] text-ink">{{ $log->action }}</p>
                        <p class="truncate text-[11px] text-muted">{{ $log->actor_label ?: '系统' }}</p>
                    </div>
                    <span class="shrink-0 font-mono text-[11px] text-faint">{{ \Carbon\Carbon::parse($log->created_at)->format('H:i') }}</span>
                </div>
            @empty
                <p class="px-4 py-8 text-center text-[13px] text-muted">暂无记录。</p>
            @endforelse
        </div>
    </section>
</div>

<div class="mt-7 flex flex-wrap gap-2 border-t border-line pt-5">
    <a href="/admin/users" class="btn btn-secondary btn-sm">管理用户</a>
    <a href="/admin/clients" class="btn btn-secondary btn-sm">管理应用</a>
    <a href="/admin/organizations" class="btn btn-secondary btn-sm">管理组织</a>
    <a href="/admin/scopes" class="btn btn-secondary btn-sm">权限范围</a>
    <a href="/admin/settings" class="btn btn-secondary btn-sm">站点设置</a>
</div>
@endsection
