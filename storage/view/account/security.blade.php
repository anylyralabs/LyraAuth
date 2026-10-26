@extends('layouts.shell')

@section('content')

{{-- 双因素 --}}
<section id="totp" class="panel">
    <div class="panel-header">
        <div>
            <h2 class="panel-title">双因素认证（TOTP）</h2>
            <p class="panel-note">使用验证器 App 生成一次性验证码，登录时需要额外输入。</p>
        </div>
        @if ($summary['totp'])
            <span class="badge badge-success">已启用</span>
        @else
            <span class="badge badge-neutral">未启用</span>
        @endif
    </div>

    <div class="panel-body">
        @if ($setup)
            <div class="grid gap-6 sm:grid-cols-[176px_minmax(0,1fr)]">
                <div class="border border-line bg-white p-2">
                    <img src="data:image/svg+xml;base64,{{ base64_encode(\App\Support\QrCode::svg($setup['uri'])) }}"
                         alt="TOTP 绑定二维码" width="176" height="176" class="h-auto w-full">
                </div>
                <div>
                    <ol class="space-y-1.5 text-[13px] leading-[1.65] text-ink-soft">
                        <li class="flex gap-2"><span class="font-mono text-[11px] text-brand">1</span><span>用验证器 App 扫描左侧二维码</span></li>
                        <li class="flex gap-2"><span class="font-mono text-[11px] text-brand">2</span><span>无法扫码时，手动输入下方密钥</span></li>
                        <li class="flex gap-2"><span class="font-mono text-[11px] text-brand">3</span><span>输入应用显示的 6 位验证码完成绑定</span></li>
                    </ol>

                    <div class="mt-4 flex items-center gap-2">
                        <code class="flex-1 select-all break-all border border-line bg-sunk px-2.5 py-2 font-mono text-[12.5px] text-ink"
                              data-secret="{{ $setup['secret'] }}">{{ $setup['secret'] }}</code>
                        <button type="button" class="btn btn-secondary btn-sm shrink-0"
                                data-copy="{{ $setup['secret'] }}" data-copied-label="已复制">复制</button>
                    </div>

                    <form method="POST" action="/account/totp/confirm" class="mt-4 flex flex-wrap items-end gap-2">
                        @csrf
                        <div class="flex-1">
                            <label for="code" class="field-label">验证码</label>
                            <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*"
                                   maxlength="6" autocomplete="one-time-code" placeholder="000000"
                                   class="field-input font-mono tracking-[0.25em]" required autofocus>
                        </div>
                        <button type="submit" class="btn btn-primary">完成绑定</button>
                    </form>
                </div>
            </div>
        @elseif ($summary['totp'])
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-[13px] text-muted">
                    已启用{{ $totp?->confirmed_at ? '，启用时间 ' . $totp->confirmed_at->format('Y-m-d') : '' }}。
                    关闭后登录将不再要求验证码。
                </p>
                <details class="w-full sm:w-auto">
                    <summary class="btn btn-secondary btn-sm cursor-pointer list-none">关闭双因素</summary>
                    <form method="POST" action="/account/totp/disable" class="mt-3 flex flex-wrap items-end gap-2">
                        @csrf
                        <div>
                            <label for="disable-password" class="field-label">输入密码以确认</label>
                            <input id="disable-password" name="password" type="password" class="field-input" required>
                        </div>
                        <button type="submit" class="btn btn-danger">确认关闭</button>
                    </form>
                </details>
            </div>
        @else
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-[13px] text-muted">尚未启用。启用后即使密码泄露，攻击者也无法直接登录。</p>
                <form method="POST" action="/account/totp/setup">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm">开始绑定</button>
                </form>
            </div>
        @endif
    </div>
</section>

{{-- 恢复码 --}}
@if ($summary['totp'])
    <section id="recovery" class="panel mt-4">
        <div class="panel-header">
            <div>
                <h2 class="panel-title">恢复码</h2>
                <p class="panel-note">验证器丢失时可用恢复码登录，每个只能使用一次。</p>
            </div>
        </div>
        <div class="panel-body">
            @if (! empty($newRecoveryCodes))
                <div class="alert alert-warning mb-4">
                    <span>请立即保存这些恢复码。离开本页后将无法再次查看。</span>
                </div>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach ($newRecoveryCodes as $code)
                        <code class="select-all border border-line bg-sunk px-2 py-1.5 text-center font-mono text-[12.5px] tracking-wide text-ink">{{ $code }}</code>
                    @endforeach
                </div>
                <p class="mt-2 text-[11.5px] text-muted">点击可全选，便于复制到密码管理器。</p>
                <form method="POST" action="/account/recovery-codes" class="mt-4 flex flex-wrap items-end gap-2 border-t border-line pt-4">
                    @csrf
                    <div>
                        <label for="regen-password" class="field-label">重新生成（需密码）</label>
                        <input id="regen-password" name="password" type="password" class="field-input" required>
                    </div>
                    <button type="submit" class="btn btn-secondary">生成新恢复码</button>
                </form>
            @else
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-[13px] text-muted">
                        当前剩余 <span class="font-mono font-medium text-ink">{{ $recoveryCodes }}</span> 个可用恢复码。
                    </p>
                    <form method="POST" action="/account/recovery-codes" class="flex flex-wrap items-end gap-2">
                        @csrf
                        <div>
                            <label for="recovery-password" class="field-label">输入密码以生成</label>
                            <input id="recovery-password" name="password" type="password" class="field-input" required>
                        </div>
                        <button type="submit" class="btn btn-secondary btn-sm">重新生成</button>
                    </form>
                </div>
            @endif
        </div>
    </section>
@endif

{{-- Passkey --}}
<section class="panel mt-4">
    <div class="panel-header">
        <div>
            <h2 class="panel-title">Passkey</h2>
            <p class="panel-note">使用系统生物识别或安全密钥登录，无需输入密码。</p>
        </div>
        @if ($summary['passkey_count'] > 0)
            <span class="badge badge-success">{{ $summary['passkey_count'] }} 个</span>
        @else
            <span class="badge badge-neutral">未添加</span>
        @endif
    </div>
    <div class="panel-body">
        @if ($passkeys->isNotEmpty())
            <div class="border-t border-line">
                @foreach ($passkeys as $passkey)
                    <div class="flex items-center justify-between gap-3 border-b border-line py-2.5">
                        <div class="min-w-0">
                            <p class="truncate text-[13px] text-ink">{{ $passkey->device_name ?: '未命名密钥' }}</p>
                            <p class="font-mono text-[11px] text-muted">
                                添加 {{ $passkey->created_at?->format('Y-m-d') }}
                                @if ($passkey->last_used_at)
                                    · 最近 {{ \App\Support\View::relativeTime($passkey->last_used_at->getTimestamp()) }}
                                @endif
                            </p>
                        </div>
                        <form method="POST" action="/account/passkeys/{{ $passkey->id }}/delete"
                              data-confirm="确定删除这个 Passkey？删除后将无法用它登录。">
                            @csrf
                            <button type="submit" class="btn btn-ghost btn-sm text-danger">删除</button>
                        </form>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-[13px] text-muted">尚未添加任何 Passkey。</p>
        @endif

        <div class="mt-4 border border-dashed border-line-strong bg-sunk p-3.5">
            <p class="text-[13px] font-medium text-ink">添加 Passkey</p>
            <p class="mt-1 text-[11.5px] leading-[1.6] text-muted">
                浏览器会调用系统的生物识别或安全密钥。此功能需要 HTTPS 或 localhost 环境。
            </p>
            <button type="button" class="btn btn-secondary btn-sm mt-3" data-passkey-register>添加 Passkey</button>
            <p class="mt-2 hidden text-[11.5px] text-muted" data-passkey-status></p>
        </div>
    </div>
</section>

{{-- 登录设备 --}}
<section id="sessions" class="panel mt-4">
    <div class="panel-header">
        <div>
            <h2 class="panel-title">登录设备</h2>
            <p class="panel-note">发现不认识的设备时，请立即退出该设备并修改密码。</p>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="w-[40%]">设备</th>
                    <th class="hidden w-[24%] sm:table-cell">IP 地址</th>
                    <th class="w-[22%]">最近活动</th>
                    <th class="w-[14%] text-right">操作</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sessions as $session)
                    <tr>
                        <td>
                            <span class="text-ink">{{ $session->device_label ?: '未知设备' }}</span>
                            @if ($session->session_id === $currentSessionId)
                                <span class="badge badge-brand ml-2">当前</span>
                            @endif
                        </td>
                        <td class="hidden font-mono text-[12px] sm:table-cell">{{ $session->ip_address ?: '—' }}</td>
                        <td class="text-[12px] text-muted">{{ \App\Support\View::relativeTime($session->last_activity_at?->getTimestamp()) }}</td>
                        <td class="text-right">
                            @if ($session->session_id !== $currentSessionId)
                                <form method="POST" action="/account/sessions/revoke" class="inline">
                                    @csrf
                                    <input type="hidden" name="session_id" value="{{ $session->session_id }}">
                                    <button type="submit" class="btn btn-ghost btn-sm text-danger">退出</button>
                                </form>
                            @else
                                <span class="text-[12px] text-faint">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-2.5">
        <p class="text-[11.5px] text-muted">退出其他设备后，本设备保持登录。</p>
        <form method="POST" action="/account/sessions/revoke-all"
              data-confirm="确定退出所有其他设备？被信任的浏览器也会一并清除。">
            @csrf
            <button type="submit" class="btn btn-secondary btn-sm">退出其他设备</button>
        </form>
    </div>
</section>

{{-- 信任设备 --}}
@if ($trustedDevices->isNotEmpty())
    <section id="trusted" class="panel mt-4">
        <div class="panel-header">
            <div>
                <h2 class="panel-title">受信任的设备</h2>
                <p class="panel-note">这些设备此前选择了「记住此设备」，可跳过双因素验证。</p>
            </div>
        </div>
        <div>
            @foreach ($trustedDevices as $device)
                <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-2.5 last:border-b-0">
                    <div class="min-w-0">
                        <p class="truncate text-[13px] text-ink">{{ $device->device_label ?: '未知设备' }}</p>
                        <p class="font-mono text-[11px] text-muted">有效期至 {{ $device->expires_at?->format('Y-m-d') }}</p>
                    </div>
                    <form method="POST" action="/account/trusted-devices/revoke">
                        @csrf
                        <input type="hidden" name="id" value="{{ $device->id }}">
                        <button type="submit" class="btn btn-ghost btn-sm text-danger">撤销</button>
                    </form>
                </div>
            @endforeach
        </div>
    </section>
@endif

{{-- 修改密码 --}}
<section class="panel mt-4">
    <div class="panel-header">
        <div>
            <h2 class="panel-title">修改密码</h2>
            <p class="panel-note">修改后，其他已登录的设备会自动退出。</p>
        </div>
    </div>
    <form method="POST" action="/account/password" class="panel-body space-y-4">
        @csrf
        <div>
            <label for="current_password" class="field-label">当前密码</label>
            <input id="current_password" name="current_password" type="password"
                   class="field-input" autocomplete="current-password" required>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="new_password" class="field-label">新密码</label>
                <input id="new_password" name="password" type="password"
                       class="field-input" placeholder="至少 10 位，含字母与数字" autocomplete="new-password" required>
            </div>
            <div>
                <label for="new_password_confirmation" class="field-label">确认新密码</label>
                <input id="new_password_confirmation" name="password_confirmation" type="password"
                       class="field-input" autocomplete="new-password" required>
            </div>
        </div>
        <div class="flex justify-end border-t border-line pt-4">
            <button type="submit" class="btn btn-primary">更新密码</button>
        </div>
    </form>
</section>
@endsection
