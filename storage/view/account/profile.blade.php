@extends('layouts.shell')

@section('content')
<form method="POST" action="/account/profile" class="space-y-5">
    @csrf

    <section class="panel">
        <div class="panel-header">
            <h2 class="text-sm font-semibold text-ink">公开资料</h2>
            <p class="mt-1 text-[13px] text-muted">显示名称会出现在同意页、应用内以及 userinfo 的 name 字段。</p>
        </div>
        <div class="panel-body space-y-4">
            <div class="grid gap-4 sm:grid-cols-[80px_minmax(0,1fr)]">
                <div>
                    <label for="avatar_color" class="field-label">头像颜色</label>
                    <input id="avatar_color" name="avatar_color" type="color" value="{{ $values['avatar_color'] }}"
                           class="h-9 w-full cursor-pointer rounded-[6px] border border-line-strong bg-white p-0.5">
                    <span class="mt-2 flex h-10 w-10 items-center justify-center rounded-full text-sm font-semibold text-white"
                          style="background-color: {{ $values['avatar_color'] }}" aria-hidden="true">
                        {{ $currentUser->initials() }}
                    </span>
                </div>
                <div>
                    <label for="display_name" class="field-label">显示名称</label>
                    <input id="display_name" name="display_name" type="text" value="{{ $values['display_name'] }}"
                           class="field-input {{ isset($errors['display_name']) ? 'border-danger' : '' }}" required>
                    @if (isset($errors['display_name']))
                        <p class="field-error">{{ $errors['display_name'] }}</p>
                    @endif
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="field-label">用户名</label>
                    <input type="text" value="{{ $currentUser->username }}" class="field-input" disabled>
                    <p class="field-hint">用户名创建后不可修改。</p>
                </div>
                <div>
                    <label class="field-label">邮箱</label>
                    <input type="text" value="{{ $currentUser->email }}" class="field-input" disabled>
                    <p class="field-hint">{{ $currentUser->isEmailVerified() ? '已验证' : '尚未验证' }}</p>
                </div>
            </div>

            <div>
                <label for="profile_bio" class="field-label">简介（可选）</label>
                <textarea id="profile_bio" name="profile_bio" rows="3" class="field-input resize-y"
                          maxlength="500" placeholder="一句话介绍自己">{{ $values['profile_bio'] }}</textarea>
                @if (isset($errors['profile_bio']))
                    <p class="field-error">{{ $errors['profile_bio'] }}</p>
                @endif
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="locale" class="field-label">语言</label>
                    <select id="locale" name="locale" class="field-input">
                        <option value="zh_CN" {{ $values['locale'] === 'zh_CN' ? 'selected' : '' }}>简体中文</option>
                        <option value="en" {{ $values['locale'] === 'en' ? 'selected' : '' }}>English</option>
                    </select>
                </div>
                <div>
                    <label for="timezone" class="field-label">时区</label>
                    <select id="timezone" name="timezone" class="field-input">
                        @foreach (['Asia/Shanghai' => '中国标准时间', 'UTC' => 'UTC', 'Asia/Tokyo' => '日本标准时间', 'Europe/London' => '伦敦', 'America/New_York' => '纽约'] as $tz => $label)
                            <option value="{{ $tz }}" {{ $values['timezone'] === $tz ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
        <div class="flex justify-end border-t border-line px-4 py-3">
            <button type="submit" class="btn btn-primary">保存资料</button>
        </div>
    </section>
</form>
@endsection
