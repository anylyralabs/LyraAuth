@extends('layouts.shell')

@section('content')
<div class="grid gap-5 lg:grid-cols-3">
    {{-- 左：账号信息 --}}
    <div class="space-y-5 lg:col-span-1">
        <section class="panel">
            <div class="panel-body">
                <div class="flex items-center gap-3">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full text-base font-semibold text-white"
                          style="background-color: {{ $user->avatar_color }}">
                        {{ $user->initials() }}
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-base font-semibold text-ink">{{ $user->display_name }}</p>
                        <p class="truncate text-[13px] text-muted">{{ $user->username }}</p>
                    </div>
                </div>

                <dl class="mt-5 space-y-2.5 text-[13px]">
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted">邮箱</dt>
                        <dd class="truncate text-right text-ink">{{ $user->email }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted">邮箱验证</dt>
                        <dd class="text-right {{ $user->isEmailVerified() ? 'text-success' : 'text-warning' }}">
                            {{ $user->isEmailVerified() ? '已验证' : '未验证' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted">用户 ID</dt>
                        <dd class="truncate text-right font-mono text-[11px] text-ink-soft">{{ $user->uuid }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted">注册时间</dt>
                        <dd class="text-right text-ink">{{ $user->created_at?->format('Y-m-d H:i') }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted">最近登录</dt>
                        <dd class="text-right text-ink">{{ $user->last_login_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-muted">最近 IP</dt>
                        <dd class="text-right font-mono text-[12px] text-ink-soft">{{ $user->last_login_ip ?? '—' }}</dd>
                    </div>
                </dl>

                <div class="mt-4 flex flex-wrap gap-1.5 border-t border-line pt-4">
                    @if ($user->isAdmin())
                        <span class="badge badge-brand">管理员</span>
                    @endif
                    @if ($totpEnabled)
                        <span class="badge badge-success">TOTP</span>
                    @endif
                    @if ($passkeyCount > 0)
                        <span class="badge badge-success">{{ $passkeyCount }} 个 Passkey</span>
                    @endif
                    @if ($user->mfa_enforced)
                        <span class="badge badge-warning">强制双因素</span>
                    @endif
                </div>
            </div>
        </section>

        {{-- 状态 --}}
        <section class="panel">
            <div class="panel-header"><h2 class="text-sm font-semibold text-ink">账号状态</h2></div>
            <form method="POST" action="/admin/users/{{ $user->id }}/status" class="panel-body space-y-3">
                @csrf
                <p class="text-[13px] text-muted">
                    当前：<span class="font-medium text-ink">
                        {{ $user->status === 'active' ? '正常' : ($user->status === 'pending' ? '待验证' : '已停用') }}
                    </span>
                </p>
                @if ($user->id !== $currentUser->id)
                    <div class="flex gap-2">
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
                @else
                    <p class="text-xs text-muted">不能在此修改自己的账号状态。</p>
                @endif
            </form>
        </section>

        {{-- 权限 --}}
        <section class="panel">
            <div class="panel-header"><h2 class="text-sm font-semibold text-ink">权限与策略</h2></div>
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
                        <input type="checkbox" name="mfa_enforced" value="1" class="mt-0.5" {{ $user->mfa_enforced ? 'checked' : '' }}>
                        <span>
                            <span class="font-medium text-ink">强制启用双因素</span>
                            <span class="block text-xs text-muted">该账号下次登录时会被要求绑定验证器。</span>
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
    </div>

    {{-- 右：关联数据 --}}
    <div class="space-y-5 lg:col-span-2">
        <section class="panel">
            <div class="panel-header">
                <h2 class="text-sm font-semibold text-ink">最近会话</h2>
                <p class="mt-1 text-[13px] text-muted">仅显示最近 10 条记录。</p>
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
                                <td class="hidden font-mono text-xs sm:table-cell">{{ $session->ip_address ?: '—' }}</td>
                                <td class="text-xs">{{ $session->last_activity_at?->format('m-d H:i') ?? '—' }}</td>
                                <td>
                                    @if ($session->isActive())
                                        <span class="badge badge-success">活跃</span>
                                    @else
                                        <span class="badge badge-neutral">已结束</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-8 text-center text-sm text-muted">暂无会话记录。</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header"><h2 class="text-sm font-semibold text-ink">所属组织</h2></div>
            <div class="panel-body">
                @forelse ($organizations as $organization)
                    <a href="/organizations/{{ $organization->slug }}" class="flex items-center justify-between gap-3 border-b border-line py-2.5 last:border-b-0 first:pt-0 last:pb-0">
                        <span class="text-[13px] text-ink">{{ $organization->name }}</span>
                        <span class="text-xs text-muted">{{ $organization->slug }}</span>
                    </a>
                @empty
                    <p class="text-[13px] text-muted">该账号不属于任何组织。</p>
                @endforelse
            </div>
        </section>

        <section class="panel">
            <div class="panel-header"><h2 class="text-sm font-semibold text-ink">拥有的应用</h2></div>
            <div class="panel-body">
                @forelse ($clients as $client)
                    <div class="flex items-center justify-between gap-3 border-b border-line py-2.5 last:border-b-0 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <p class="truncate text-[13px] text-ink">{{ $client->name }}</p>
                            <p class="truncate font-mono text-[11px] text-muted">{{ $client->client_id }}</p>
                        </div>
                        <a href="/admin/clients/{{ $client->id }}/edit" class="btn btn-ghost btn-sm shrink-0">编辑</a>
                    </div>
                @empty
                    <p class="text-[13px] text-muted">该账号没有创建应用。</p>
                @endforelse
            </div>
        </section>

        <a href="/admin/users" class="btn btn-secondary btn-sm">返回用户列表</a>
    </div>
</div>
@endsection
