@extends('layouts.shell')

@section('content')
@forelse ($consents as $consent)
    @php $client = $clients[$consent->client_id] ?? null; @endphp
    @if ($client)
        <div class="panel mb-3 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[7px] text-sm font-semibold text-white"
                          style="background-color: {{ $client->logo_color }}">
                        {{ $client->initial() }}
                    </span>
                    <div class="min-w-0">
                        <p class="text-[14px] font-medium text-ink">{{ $client->name }}</p>
                        <p class="mt-0.5 text-xs text-muted">
                            {{ $client->description ?: $client->client_id }}
                        </p>
                        <p class="mt-1.5 text-xs text-muted">
                            上次授权 {{ $consent->last_granted_at?->format('Y-m-d H:i') ?? '—' }}
                        </p>
                    </div>
                </div>

                <form method="POST" action="/account/apps/{{ $consent->id }}/revoke"
                      data-confirm="撤销后该应用需要重新获得你的授权，确定继续？">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm">撤销授权</button>
                </form>
            </div>

            <div class="mt-3 flex flex-wrap gap-1.5 border-t border-line pt-3">
                @foreach (array_filter(explode(' ', (string) $consent->scopes)) as $scope)
                    <span class="badge badge-neutral font-mono">{{ $scope }}</span>
                @endforeach
            </div>
        </div>
    @endif
@empty
    <div class="panel px-6 py-12 text-center">
        <p class="text-sm text-muted">还没有应用获得你的授权。</p>
        <p class="mt-1 text-xs text-muted">首次登录接入的应用时，会在这里看到授权记录。</p>
    </div>
@endforelse
@endsection
