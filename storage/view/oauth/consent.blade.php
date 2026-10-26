@extends('layouts.base')

@section('title', '授权请求')

@section('body')
<div class="flex min-h-screen flex-col bg-canvas">
    <header class="border-b border-line bg-surface">
        <div class="mx-auto flex h-14 w-full max-w-2xl items-center justify-between px-5">
            <a href="/">@include('partials.brand', ['size' => 'sm'])</a>
            <span class="text-xs text-muted">安全授权</span>
        </div>
    </header>

    <main id="main" class="mx-auto flex w-full max-w-2xl flex-1 items-start justify-center px-5 py-10 sm:py-14">
        <div class="w-full">
            <div class="panel">
                <div class="panel-body">
                    <div class="flex items-start gap-3.5">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-[8px] text-base font-semibold text-white"
                              style="background-color: {{ $client?->logo_color ?? '#2F4D5E' }}">
                            {{ $client?->initial() ?? '·' }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <h1 class="text-base font-semibold leading-6 text-ink">
                                {{ $clientName }} 请求访问你的账号
                            </h1>
                            <p class="mt-1 text-[13px] leading-5 text-muted">
                                以 <span class="font-medium text-ink-soft">{{ $user->email }}</span> 的身份授权。
                            </p>
                        </div>
                    </div>

                    @if ($isFirstParty)
                        <p class="mt-4 rounded-[6px] border border-brand-line bg-brand-soft px-3 py-2 text-xs leading-5 text-brand">
                            这是本站的第一方应用，授权后将直接使用你的登录状态。
                        </p>
                    @endif

                    <div class="mt-6">
                        <p class="text-xs font-medium uppercase tracking-wider text-muted">该应用将获得</p>
                        <ul class="mt-3 space-y-2.5">
                            @foreach ($scopes as $scope)
                                <li class="flex items-start gap-2.5">
                                    <svg class="mt-1 h-3.5 w-3.5 shrink-0 text-success" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                        <path d="M3 8.4l3.2 3.2L13 4.8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    <div>
                                        <p class="text-[13px] leading-5 text-ink">{{ $scope['name'] }}</p>
                                        @if (! empty($scope['description']))
                                            <p class="text-xs leading-5 text-muted">{{ $scope['description'] }}</p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    @if ($client?->client_uri)
                        <p class="mt-6 border-t border-line pt-4 text-xs leading-5 text-muted">
                            应用信息：
                            <a href="{{ $client->client_uri }}" class="text-ink-soft underline decoration-line-strong underline-offset-2"
                               rel="noopener noreferrer nofollow" target="_blank">{{ parse_url($client->client_uri, PHP_URL_HOST) ?: $client->client_uri }}</a>
                        </p>
                    @endif
                </div>

                <div class="flex flex-col-reverse gap-2 border-t border-line bg-[#fafbfb] px-5 py-4 sm:flex-row sm:justify-end">
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

            <p class="mt-4 text-center text-xs leading-5 text-muted">
                授权后可在「已授权应用」中随时撤销。本页地址不会被应用看到你的密码。
            </p>
        </div>
    </main>

    <footer class="border-t border-line bg-surface py-4 text-center text-xs text-muted">
        LyraAuth · OpenID Connect
    </footer>
</div>
@endsection
