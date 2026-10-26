@extends('layouts.shell')

@section('content')
<div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_300px]">
    <div>
        @forelse ($organizations as $organization)
            <a href="/organizations/{{ $organization->slug }}"
               class="panel mb-2.5 flex items-center gap-3 p-3.5 transition-colors hover:border-line-strong">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-[2px] bg-brand-soft font-mono text-[12px] font-medium text-brand">
                    {{ mb_strtoupper(mb_substr($organization->name, 0, 1)) }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-[13px] font-medium text-ink">{{ $organization->name }}</p>
                    <p class="truncate text-[11.5px] text-muted">{{ $organization->description ?: $organization->slug }}</p>
                </div>
                <span class="badge badge-neutral shrink-0">
                    {{ match ($roles[$organization->id] ?? 'member') {
                        'owner' => '拥有者',
                        'admin' => '管理员',
                        default => '成员',
                    } }}
                </span>
            </a>
        @empty
            <div class="panel px-6 py-14 text-center">
                <p class="text-[13px] text-muted">你还没有加入任何组织。</p>
                <p class="mt-1 text-[11.5px] text-faint">组织可用于把成员与接入应用分组管理。</p>
            </div>
        @endforelse
    </div>

    <div class="panel h-fit">
        <div class="panel-header">
            <h2 class="panel-title">创建组织</h2>
        </div>
        <form method="POST" action="/organizations" class="panel-body space-y-3">
            @csrf
            <div>
                <label for="name" class="field-label">组织名称</label>
                <input id="name" name="name" type="text" class="field-input" placeholder="例如 研发中心" required>
            </div>
            <div>
                <label for="slug" class="field-label">标识（可选）</label>
                <input id="slug" name="slug" type="text" class="field-input font-mono" placeholder="rd-center">
                <p class="field-hint">用于访问地址，留空自动生成。</p>
            </div>
            <div>
                <label for="description" class="field-label">简介（可选）</label>
                <textarea id="description" name="description" rows="2" class="field-input resize-y"></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-block">创建</button>
        </form>
    </div>
</div>
@endsection
