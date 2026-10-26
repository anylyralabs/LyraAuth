@extends('layouts.shell')

@section('content')
<div class="grid gap-5 lg:grid-cols-[300px_minmax(0,1fr)]">

    {{-- 左：账号信息与控制 --}}
    <div class="space-y-4">
        <section class="panel">
            <div class="panel-body">
                <div class="flex items-center gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-[2px] font-mono text-[15px] font-medium"
                          style="background-color: {{ $user->avatar_color }}; color: {{ $user->avatarInk() }}">
                        {{ $user->initials() }}
                    </span>
                    <div class="min-w-0">
                        <p class="truncate font-mono text-[13px] text-ink">{{ $user->username }}</p>
                        <p class="truncate text-[11.5px] text-muted">{{ $user->display_name }}</p>
                    </div>
                </div>

                <dl class="spec mt-4">
                    <dt>邮箱</dt>
                    <dd class="truncate font-mono text-[12px]">{{ $user->email }}</dd>
                    <dt>邮箱验证</dt>
                    <dd>
                        @if ($user->isEmailVerified())
                            <span class="badge badge-success">已验证</span>
                        @else
                            <span class="badge badge-warning">未验证</span>
                        @endif
                    </dd>
                    <dt>用户 ID</dt>
                    <dd class="font-mono text-[11px] text-ink-soft">{{ $user->uuid }}</dd>
                    <dt>注册时间</dt>
                    <dd class="font-mono text-[12px]">{{ $user->created_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                    <dt>最近登录</dt>
                    <dd class="font-mono text-[12px]">{{ $user->last_login_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                    <dt>最近 IP</dt>
                    <dd class="font-mono text-[12px]">{{ $user->last_login_ip ?? '—' }}</dd>
                </dl>

                <div class="mt-4 flex flex-wrap gap-1.5 border-t border-line pt-3">
                    @if ($user->isAdmin())
                        <span class="badge badge-brand">管理员</span>
                    @endif
                    @if ($totpEnabled)
                        <span class="badge badge-success">TOTP</span>
                    @endif
                    @if ($passkeyCount > 0)
                        <span class="badge badge-neutral">{{ $passkeyCount }} 个 Passkey</span>
                    @endif
                    @if ($user->mfa_enforced)
                        <span class="badge badge-warning">强制双因素</span>
                    @endif
                </div>
            </div>
        </section>

        {{-- 状态 --}}
        <section class="panel">
            <div class="panel-header">
                <h2 class="panel-title">账号状态</h2>
                @if ($user->status === 'active')
                    <span class="badge badge-success">正常</span>
                @elseif ($user->status === 'pending')
                    <span class="badge badge-warning">待验证</span>
                @else
                    <span class="badge badge-danger">已停用</span>
                @endif
            </div>
            <form method="POST" action="/admin/users/{{ $user->id }}/status" class="panel-body space-y-3">
                @csrf
                @if ($user->id !== $currentUser->id)
                    <div class="flex flex-wrap gap-2">
                        @if ($user->status === 'active')
                            <button type="submit" name="status" value="suspended" class="btn btn-danger btn-sm"
                                    data-confirm="停用后该账号将无法登录，且所有会话立即失效。确定继续？">停用账号</button>
                        @else
                            <button type="submit" name="status" value="active" class="btn btn-primary btn-sm">恢复账号</button>
                        @endif
                        <button type="submit" formaction="/admin/users/{{ $user->id }}/revoke-sessions" class="btn btn-secondary btn-sm">
                            退出所有设备
                        </button>
                    </div>
                    <p class="text-[11.5px] leading-[1.6] text-muted">停用会立即吊销该账号的全部会话与信任设备。</p>
                @else
                    <p class="text-[12px] text-muted">不能在此修改自己的账号状态。</p>
                @endif
            </form>
        </section>

        {{-- 权限 --}}
        <section class="panel">
            <div class="panel-header">
                <h2 class="panel-title">权限与策略</h2>
            </div>
            <div class="panel-body space-y-4">
                <form method="POST" action="/admin/users/{{ $user->id }}/role" class="space-y-2">
                    @csrf
                    <label for="platform_role" class="field-label">平台角色</label>
                    <div class="flex gap-2">
                        <select id="platform_role" name="platform_role" class="field-input">
                            <option value="user" {{ ! $user->isAdmin() ? 'selected' : '' }}>普通用户</option>
                            <option value="admin" {{ $user->isAdmin() ? 'selected' : '' }}>管理员</option>
                        </select>
                        <button type="submit" class="btn btn-secondary shrink-0">保存</button>
                    </div>
                </form>

                <form method="POST" action="/admin/users/{{ $user->id }}/mfa-policy" class="space-y-2 border-t border-line pt-4">
                    @csrf
                    <label class="flex items-start gap-2.5 text-[13px]">
                        <input type="checkbox" name="mfa_enforced" value="1" class="mt-0.5 accent-brand" {{ $user->mfa_enforced ? 'checked' : '' }}>
                        <span>
                            <span class="font-medium text-ink">强制启用双因素</span>
                            <span class="block text-[11.5px] leading-[1.6] text-muted">该账号下次登录时会被要求绑定验证器。</span>
                        </span>
                    </label>
                    <button type="submit" class="btn btn-secondary btn-sm">保存策略</button>
                </form>

                <form method="POST" action="/admin/users/{{ $user->id }}/reset-password" class="border-t border-line pt-4"
                      data-confirm="将向该账号邮箱发送重置密码链接，确认继续？">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm">发送重置密码邮件</button>
                </form>
            </div>
        </section>

        <a href="/admin/users" class="btn btn-ghost btn-sm">← 返回用户列表</a>
    </div>

    {{-- 右：关联数据 --}}
    <div class="space-y-4">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2 class="panel-title">最近会话</h2>
                    <p class="panel-note">仅显示最近 10 条记录。</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="w-[38%]">设备</th>
                            <th class="hidden w-[24%] sm:table-cell">IP</th>
                            <th class="w-[22%]">时间</th>
                            <th class="w-[16%]">状态</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sessions as $session)
                            <tr>
                                <td class="text-[13px] text-ink">{{ $session->device_label ?: '未知设备' }}</td>
                                <td class="hidden font-mono text-[12px] sm:table-cell">{{ $session->ip_address ?: '—' }}</td>
                                <td class="font-mono text-[11.5px]">{{ $session->last_activity_at?->format('m-d H:i') ?? '—' }}</td>
                                <td>
                                    @if ($session->isActive())
                                        <span class="badge badge-success">活跃</span>
                                    @else
                                        <span class="badge badge-neutral">已结束</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-8 text-center text-[13px] text-muted">暂无会话记录。</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid gap-4 sm:grid-cols-2">
            <section class="panel">
                <div class="panel-header">
                    <h2 class="panel-title">所属组织</h2>
                    @if ($organizations->isNotEmpty())
                        <span class="font-mono text-[11px] text-faint">{{ $organizations->count() }}</span>
                    @endif
                </div>
                <div class="px-4 py-1">
                    @forelse ($organizations as $organization)
                        <a href="/organizations/{{ $organization->slug }}"
                           class="flex items-center justify-between gap-3 border-b border-line py-2.5 transition-colors last:border-b-0 hover:text-brand">
                            <span class="truncate text-[13px] text-ink">{{ $organization->name }}</span>
                            <span class="shrink-0 font-mono text-[11px] text-faint">{{ $organization->slug }}</span>
                        </a>
                    @empty
                        <p class="py-4 text-[13px] text-muted">该账号不属于任何组织。</p>
                    @endforelse
                </div>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <h2 class="panel-title">拥有的应用</h2>
                    @if ($clients->isNotEmpty())
                        <span class="font-mono text-[11px] text-faint">{{ $clients->count() }}</span>
                    @endif
                </div>
                <div class="px-4 py-1">
                    @forelse ($clients as $client)
                        <div class="flex items-center justify-between gap-3 border-b border-line py-2.5 last:border-b-0">
                            <div class="min-w-0">
                                <p class="truncate text-[13px] text-ink">{{ $client->name }}</p>
                                <p class="truncate font-mono text-[10.5px] text-muted">{{ $client->client_id }}</p>
                            </div>
                            <a href="/admin/clients/{{ $client->id }}/edit" class="btn btn-ghost btn-sm shrink-0">编辑</a>
                        </div>
                    @empty
                        <p class="py-4 text-[13px] text-muted">该账号没有创建应用。</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</div>
@endsection
