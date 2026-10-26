@extends('layouts.shell')

@section('content')
@php
    $values = array_merge([
        'name' => '',
        'description' => '',
        'client_type' => 'confidential',
        'redirect_uris' => '',
        'post_logout_uris' => '',
        'auth_method' => 'client_secret_basic',
        'require_pkce' => '1',
        'is_first_party' => '0',
        'client_credentials' => '0',
        'client_uri' => '',
        'logo_color' => '#2F4D5E',
        'scopes' => [],
    ], $values);
@endphp

@if ($errors !== [])
    <div class="alert alert-error mb-5" role="alert">
        <div>
            <p class="font-medium">请修正以下问题：</p>
            <ul class="mt-1 list-inside list-disc">
                @foreach ($errors as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

<form method="POST" action="{{ $client ? '/admin/clients/' . $client->id : '/admin/clients' }}" class="space-y-5">
    @csrf

    <section class="panel">
        <div class="panel-header">
            <h2 class="text-sm font-semibold text-ink">基本信息</h2>
        </div>
        <div class="panel-body space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="name" class="field-label">应用名称</label>
                    <input id="name" name="name" type="text" value="{{ $values['name'] }}" class="field-input" required>
                </div>
                <div>
                    <label for="logo_color" class="field-label">图标颜色</label>
                    <div class="flex items-center gap-2">
                        <input id="logo_color" name="logo_color" type="color" value="{{ $values['logo_color'] }}"
                               class="h-9 w-12 cursor-pointer rounded-[6px] border border-line-strong bg-white p-0.5">
                        <span class="text-xs text-muted">用于应用图标与同意页</span>
                    </div>
                </div>
            </div>

            <div>
                <label for="description" class="field-label">应用说明</label>
                <textarea id="description" name="description" rows="2" class="field-input resize-y"
                          placeholder="简要描述该应用的用途">{{ $values['description'] }}</textarea>
            </div>

            <div>
                <label for="client_uri" class="field-label">应用主页（可选）</label>
                <input id="client_uri" name="client_uri" type="url" value="{{ $values['client_uri'] }}"
                       class="field-input" placeholder="https://app.example.com">
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <h2 class="text-sm font-semibold text-ink">客户端类型</h2>
            <p class="mt-1 text-[13px] text-muted">公共客户端无法安全保存密钥，必须使用 PKCE。机密客户端可保存密钥。</p>
        </div>
        <div class="panel-body space-y-3">
            @if ($client === null)
                <label class="flex cursor-pointer items-start gap-3 rounded-[7px] border border-line p-3 hover:bg-[#fafbfb]">
                    <input type="radio" name="client_type" value="confidential" class="mt-1"
                           {{ $values['client_type'] === 'confidential' ? 'checked' : '' }}>
                    <div>
                        <p class="text-[13px] font-medium text-ink">机密客户端（服务端应用）</p>
                        <p class="mt-0.5 text-xs leading-5 text-muted">拥有 client_secret，适用于后端渲染的 Web 应用。</p>
                    </div>
                </label>
                <label class="flex cursor-pointer items-start gap-3 rounded-[7px] border border-line p-3 hover:bg-[#fafbfb]">
                    <input type="radio" name="client_type" value="public" class="mt-1"
                           {{ $values['client_type'] === 'public' ? 'checked' : '' }}>
                    <div>
                        <p class="text-[13px] font-medium text-ink">公共客户端（SPA / 移动端 / CLI）</p>
                        <p class="mt-0.5 text-xs leading-5 text-muted">无密钥，强制 PKCE；重定向地址仍会精确校验。</p>
                    </div>
                </label>
            @else
                <p class="text-[13px] text-muted">
                    当前类型：<span class="font-medium text-ink">{{ $client->isPublic() ? '公共客户端' : '机密客户端' }}</span>
                    （创建后不可更改）
                </p>
            @endif
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <h2 class="text-sm font-semibold text-ink">回调地址</h2>
            <p class="mt-1 text-[13px] text-muted">每行一个，必须完全匹配（含端口与路径）。除 localhost 外必须使用 https。</p>
        </div>
        <div class="panel-body space-y-4">
            <div>
                <label for="redirect_uris" class="field-label">重定向 URI</label>
                <textarea id="redirect_uris" name="redirect_uris" rows="3" class="field-input resize-y font-mono text-[13px]"
                          placeholder="https://app.example.com/callback" required>{{ $values['redirect_uris'] }}</textarea>
            </div>
            <div>
                <label for="post_logout_uris" class="field-label">登出后回跳 URI（可选）</label>
                <textarea id="post_logout_uris" name="post_logout_uris" rows="2" class="field-input resize-y font-mono text-[13px]"
                          placeholder="https://app.example.com/">{{ $values['post_logout_uris'] }}</textarea>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <h2 class="text-sm font-semibold text-ink">协议与权限</h2>
        </div>
        <div class="panel-body space-y-4">
            @if (! ($client?->isPublic()))
                <div>
                    <label for="auth_method" class="field-label">令牌端点的认证方式</label>
                    <select id="auth_method" name="auth_method" class="field-input">
                        <option value="client_secret_basic" {{ $values['auth_method'] === 'client_secret_basic' ? 'selected' : '' }}>client_secret_basic（推荐）</option>
                        <option value="client_secret_post" {{ $values['auth_method'] === 'client_secret_post' ? 'selected' : '' }}>client_secret_post</option>
                    </select>
                </div>
            @endif

            <div class="space-y-2.5">
                <label class="flex items-start gap-2.5 text-[13px]">
                    <input type="checkbox" name="require_pkce" value="1" class="mt-0.5"
                           {{ $values['require_pkce'] === '1' ? 'checked' : '' }}>
                    <span>
                        <span class="font-medium text-ink">强制 PKCE</span>
                        <span class="block text-xs text-muted">公共客户端始终强制。机密客户端建议保持开启。</span>
                    </span>
                </label>

                <label class="flex items-start gap-2.5 text-[13px]">
                    <input type="checkbox" name="is_first_party" value="1" class="mt-0.5"
                           {{ $values['is_first_party'] === '1' ? 'checked' : '' }}>
                    <span>
                        <span class="font-medium text-ink">第一方应用</span>
                        <span class="block text-xs text-muted">首次授权也不展示同意页，登录后直接签发授权码。</span>
                    </span>
                </label>

                @if (! ($client?->isPublic()))
                    <label class="flex items-start gap-2.5 text-[13px]">
                        <input type="checkbox" name="client_credentials" value="1" class="mt-0.5"
                               {{ $values['client_credentials'] === '1' ? 'checked' : '' }}>
                        <span>
                            <span class="font-medium text-ink">允许客户端凭证模式</span>
                            <span class="block text-xs text-muted">用于服务间调用，不涉及用户身份。</span>
                        </span>
                    </label>
                @endif
            </div>

            <div class="border-t border-line pt-4">
                <p class="field-label">允许申请的权限范围</p>
                <p class="mb-2 text-xs text-muted">不勾选任何项表示允许全部 scope。</p>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($scopes as $scope)
                        <label class="flex items-start gap-2 rounded-[6px] border border-line px-2.5 py-2 text-[13px] hover:bg-[#fafbfb]">
                            <input type="checkbox" name="scopes[]" value="{{ $scope->identifier }}" class="mt-0.5"
                                   {{ in_array($scope->identifier, (array) $values['scopes'], true) ? 'checked' : '' }}>
                            <span>
                                <span class="font-medium text-ink">{{ $scope->name }}</span>
                                <span class="block font-mono text-[11px] text-muted">{{ $scope->identifier }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <div class="flex items-center justify-end gap-2">
        <a href="/admin/clients" class="btn btn-secondary">取消</a>
        <button type="submit" class="btn btn-primary">{{ $client === null ? '创建应用' : '保存修改' }}</button>
    </div>
</form>
@endsection
