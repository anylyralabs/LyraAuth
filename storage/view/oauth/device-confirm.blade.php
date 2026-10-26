@extends('layouts.auth')

@section('title', '确认设备登录')

@section('content')
<div class="mb-6">
    <p class="font-mono text-[10.5px] tracking-[0.08em] text-faint">设备代码</p>
    <p class="mt-1 font-mono text-[17px] tracking-[0.18em] text-ink">{{ $userCode }}</p>
</div>

<div class="panel">
    <div class="p-5">
        <div class="flex items-start gap-3.5">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[2px] font-mono text-[14px] font-medium"
                  style="background-color: {{ $client->logo_color }}; color: {{ $client->logoInk() }}">
                {{ $client->initial() }}
            </span>
            <div class="min-w-0 flex-1">
                <h1 class="text-[15px] font-semibold leading-[1.4] tracking-[-0.015em] text-ink">{{ $client->name }} 请求在设备上登录</h1>
                <p class="mt-1 text-[12.5px] leading-[1.6] text-muted">
                    确认后，该设备将以 <span class="font-mono text-ink-soft">{{ $user->email }}</span> 的身份访问。
                </p>
            </div>
        </div>

        @if ($scopes !== [])
            <div class="mt-4 border-t border-line pt-3.5">
                <p class="font-mono text-[10.5px] tracking-[0.08em] text-faint">将获得</p>
                <ul class="mt-2">
                    @foreach ($scopes as $identifier => $name)
                        <li class="flex items-baseline justify-between gap-3 border-b border-line py-1.5 last:border-b-0">
                            <span class="text-[12.5px] text-ink-soft">{{ $name }}</span>
                            <span class="font-mono text-[10.5px] text-faint">{{ $identifier }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="mt-4 text-[11.5px] leading-[1.65] text-muted">
            如果这不是你发起的请求，请选择拒绝。任何人都可能看到代码，但只有你能批准。
        </p>
    </div>

    <div class="flex flex-col-reverse gap-2 border-t border-line bg-sunk px-5 py-3.5 sm:flex-row sm:justify-end">
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
