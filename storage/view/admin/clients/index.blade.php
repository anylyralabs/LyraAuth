@extends('layouts.shell')

@section('content')
@if ($newSecret)
    <section class="panel mb-4 border-warning-line">
        <div class="panel-header bg-warning-soft">
            <div>
                <h2 class="panel-title">客户端密钥（仅显示一次）</h2>
                <p class="panel-note">离开本页后将无法再次查看，请立即保存到安全的位置。</p>
            </div>
            <span class="badge badge-warning">SECRET</span>
        </div>
        <div class="panel-body">
            <div class="flex items-center gap-2">
                <code class="flex-1 select-all break-all border border-line bg-sunk px-2.5 py-2 font-mono text-[12.5px] text-ink">{{ $newSecret }}</code>
                <button type="button" class="btn btn-secondary btn-sm shrink-0" data-copy="{{ $newSecret }}" data-copied-label="已复制">复制</button>
            </div>
        </div>
    </section>
@endif

<div class="mb-4 flex items-center justify-between gap-3">
    <p class="font-mono text-[11px] tracking-[0.06em] text-faint">TOTAL {{ $clients->total() }}</p>
    <a href="/admin/clients/create" class="btn btn-primary btn-sm">新建应用</a>
</div>

<div class="panel overflow-hidden">
    @if ($clients->isEmpty())
        <div class="px-6 py-14 text-center">
            <p class="text-[13px] text-muted">还没有创建任何应用。</p>
            <a href="/admin/clients/create" class="btn btn-secondary btn-sm mt-4">创建第一个应用</a>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="data-table table-fixed">
                <thead>
                    <tr>
                        <th class="w-[34%]">应用</th>
                        <th class="hidden w-[16%] sm:table-cell">类型</th>
                        <th class="hidden w-[22%] md:table-cell">授权模式</th>
                        <th class="w-[14%]">状态</th>
                        <th class="w-[14%] text-right">操作</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($clients as $client)
                        <tr>
                            <td>
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[2px] font-mono text-[11px] font-medium"
                                          style="background-color: {{ $client->logo_color }}; color: {{ $client->logoInk() }}">
                                        {{ $client->initial() }}
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate text-[13px] font-medium text-ink">{{ $client->name }}</p>
                                        <p class="truncate font-mono text-[10.5px] text-muted">{{ $client->client_id }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="hidden sm:table-cell">
                                <span class="text-[12px]">{{ $client->isPublic() ? '公共' : '机密' }}</span>
                                @if ($client->is_first_party)
                                    <span class="badge badge-brand ml-1">第一方</span>
                                @endif
                            </td>
                            <td class="hidden md:table-cell">
                                <span class="font-mono text-[10.5px] tracking-[0.02em] text-muted">
                                    {{ implode(' · ', array_map(static fn ($g) => match ($g) {
                                        'authorization_code' => 'code',
                                        'refresh_token' => 'refresh',
                                        'client_credentials' => 'client',
                                        'urn:ietf:params:oauth:grant-type:device_code' => 'device',
                                        default => $g,
                                    }, (array) $client->grant_types)) }}
                                </span>
                            </td>
                            <td>
                                @if ($client->is_active)
                                    <span class="badge badge-success">启用</span>
                                @else
                                    <span class="badge badge-neutral">停用</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <details class="relative inline-block text-left" data-menu>
                                    <summary class="btn btn-ghost btn-sm cursor-pointer list-none">管理</summary>
                                    <div class="panel absolute right-0 z-30 mt-1 w-44 p-1.5 text-left shadow-none">
                                        <a href="/admin/clients/{{ $client->id }}/edit"
                                           class="block rounded-[3px] px-2.5 py-1.5 text-[12.5px] text-ink-soft hover:bg-sunk">编辑</a>

                                        @unless ($client->isPublic())
                                            <form method="POST" action="/admin/clients/{{ $client->id }}/rotate-secret"
                                                  data-confirm="轮换后旧密钥立即失效，确定继续？">
                                                @csrf
                                                <button type="submit" class="block w-full rounded-[3px] px-2.5 py-1.5 text-left text-[12.5px] text-ink-soft hover:bg-sunk">轮换密钥</button>
                                            </form>
                                        @endunless

                                        <form method="POST" action="/admin/clients/{{ $client->id }}/toggle">
                                            @csrf
                                            <button type="submit" class="block w-full rounded-[3px] px-2.5 py-1.5 text-left text-[12.5px] text-ink-soft hover:bg-sunk">
                                                {{ $client->is_active ? '停用' : '启用' }}
                                            </button>
                                        </form>

                                        <div class="my-1 border-t border-line"></div>

                                        <form method="POST" action="/admin/clients/{{ $client->id }}/delete"
                                              data-confirm="删除后该应用的所有令牌与授权都会失效，且不可恢复。确定删除「{{ $client->name }}」？">
                                            @csrf
                                            <button type="submit" class="block w-full rounded-[3px] px-2.5 py-1.5 text-left text-[12.5px] text-danger hover:bg-danger-soft">删除</button>
                                        </form>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@if ($clients->hasPages())
    <div class="mt-4 flex items-center justify-between">
        <span class="font-mono text-[11px] tracking-[0.04em] text-faint">PAGE {{ $clients->currentPage() }} / {{ $clients->lastPage() }}</span>
        <div class="flex gap-2">
            @if ($clients->previousPageUrl())
                <a href="{{ $clients->previousPageUrl() }}" class="btn btn-secondary btn-sm">上一页</a>
            @endif
            @if ($clients->nextPageUrl())
                <a href="{{ $clients->nextPageUrl() }}" class="btn btn-secondary btn-sm">下一页</a>
            @endif
        </div>
    </div>
@endif
@endsection
