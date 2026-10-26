@extends('layouts.shell')

@section('content')
<div class="space-y-5">
    <section class="panel">
        <div class="panel-header">
            <h2 class="text-sm font-semibold text-ink">通用</h2>
            <p class="mt-1 text-[13px] text-muted">站点名称与注册策略。</p>
        </div>
        <form method="POST" action="/admin/settings/general" class="panel-body space-y-4">
            @csrf
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="site_name" class="field-label">站点名称</label>
                    <input id="site_name" name="site_name" type="text" value="{{ $values['site_name'] }}" class="field-input">
                </div>
                <div>
                    <label for="support_email" class="field-label">支持邮箱</label>
                    <input id="support_email" name="support_email" type="email" value="{{ $values['support_email'] }}"
                           class="field-input" placeholder="support@example.com">
                </div>
            </div>

            <div class="space-y-2.5 border-t border-line pt-4">
                <label class="flex items-start gap-2.5 text-[13px]">
                    <input type="checkbox" name="registration_enabled" value="1" class="mt-0.5"
                           {{ $values['registration_enabled'] ? 'checked' : '' }}>
                    <span>
                        <span class="font-medium text-ink">开放自助注册</span>
                        <span class="block text-xs text-muted">关闭后只能由管理员创建账号。</span>
                    </span>
                </label>
                <label class="flex items-start gap-2.5 text-[13px]">
                    <input type="checkbox" name="require_email_verification" value="1" class="mt-0.5"
                           {{ $values['require_email_verification'] ? 'checked' : '' }}>
                    <span>
                        <span class="font-medium text-ink">注册后需要验证邮箱</span>
                        <span class="block text-xs text-muted">未验证的账号无法登录。</span>
                    </span>
                </label>
                <label class="flex items-start gap-2.5 text-[13px]">
                    <input type="checkbox" name="restrict_client_creation" value="1" class="mt-0.5"
                           {{ $values['restrict_client_creation'] ? 'checked' : '' }}>
                    <span>
                        <span class="font-medium text-ink">仅管理员可创建应用</span>
                        <span class="block text-xs text-muted">关闭时普通用户也可以创建 OAuth 客户端。</span>
                    </span>
                </label>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="btn btn-primary">保存通用设置</button>
            </div>
        </form>
    </section>

    <section id="mail" class="panel">
        <div class="panel-header">
            <h2 class="text-sm font-semibold text-ink">邮件</h2>
            <p class="mt-1 text-[13px] text-muted">
                开发环境建议使用 log 驱动，邮件内容写入 <code class="rounded bg-[#f1f3f5] px-1 py-0.5 text-[12px]">runtime/logs/mail.log</code>。
            </p>
        </div>
        <form method="POST" action="/admin/settings/mail" class="panel-body space-y-4">
            @csrf
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="mail_driver" class="field-label">驱动</label>
                    <select id="mail_driver" name="mail_driver" class="field-input">
                        <option value="log" {{ $values['mail_driver'] === 'log' ? 'selected' : '' }}>log（写入文件）</option>
                        <option value="smtp" {{ $values['mail_driver'] === 'smtp' ? 'selected' : '' }}>smtp</option>
                    </select>
                </div>
                <div>
                    <label for="mail_host" class="field-label">SMTP 主机</label>
                    <input id="mail_host" name="mail_host" type="text" value="{{ $values['mail_host'] }}" class="field-input" placeholder="smtp.example.com">
                </div>
                <div>
                    <label for="mail_port" class="field-label">端口</label>
                    <input id="mail_port" name="mail_port" type="number" value="{{ $values['mail_port'] }}" class="field-input">
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="mail_username" class="field-label">用户名</label>
                    <input id="mail_username" name="mail_username" type="text" value="{{ $values['mail_username'] }}" class="field-input" autocomplete="off">
                    <p class="field-hint">OAuth2 模式下会自动使用授权账号，可留空。</p>
                </div>
                <div>
                    <label for="mail_password" class="field-label">密码</label>
                    <input id="mail_password" name="mail_password" type="password" class="field-input"
                           autocomplete="new-password"
                           placeholder="{{ $mailPasswordSet ? '已设置，留空表示不修改' : '未设置' }}">
                    <p class="field-hint">OAuth2 模式下不需要密码。以 APP_KEY 加密存储。</p>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="mail_auth_method" class="field-label">认证方式</label>
                    <select id="mail_auth_method" name="mail_auth_method" class="field-input">
                        <option value="login" {{ $values['mail_auth_method'] === 'login' ? 'selected' : '' }}>用户名 + 密码</option>
                        <option value="xoauth2" {{ $values['mail_auth_method'] === 'xoauth2' ? 'selected' : '' }}>微软 OAuth2（XOAUTH2）</option>
                    </select>
                    <p class="field-hint">Outlook 个人邮箱已关闭密码认证，需选 OAuth2。</p>
                </div>
                <div>
                    <label for="mail_encryption" class="field-label">加密方式</label>
                    <select id="mail_encryption" name="mail_encryption" class="field-input">
                        <option value="tls" {{ $values['mail_encryption'] === 'tls' ? 'selected' : '' }}>STARTTLS（推荐，587）</option>
                        <option value="ssl" {{ $values['mail_encryption'] === 'ssl' ? 'selected' : '' }}>SSL/TLS（465）</option>
                        <option value="" {{ $values['mail_encryption'] === '' ? 'selected' : '' }}>不加密（仅内网）</option>
                    </select>
                </div>
                <div>
                    <label for="mail_from_name" class="field-label">发件人名称</label>
                    <input id="mail_from_name" name="mail_from_name" type="text" value="{{ $values['mail_from_name'] }}" class="field-input">
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="mail_from_address" class="field-label">发件地址</label>
                    <input id="mail_from_address" name="mail_from_address" type="email" value="{{ $values['mail_from_address'] }}" class="field-input">
                </div>
            </div>

            <label class="flex items-start gap-2.5 border-t border-line pt-4 text-[13px]">
                <input type="checkbox" name="mail_verify_peer" value="1" class="mt-0.5" {{ $values['mail_verify_peer'] ? 'checked' : '' }}>
                <span>
                    <span class="font-medium text-ink">校验服务器证书</span>
                    <span class="block text-xs text-muted">生产环境请保持开启；自签证书的内网服务器可临时关闭。</span>
                </span>
            </label>

            <div class="flex justify-end">
                <button type="submit" class="btn btn-primary">保存邮件设置</button>
            </div>
        </form>

        <div class="border-t border-line px-4 py-4">
            <p class="text-[13px] font-medium text-ink">发送测试邮件</p>
            <p class="mt-1 text-xs text-muted">会使用当前已保存的配置真实投递一封邮件。</p>
            <form method="POST" action="/admin/settings/mail/test" class="mt-3 flex flex-wrap items-end gap-2">
                @csrf
                <div class="min-w-56 flex-1">
                    <label for="test_to" class="field-label">收件人</label>
                    <input id="test_to" name="to" type="email" class="field-input" placeholder="you@example.com" required>
                </div>
                <button type="submit" class="btn btn-secondary">发送测试</button>
            </form>
            <p class="mt-2 text-xs text-muted">
                失败原因写入 <code class="rounded bg-[#f1f3f5] px-1 py-0.5 text-[11px]">runtime/logs/hyperf.log</code>，
                也可用命令行：<code class="rounded bg-[#f1f3f5] px-1 py-0.5 text-[11px]">php bin/hyperf.php mail:test --to=...</code>
            </p>
        </div>
    </section>

    {{-- 微软账号 OAuth2 授权 --}}
    <section id="mail-oauth" class="panel">
        <div class="panel-header flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-sm font-semibold text-ink">微软账号授权（OAuth2）</h2>
                <p class="mt-1 text-[13px] text-muted">
                    Outlook 个人邮箱已关闭密码认证，改用 OAuth2 令牌发信。
                </p>
            </div>
            @if ($oauth['authorized'])
                <span class="badge badge-success">已授权</span>
            @elseif ($oauth['configured'])
                <span class="badge badge-warning">待授权</span>
            @else
                <span class="badge badge-neutral">未配置</span>
            @endif
        </div>

        <div class="panel-body space-y-5">
            {{-- 当前授权状态 --}}
            @if ($oauth['authorized'])
                <div class="rounded-[7px] border border-success-line bg-success-soft px-3.5 py-3">
                    <p class="text-[13px] font-medium text-ink">已授权账号</p>
                    <p class="mt-0.5 font-mono text-[13px] text-ink-soft">{{ $oauth['account'] ?: '（未能读取账号邮箱）' }}</p>
                    @if ($oauth['authorized_at'])
                        <p class="mt-1 text-xs text-muted">授权时间 {{ $oauth['authorized_at'] }}</p>
                    @endif
                    <form method="POST" action="/admin/settings/mail/oauth/revoke" class="mt-2.5"
                          data-confirm="撤销后需要重新授权才能发信，确定继续？">
                        @csrf
                        <button type="submit" class="btn btn-secondary btn-sm">撤销授权</button>
                    </form>
                </div>
            @endif

            {{-- 第一步：应用凭据 --}}
            <div>
                <p class="text-[13px] font-medium text-ink">1. 填写 Azure 应用凭据</p>
                <p class="mt-1 text-xs leading-5 text-muted">
                    在 <a href="https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade"
                          target="_blank" rel="noopener noreferrer" class="text-brand underline decoration-brand-line underline-offset-2">Azure 门户 → 应用注册</a>
                    新建应用，账户类型选「个人 Microsoft 账户」，然后：
                </p>
                <ol class="mt-2 space-y-1 pl-4 text-xs leading-5 text-muted" style="list-style: decimal">
                    <li>「身份验证」里添加平台 → Web，重定向 URI 填下方地址；</li>
                    <li>「证书和密码」里新建客户端密码，复制<b class="text-ink-soft">值</b>（不是 ID）；</li>
                    <li>「API 权限」→ Microsoft Graph → 委托的权限，添加 <code class="rounded bg-[#f1f3f5] px-1 py-0.5 text-[11px]">SMTP.Send</code> 与 <code class="rounded bg-[#f1f3f5] px-1 py-0.5 text-[11px]">offline_access</code>。</li>
                </ol>

                <div class="mt-3 rounded-[6px] border border-line bg-[#fafbfb] px-3 py-2">
                    <p class="text-[11px] text-muted">重定向 URI（必须完全一致）</p>
                    <div class="mt-1 flex items-center gap-2">
                        <code class="flex-1 break-all font-mono text-[12px] text-ink">{{ $oauth['redirect_uri'] }}</code>
                        <button type="button" class="btn btn-secondary btn-sm shrink-0"
                                data-copy="{{ $oauth['redirect_uri'] }}" data-copied-label="已复制">复制</button>
                    </div>
                </div>

                <form method="POST" action="/admin/settings/mail/oauth/application" class="mt-3 space-y-3">
                    @csrf
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="oauth_client_id" class="field-label">应用程序（客户端）ID</label>
                            <input id="oauth_client_id" name="client_id" type="text"
                                   value="{{ $oauth['client_id'] }}"
                                   class="field-input font-mono text-[13px]"
                                   placeholder="00000000-0000-0000-0000-000000000000" required>
                        </div>
                        <div>
                            <label for="oauth_client_secret" class="field-label">客户端密码值</label>
                            <input id="oauth_client_secret" name="client_secret" type="password"
                                   class="field-input" autocomplete="new-password"
                                   placeholder="{{ $oauth['secret_set'] ? '已设置，留空表示不修改' : '粘贴密码的「值」列' }}">
                            <p class="field-hint">以 APP_KEY 加密存储。</p>
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="oauth_tenant" class="field-label">租户</label>
                            <input id="oauth_tenant" name="tenant" type="text" value="{{ $oauth['tenant'] }}"
                                   class="field-input font-mono text-[13px]" placeholder="consumers">
                            <p class="field-hint">个人账户用 <code>consumers</code>；工作/学校账户用租户 ID 或 <code>organizations</code>。</p>
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="btn btn-primary btn-sm">保存应用凭据</button>
                    </div>
                </form>
            </div>

            {{-- 第二步：手动授权 --}}
            <div class="border-t border-line pt-4">
                <p class="text-[13px] font-medium text-ink">2. 登录微软账号并同意</p>
                <p class="mt-1 text-xs leading-5 text-muted">
                    点击后跳转到微软登录页，登录并同意授权即可，回调后自动保存令牌。
                </p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <a href="/admin/settings/mail/oauth/authorize"
                       class="btn btn-primary btn-sm {{ $oauth['configured'] ? '' : 'pointer-events-none opacity-50' }}">
                        {{ $oauth['authorized'] ? '重新授权' : '开始授权' }}
                    </a>
                    @unless ($oauth['configured'])
                        <span class="text-xs text-muted">请先保存 Client ID</span>
                    @endunless
                </div>
            </div>

            {{-- 提示 --}}
            <div class="border-t border-line pt-4">
                <p class="text-xs leading-5 text-muted">
                    若授权成功但发信仍报 <code class="rounded bg-[#f1f3f5] px-1 py-0.5 text-[11px]">535 5.7.139 SmtpClientAuthentication is disabled</code>，
                    说明微软在服务端关闭了该邮箱的 SMTP 提交权限。个人账户没有管理开关，
                    需要改用 <a href="https://resend.com" target="_blank" rel="noopener noreferrer" class="text-brand underline decoration-brand-line underline-offset-2">Resend</a> 等专业邮件服务。
                </p>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <h2 class="text-sm font-semibold text-ink">签名密钥</h2>
            <p class="mt-1 text-[13px] text-muted">密钥存放于 storage/keys/oauth/，可通过命令行轮换。</p>
        </div>
        <div class="panel-body">
            <p class="text-[13px] leading-6 text-muted">
                当前 JWKS 会同时暴露最近 3 把公钥，因此轮换后已签发的令牌在过期前仍可验证。
            </p>
            <code class="mt-3 block rounded-[6px] border border-line bg-[#fafbfb] px-3 py-2 font-mono text-[12px] text-ink">
                php bin/hyperf.php oauth:keys:rotate
            </code>
        </div>
    </section>
</div>
@endsection
