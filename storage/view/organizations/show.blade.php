@extends('layouts.shell')

@section('content')
<div class="mb-4">
    <a href="/organizations" class="text-xs text-muted hover:text-ink">← 返回组织列表</a>
</div>

<div class="panel overflow-hidden">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="w-[44%]">成员</th>
                    <th class="w-[20%]">角色</th>
                    <th class="hidden w-[20%] sm:table-cell">加入时间</th>
                    <th class="w-[16%] text-right">操作</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($members as $member)
                    @php $memberUser = $memberUsers[$member->user_id] ?? null; @endphp
                    <tr>
                        <td>
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold text-white"
                                      style="background-color: {{ $memberUser->avatar_color ?? '#2F4D5E' }}">
                                    {{ $memberUser?->initials() ?? '·' }}
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate text-[13px] text-ink">{{ $memberUser?->display_name ?? '已删除用户' }}</p>
                                    <p class="truncate text-xs text-muted">{{ $memberUser?->email }}</p>
                                </div>
                            </div>
                        </td>
                        <td>
                            @if ($member->role === 'owner')
                                <span class="badge badge-brand">拥有者</span>
                            @elseif ($member->role === 'admin')
                                <span class="badge badge-neutral">管理员</span>
                            @else
                                <span class="text-xs text-muted">成员</span>
                            @endif
                        </td>
                        <td class="hidden text-xs sm:table-cell">{{ $member->joined_at?->format('Y-m-d') ?? '—' }}</td>
                        <td class="text-right">
                            @if ($canManage && $member->role !== 'owner')
                                <details class="relative inline-block text-left">
                                    <summary class="btn btn-ghost btn-sm cursor-pointer list-none">管理</summary>
                                    <div class="panel absolute right-0 z-30 mt-1 w-40 p-1.5 text-left">
                                        <form method="POST" action="/organizations/{{ $organization->slug }}/members/{{ $member->id }}/role">
                                            @csrf
                                            <input type="hidden" name="role" value="{{ $member->role === 'admin' ? 'member' : 'admin' }}">
                                            <button type="submit" class="block w-full rounded-[5px] px-2.5 py-1.5 text-left text-[13px] text-ink-soft hover:bg-[#f1f3f5]">
                                                {{ $member->role === 'admin' ? '降为成员' : '设为管理员' }}
                                            </button>
                                        </form>
                                        <form method="POST" action="/organizations/{{ $organization->slug }}/members/{{ $member->id }}/remove"
                                              data-confirm="确定将该成员移出组织？">
                                            @csrf
                                            <button type="submit" class="block w-full rounded-[5px] px-2.5 py-1.5 text-left text-[13px] text-danger hover:bg-danger-soft">移出组织</button>
                                        </form>
                                    </div>
                                </details>
                            @else
                                <span class="text-xs text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

@if ($canManage)
    <section class="panel mt-5">
        <div class="panel-header">
            <h2 class="text-sm font-semibold text-ink">添加成员</h2>
            <p class="mt-1 text-[13px] text-muted">对方必须先拥有 LyraAuth 账号。</p>
        </div>
        <form method="POST" action="/organizations/{{ $organization->slug }}/members" class="panel-body flex flex-wrap items-end gap-3">
            @csrf
            <div class="min-w-56 flex-1">
                <label for="email" class="field-label">邮箱</label>
                <input id="email" name="email" type="email" class="field-input" placeholder="member@example.com" required>
            </div>
            <div>
                <label for="role" class="field-label">角色</label>
                <select id="role" name="role" class="field-input w-32">
                    <option value="member">成员</option>
                    <option value="admin">管理员</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">添加</button>
        </form>
    </section>
@endif
@endsection
