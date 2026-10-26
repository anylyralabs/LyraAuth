@extends('layouts.shell')

@section('content')
{{-- 指标 --}}
<div class="grid gap-px overflow-hidden rounded-[10px] border border-line bg-line sm:grid-cols-2 lg:grid-cols-4">
    <div class="bg-surface p-5">
        <p class="text-xs font-medium text-muted">用户总数</p>
        <p class="mt-1.5 text-2xl font-semibold tracking-tight text-ink">{{ number_format($stats['users']) }}</p>
        <p class="mt-1 text-xs text-muted">活跃 {{ number_format($stats['active_users']) }}</p>
    </div>
    <div class="bg-surface p-5">
        <p class="text-xs font-medium text-muted">应用</p>
        <p class="mt-1.5 text-2xl font-semibold tracking-tight text-ink">{{ number_format($stats['clients']) }}</p>
        <p class="mt-1 text-xs text-muted">组织 {{ number_format($stats['organizations']) }}</p>
    </div>
    <div class="bg-surface p-5">
        <p class="text-xs font-medium text-muted">活跃会话</p>
        <p class="mt-1.5 text-2xl font-semibold tracking-tight text-ink">{{ number_format($stats['sessions']) }}</p>
        <p class="mt-1 text-xs text-muted">有效刷新令牌 {{ number_format($stats['tokens']) }}</p>
    </div>
    <div class="bg-surface p-5">
        <p class="text-xs font-medium text-muted">近 24 小时登录</p>
        <p class="mt-1.5 text-2xl font-semibold tracking-tight text-ink">{{ number_format($loginStats['success'] + $loginStats['failed']) }}</p>
        <p class="mt-1 text-xs text-muted">
            成功 {{ $loginStats['success'] }} · 失败 {{ $loginStats['failed'] }}
        </p>
    </div>
</div>

<div class="mt-6 grid gap-5 lg:grid-cols-2">
    {{-- 最近登录 --}}
    <section>
        <div class="mb-3 flex items-baseline justify-between">
            <h2 class="text-sm font-semibold text-ink">最近登录尝试</h2>
            <a href="/admin/audit-logs?tab=logins" class="text-xs text-muted hover:text-brand hover:underline">全部</a>
        </div>
        <div class="panel overflow-hidden">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="w-[46%]">账号</th>
                        <th class="w-[24%]">结果</th>
                        <th class="w-[30%] text-right">时间</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentLogins as $attempt)
                        <tr>
                            <td class="max-w-0 truncate text-[13px]">{{ $attempt->identifier ?: '—' }}</td>
                            <td>
                                @if ($attempt->result === 'success')
                                    <span class="badge badge-success">成功</span>
                                @elseif (in_array($attempt->result, ['bad_credentials', 'mfa_failed'], true))
                                    <span class="badge badge-danger">失败</span>
                                @else
                                    <span class="badge badge-warning">拦截</span>
                                @endif
                            </td>
                            <td class="text-right text-xs">{{ \Carbon\Carbon::parse($attempt->created_at)->format('H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-8 text-center text-sm text-muted">暂无记录。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- 最近操作 --}}
    <section>
        <div class="mb-3 flex items-baseline justify-between">
            <h2 class="text-sm font-semibold text-ink">最近操作</h2>
            <a href="/admin/audit-logs" class="text-xs text-muted hover:text-brand hover:underline">全部</a>
        </div>
        <div class="panel overflow-hidden">
            <div class="divide-y divide-line">
                @forelse ($recentAudit as $log)
                    <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                        <div class="min-w-0">
                            <p class="truncate font-mono text-[12px] text-ink">{{ $log->action }}</p>
                            <p class="truncate text-xs text-muted">{{ $log->actor_label ?: '系统' }}</p>
                        </div>
                        <span class="shrink-0 text-xs text-muted">{{ \Carbon\Carbon::parse($log->created_at)->format('H:i') }}</span>
                    </div>
                @empty
                    <p class="px-4 py-8 text-center text-sm text-muted">暂无记录。</p>
                @endforelse
            </div>
        </div>
    </section>
</div>

<div class="mt-6 flex flex-wrap gap-2">
    <a href="/admin/users" class="btn btn-secondary btn-sm">管理用户</a>
    <a href="/admin/clients" class="btn btn-secondary btn-sm">管理应用</a>
    <a href="/admin/organizations" class="btn btn-secondary btn-sm">管理组织</a>
    <a href="/admin/scopes" class="btn btn-secondary btn-sm">权限范围</a>
    <a href="/admin/settings" class="btn btn-secondary btn-sm">站点设置</a>
</div>
@endsection
