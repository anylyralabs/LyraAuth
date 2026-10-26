@extends('layouts.shell')

@section('content')
@php
    $tabs = [
        ['key' => 'audit', 'label' => '操作日志'],
        ['key' => 'logins', 'label' => '登录记录'],
    ];
@endphp

<div class="mb-4 flex flex-wrap items-end justify-between gap-4 border-b border-line">
    <nav class="flex gap-5" aria-label="日志类型">
        @foreach ($tabs as $item)
            <a href="/admin/audit-logs?tab={{ $item['key'] }}"
               class="-mb-px border-b-2 pb-2 text-[13px] transition-colors {{ $tab === $item['key'] ? 'border-brand font-medium text-ink' : 'border-transparent text-muted hover:text-ink' }}"
               @if ($tab === $item['key']) aria-current="page" @endif>
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>

    <form method="GET" action="/admin/audit-logs" class="mb-3 flex flex-wrap items-end gap-2.5">
        <input type="hidden" name="tab" value="{{ $tab }}">
        @if ($tab === 'audit')
            <div>
                <label for="action" class="field-label">动作</label>
                <input id="action" name="action" type="text" value="{{ $action }}" class="field-input w-44 font-mono !text-[12px]" placeholder="auth.login">
            </div>
        @endif
        <div>
            <label for="result" class="field-label">结果</label>
            <select id="result" name="result" class="field-input w-28">
                <option value="">全部</option>
                @if ($tab === 'audit')
                    <option value="success" {{ $result === 'success' ? 'selected' : '' }}>成功</option>
                    <option value="failure" {{ $result === 'failure' ? 'selected' : '' }}>失败</option>
                    <option value="denied" {{ $result === 'denied' ? 'selected' : '' }}>拒绝</option>
                @else
                    <option value="success" {{ $result === 'success' ? 'selected' : '' }}>成功</option>
                    <option value="bad_credentials" {{ $result === 'bad_credentials' ? 'selected' : '' }}>密码错误</option>
                    <option value="locked" {{ $result === 'locked' ? 'selected' : '' }}>已锁定</option>
                    <option value="blocked" {{ $result === 'blocked' ? 'selected' : '' }}>已阻止</option>
                @endif
            </select>
        </div>
        <div>
            <label for="q" class="field-label">关键词</label>
            <input id="q" name="q" type="search" value="{{ $keyword }}" class="field-input w-44"
                   placeholder="{{ $tab === 'audit' ? '操作者 / 目标 / IP' : '账号或邮箱' }}">
        </div>
        <button type="submit" class="btn btn-secondary">筛选</button>
    </form>
</div>

<div class="panel overflow-hidden">
    <div class="overflow-x-auto">
        @if ($tab === 'audit')
            <table class="data-table table-fixed">
                <thead>
                    <tr>
                        <th class="w-[16%]">时间</th>
                        <th class="w-[22%]">动作</th>
                        <th class="hidden w-[22%] md:table-cell">操作者</th>
                        <th class="hidden w-[16%] lg:table-cell">IP</th>
                        <th class="w-[12%]">结果</th>
                        <th class="w-[12%] text-right">详情</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td class="whitespace-nowrap font-mono text-[11.5px] text-muted" title="{{ $log->created_at ? \Carbon\Carbon::parse($log->created_at)->format('Y-m-d H:i:s') : '' }}">{{ $log->created_at ? \Carbon\Carbon::parse($log->created_at)->format('m-d H:i') : '—' }}</td>
                            <td class="max-w-0 truncate font-mono text-[12px] text-ink" title="{{ $log->action }}">{{ $log->action }}</td>
                            <td class="hidden max-w-0 truncate text-[12px] md:table-cell">{{ $log->actor_label ?: '—' }}</td>
                            <td class="hidden font-mono text-[11.5px] lg:table-cell">{{ $log->ip_address ?: '—' }}</td>
                            <td>
                                @if ($log->result === 'success')
                                    <span class="badge badge-success">成功</span>
                                @elseif ($log->result === 'denied')
                                    <span class="badge badge-warning">拒绝</span>
                                @else
                                    <span class="badge badge-danger">失败</span>
                                @endif
                            </td>
                            <td class="text-right">
                                @if ($log->meta || $log->target_type)
                                    <details class="relative inline-block text-left" data-menu>
                                        <summary class="btn btn-ghost btn-sm cursor-pointer list-none">查看</summary>
                                        <div class="panel absolute right-0 z-30 mt-1 w-72 p-3 text-left">
                                            <dl class="space-y-2 text-[12px]">
                                                @if ($log->target_type)
                                                    <div class="flex gap-2"><dt class="w-14 shrink-0 text-muted">目标</dt><dd class="break-all font-mono text-[11px] text-ink">{{ $log->target_type }}#{{ $log->target_id }}</dd></div>
                                                @endif
                                                @if ($log->user_agent)
                                                    <div class="flex gap-2"><dt class="w-14 shrink-0 text-muted">UA</dt><dd class="break-all text-[11px] leading-[1.5] text-ink-soft">{{ $log->user_agent }}</dd></div>
                                                @endif
                                                @if ($log->meta)
                                                    <div class="flex gap-2"><dt class="w-14 shrink-0 text-muted">上下文</dt><dd class="break-all font-mono text-[10.5px] leading-[1.55] text-ink-soft">{{ $log->meta }}</dd></div>
                                                @endif
                                            </dl>
                                        </div>
                                    </details>
                                @else
                                    <span class="text-[12px] text-faint">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-[13px] text-muted">没有符合条件的记录。</td></tr>
                    @endforelse
                </tbody>
            </table>
        @else
            <table class="data-table table-fixed">
                <thead>
                    <tr>
                        <th class="w-[18%]">时间</th>
                        <th class="w-[32%]">账号</th>
                        <th class="hidden w-[20%] sm:table-cell">IP</th>
                        <th class="w-[18%]">结果</th>
                        <th class="hidden w-[12%] lg:table-cell">用户</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($attempts as $attempt)
                        <tr>
                            <td class="whitespace-nowrap font-mono text-[11.5px] text-muted">{{ $attempt->created_at ? \Carbon\Carbon::parse($attempt->created_at)->format('m-d H:i') : '—' }}</td>
                            <td class="max-w-0 truncate font-mono text-[12px] text-ink">{{ $attempt->identifier ?: '—' }}</td>
                            <td class="hidden font-mono text-[11.5px] sm:table-cell">{{ $attempt->ip_address ?: '—' }}</td>
                            <td>
                                @if ($attempt->result === 'success')
                                    <span class="badge badge-success">成功</span>
                                @elseif (in_array($attempt->result, ['bad_credentials', 'mfa_failed'], true))
                                    <span class="badge badge-danger">失败</span>
                                @else
                                    <span class="badge badge-warning">{{ $attempt->result === 'locked' ? '锁定' : '阻止' }}</span>
                                @endif
                            </td>
                            <td class="hidden font-mono text-[11.5px] lg:table-cell">{{ $attempt->user_id ? '#' . $attempt->user_id : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-10 text-center text-[13px] text-muted">没有符合条件的登录记录。</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif
    </div>
</div>

@php $page = $tab === 'audit' ? $logs : $attempts; @endphp
@if ($page && $page->hasPages())
    <div class="mt-4 flex items-center justify-between">
        <span class="font-mono text-[11px] tracking-[0.04em] text-faint">PAGE {{ $page->currentPage() }} / {{ $page->lastPage() }}</span>
        <div class="flex gap-2">
            @if ($page->previousPageUrl())
                <a href="{{ $page->previousPageUrl() }}&tab={{ $tab }}" class="btn btn-secondary btn-sm">上一页</a>
            @endif
            @if ($page->nextPageUrl())
                <a href="{{ $page->nextPageUrl() }}&tab={{ $tab }}" class="btn btn-secondary btn-sm">下一页</a>
            @endif
        </div>
    </div>
@endif
@endsection
