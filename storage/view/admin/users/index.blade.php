@extends('layouts.shell')

@section('content')
<div class="mb-4 flex flex-wrap items-end justify-between gap-3">
    <form method="GET" action="/admin/users" class="flex flex-wrap items-end gap-2.5">
        <div>
            <label for="q" class="field-label">搜索</label>
            <input id="q" name="q" type="search" value="{{ $keyword }}" class="field-input w-56"
                   placeholder="邮箱、用户名或昵称">
        </div>
        <div>
            <label for="status" class="field-label">状态</label>
            <select id="status" name="status" class="field-input w-32" data-auto-submit>
                <option value="" {{ $status === '' ? 'selected' : '' }}>全部</option>
                <option value="active" {{ $status === 'active' ? 'selected' : '' }}>正常</option>
                <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>待验证</option>
                <option value="suspended" {{ $status === 'suspended' ? 'selected' : '' }}>已停用</option>
            </select>
        </div>
        <button type="submit" class="btn btn-secondary">筛选</button>
        @if ($keyword !== '' || $status !== '')
            <a href="/admin/users" class="btn btn-ghost">清除</a>
        @endif
    </form>
    <p class="font-mono text-[11px] tracking-[0.06em] text-faint">TOTAL {{ $users->total() }}</p>
</div>

<div class="panel overflow-hidden">
    <div class="overflow-x-auto">
        <table class="data-table table-fixed">
            <thead>
                <tr>
                    <th class="w-[38%]">账号</th>
                    <th class="hidden w-[16%] sm:table-cell">角色</th>
                    <th class="hidden w-[16%] md:table-cell">双因素</th>
                    <th class="w-[16%]">状态</th>
                    <th class="w-[14%] text-right">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr>
                        <td>
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[2px] font-mono text-[11px] font-medium"
                                      style="background-color: {{ $user->avatar_color }}; color: {{ $user->avatarInk() }}">
                                    {{ $user->initials() }}
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate text-[13px] font-medium text-ink">
                                        {{ $user->display_name }}
                                        @if ($user->id === $currentUser->id)
                                            <span class="badge badge-brand ml-1">你</span>
                                        @endif
                                    </p>
                                    <p class="truncate font-mono text-[11px] text-muted">{{ $user->email }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="hidden sm:table-cell">
                            @if ($user->isAdmin())
                                <span class="badge badge-brand">管理员</span>
                            @else
                                <span class="text-[12px] text-muted">普通用户</span>
                            @endif
                        </td>
                        <td class="hidden md:table-cell">
                            @if ($user->mfa_enforced)
                                <span class="badge badge-warning">强制</span>
                            @elseif ($user->totp()->exists())
                                <span class="text-[12px] text-muted">已启用</span>
                            @else
                                <span class="text-[12px] text-faint">—</span>
                            @endif
                        </td>
                        <td>
                            @switch($user->status)
                                @case('active')
                                    <span class="badge badge-success">正常</span>
                                    @break
                                @case('pending')
                                    <span class="badge badge-warning">待验证</span>
                                    @break
                                @default
                                    <span class="badge badge-danger">已停用</span>
                            @endswitch
                        </td>
                        <td class="text-right">
                            <a href="/admin/users/{{ $user->id }}" class="btn btn-ghost btn-sm">详情</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="py-10 text-center text-[13px] text-muted">没有符合条件的账号。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($users->hasPages())
    <div class="mt-4 flex items-center justify-between">
        <span class="font-mono text-[11px] tracking-[0.04em] text-faint">PAGE {{ $users->currentPage() }} / {{ $users->lastPage() }}</span>
        <div class="flex gap-2">
            @if ($users->previousPageUrl())
                <a href="{{ $users->previousPageUrl() }}" class="btn btn-secondary btn-sm">上一页</a>
            @endif
            @if ($users->nextPageUrl())
                <a href="{{ $users->nextPageUrl() }}" class="btn btn-secondary btn-sm">下一页</a>
            @endif
        </div>
    </div>
@endif
@endsection
