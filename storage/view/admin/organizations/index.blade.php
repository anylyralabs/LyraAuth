@extends('layouts.shell')

@section('content')
<div class="mb-4 flex items-center justify-between gap-3">
    <p class="font-mono text-[11px] tracking-[0.06em] text-faint">TOTAL {{ $organizations->total() }}</p>
</div>

<div class="panel overflow-hidden">
    <div class="overflow-x-auto">
        <table class="data-table table-fixed">
            <thead>
                <tr>
                    <th class="w-[42%]">组织</th>
                    <th class="hidden w-[24%] sm:table-cell">标识</th>
                    <th class="w-[18%]">成员</th>
                    <th class="w-[16%] text-right">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($organizations as $organization)
                    <tr>
                        <td>
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[2px] bg-brand-soft font-mono text-[11px] font-medium text-brand">
                                    {{ mb_strtoupper(mb_substr($organization->name, 0, 1)) }}
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate text-[13px] font-medium text-ink">{{ $organization->name }}</p>
                                    <p class="truncate text-[11.5px] text-muted">{{ $organization->description ?: '—' }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="hidden font-mono text-[11.5px] sm:table-cell">{{ $organization->slug }}</td>
                        <td>
                            <span class="font-mono text-[12px] text-ink-soft">{{ $memberCounts[$organization->id] ?? 0 }}</span>
                            <span class="text-[11.5px] text-muted"> 人</span>
                        </td>
                        <td class="text-right">
                            <a href="/organizations/{{ $organization->slug }}" class="btn btn-ghost btn-sm">查看</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-10 text-center text-[13px] text-muted">还没有组织。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($organizations->hasPages())
    <div class="mt-4 flex items-center justify-between">
        <span class="font-mono text-[11px] tracking-[0.04em] text-faint">PAGE {{ $organizations->currentPage() }} / {{ $organizations->lastPage() }}</span>
        <div class="flex gap-2">
            @if ($organizations->previousPageUrl())
                <a href="{{ $organizations->previousPageUrl() }}" class="btn btn-secondary btn-sm">上一页</a>
            @endif
            @if ($organizations->nextPageUrl())
                <a href="{{ $organizations->nextPageUrl() }}" class="btn btn-secondary btn-sm">下一页</a>
            @endif
        </div>
    </div>
@endif
@endsection
