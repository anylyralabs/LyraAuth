@extends('layouts.shell')

@section('content')
<div class="panel overflow-hidden">
    <table class="data-table">
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
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[5px] bg-brand-soft text-[11px] font-semibold text-brand">
                                {{ mb_strtoupper(mb_substr($organization->name, 0, 1)) }}
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-[13px] font-medium text-ink">{{ $organization->name }}</p>
                                <p class="truncate text-xs text-muted">{{ $organization->description ?: '—' }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="hidden font-mono text-xs sm:table-cell">{{ $organization->slug }}</td>
                    <td class="text-xs">{{ $memberCounts[$organization->id] ?? 0 }} 人</td>
                    <td class="text-right">
                        <a href="/organizations/{{ $organization->slug }}" class="btn btn-ghost btn-sm">查看</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-10 text-center text-sm text-muted">还没有组织。</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($organizations->hasPages())
    <div class="mt-4 flex items-center justify-between text-xs text-muted">
        <span>第 {{ $organizations->currentPage() }} / {{ $organizations->lastPage() }} 页</span>
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
