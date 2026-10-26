@extends('layouts.base')

@section('title', '授权请求')

@section('body')
<div class="flex min-h-screen flex-col">

    <header class="border-b border-line">
        <div class="mx-auto flex h-14 w-full max-w-[640px] items-center justify-between px-5">
            <a href="/">@include('partials.brand', ['size' => 'sm'])</a>
            <span class="font-mono text-[10.5px] tracking-[0.06em] text-faint">AUTHORIZATION</span>
        </div>
    </header>

    <main id="main" class="mx-auto flex w-full max-w-[640px] flex-1 items-start justify-center px-5 py-10 sm:py-14">
        <div class="w-full">
            <div class="panel">
                <div class="p-5">
                    <div class="flex items-start gap-3.5">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[2px] font-mono text-[14px] font-medium"
                              style="background-color: {{ $client?->logo_color ?? '#14171A' }}; color: {{ $client?->logoInk() ?? '#FFFFFF' }}">
                            {{ $client?->initial() ?? '·' }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <h1 class="text-[16px] font-semibold leading-[1.4] tracking-[-0.015em] text-ink">
                                {{ $clientName }} 请求访问你的账号
                            </h1>
                            <p class="mt-1 text-[12.5px] leading-[1.6] text-muted">
                                以 <span class="font-mono text-ink-soft">{{ $user->email }}</span> 的身份授权。
                            </p>
                        </div>
                    </div>

                    @if ($isFirstParty)
                        <p class="mt-4 border border-brand-line bg-brand-soft px-2.5 py-2 text-[11.5px] leading-[1.6] text-brand">
                            这是本站的第一方应用，授权后将直接使用你的登录状态。
                        </p>
                    @endif

                    <div class="mt-5">
                        <p class="font-mono text-[10.5px] tracking-[0.08em] text-faint">该应用将获得</p>
                        <ul class="mt-2.5">
                            @foreach ($scopes as $scope)
                                <li class="flex items-start gap-2.5 border-t border-line py-2.5">
                                    <svg class="mt-[5px] h-3 w-3 shrink-0 text-brand" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                        <path d="M3 8.4l3.2 3.2L13 4.8" stroke="currentColor" stroke-width="2" stroke-linecap="square"/>
                                    </svg>
                                    <div class="min-w-0">
                                        <p class="text-[13px] leading-[1.5] text-ink">{{ $scope['name'] }}</p>
                                        @if (! empty($scope['description']))
                                            <p class="text-[11.5px] leading-[1.6] text-muted">{{ $scope['description'] }}</p>
                                        @endif
                                    </div>
                                    <span class="ml-auto shrink-0 font-mono text-[10.5px] text-faint">{{ $scope['identifier'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    @if ($client?->client_uri)
                        <p class="mt-4 border-t border-line pt-3 text-[11.5px] leading-[1.6] text-muted">
                            应用信息：
                            <a href="{{ $client->client_uri }}" class="text-ink-soft underline decoration-line-strong underline-offset-2"
                               rel="noopener noreferrer nofollow" target="_blank">{{ parse_url($client->client_uri, PHP_URL_HOST) ?: $client->client_uri }}</a>
                        </p>
                    @endif
                </div>

                <div class="flex flex-col-reverse gap-2 border-t border-line bg-sunk px-5 py-3.5 sm:flex-row sm:justify-end">
                    <form method="POST" action="/oauth/authorize" class="sm:order-1">
                        @csrf
                        <input type="hidden" name="decision" value="deny">
                        <button type="submit" class="btn btn-secondary btn-block sm:w-auto">拒绝</button>
                    </form>
                    <form method="POST" action="/oauth/authorize" class="sm:order-2">
                        @csrf
                        <input type="hidden" name="decision" value="allow">
                        <button type="submit" class="btn btn-primary btn-block sm:w-auto">允许访问</button>
                    </form>
                </div>
            </div>

            <p class="mt-4 text-center text-[11.5px] leading-[1.7] text-faint">
                授权后可在「已授权应用」中随时撤销。本页地址不会被应用看到你的密码。
            </p>
        </div>
    </main>

    <footer class="border-t border-line">
        <div class="mx-auto flex w-full max-w-[640px] items-center justify-between px-5 py-3 font-mono text-[10.5px] tracking-[0.04em] text-faint">
            <span>LyraAuth</span>
            <span>OIDC 1.0</span>
        </div>
    </footer>
</div>
@endsection
