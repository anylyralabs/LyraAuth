@extends('layouts.auth')

@section('title', '确认设备登录')

@section('content')
<div class="mb-7">
    <p class="text-xs font-medium uppercase tracking-wider text-muted">设备代码</p>
    <p class="mt-1 font-mono text-lg tracking-[0.2em] text-ink">{{ $userCode }}</p>
</div>

<div class="panel">
    <div class="panel-body">
        <div class="flex items-start gap-3.5">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-[8px] text-base font-semibold text-white"
                  style="background-color: {{ $client->logo_color }}">
                {{ $client->initial() }}
            </span>
            <div class="min-w-0 flex-1">
                <h1 class="text-base font-semibold leading-6 text-ink">{{ $client->name }} 请求在设备上登录</h1>
                <p class="mt-1 text-[13px] leading-5 text-muted">
                    确认后，该设备将以 <span class="font-medium text-ink-soft">{{ $user->email }}</span> 的身份访问。
                </p>
            </div>
        </div>

        @if ($scopes !== [])
            <div class="mt-5 border-t border-line pt-4">
                <p class="text-xs font-medium uppercase tracking-wider text-muted">将获得</p>
                <ul class="mt-2 space-y-1.5">
                    @foreach ($scopes as $identifier => $name)
                        <li class="flex items-center gap-2 text-[13px] text-ink-soft">
                            <span class="h-1 w-1 rounded-full bg-line-strong"></span>
                            {{ $name }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="mt-5 text-xs leading-5 text-muted">
            如果这不是你发起的请求，请选择拒绝。任何人都可能看到代码，但只有你能批准。
        </p>
    </div>

    <div class="flex flex-col-reverse gap-2 border-t border-line bg-[#fafbfb] px-5 py-4 sm:flex-row sm:justify-end">
        <form method="POST" action="/device/decision" class="sm:order-1">
            @csrf
            <input type="hidden" name="user_code" value="{{ $userCode }}">
            <input type="hidden" name="decision" value="deny">
            <button type="submit" class="btn btn-secondary btn-block sm:w-auto">拒绝</button>
        </form>
        <form method="POST" action="/device/decision" class="sm:order-2">
            @csrf
            <input type="hidden" name="user_code" value="{{ $userCode }}">
            <input type="hidden" name="decision" value="allow">
            <button type="submit" class="btn btn-primary btn-block sm:w-auto">批准登录</button>
        </form>
    </div>
</div>
@endsection
