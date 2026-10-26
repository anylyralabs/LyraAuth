@extends('layouts.shell')

@section('content')
@if (! empty($error))
    <div class="alert alert-error mb-4" role="alert">{{ $error }}</div>
@endif

<div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
    <div class="panel self-start overflow-visible">
        <div class="panel-header">
            <div>
                <h2 class="panel-title">已定义的权限范围</h2>
                <p class="panel-note">应用在授权请求的 scope 参数中引用这些标识。</p>
            </div>
            <span class="font-mono text-[11px] text-faint">{{ $scopes->count() }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="data-table table-fixed">
                <thead>
                    <tr>
                        <th class="w-[42%]">权限范围</th>
                        <th class="hidden w-[38%] sm:table-cell">说明</th>
                        <th class="w-[20%] text-right">操作</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($scopes as $scope)
                        <tr>
                            <td>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <code class="font-mono text-[12px] text-ink">{{ $scope->identifier }}</code>
                                    @if ($scope->is_system)
                                        <span class="badge badge-neutral">内置</span>
                                    @endif
                                    @if ($scope->is_default)
                                        <span class="badge badge-brand">默认</span>
                                    @endif
                                </div>
                                <p class="mt-0.5 text-[11.5px] text-muted">{{ $scope->name }}</p>
                            </td>
                            <td class="hidden max-w-0 truncate text-[12px] sm:table-cell" title="{{ $scope->description }}">{{ $scope->description ?: '—' }}</td>
                            <td class="text-right">
                                <details class="relative inline-block text-left" data-menu>
                                    <summary class="btn btn-ghost btn-sm cursor-pointer list-none">编辑</summary>
                                    <div class="panel absolute right-0 z-30 mt-1 w-64 p-3 text-left">
                                        <form method="POST" action="/admin/scopes/{{ $scope->id }}" class="space-y-2.5">
                                            @csrf
                                            <div>
                                                <label class="field-label">名称</label>
                                                <input name="name" type="text" value="{{ $scope->name }}" class="field-input" required>
                                            </div>
                                            <div>
                                                <label class="field-label">说明</label>
                                                <textarea name="description" rows="2" class="field-input resize-y">{{ $scope->description }}</textarea>
                                            </div>
                                            <label class="flex items-center gap-2 text-[12px]">
                                                <input type="checkbox" name="is_default" value="1" class="accent-brand" {{ $scope->is_default ? 'checked' : '' }}>
                                                <span>默认勾选</span>
                                            </label>
                                            <button type="submit" class="btn btn-primary btn-sm w-full">保存</button>
                                        </form>

                                        @unless ($scope->is_system)
                                            <form method="POST" action="/admin/scopes/{{ $scope->id }}/delete" class="mt-2 border-t border-line pt-2"
                                                  data-confirm="删除后使用该 scope 的客户端将失去它，确定继续？">
                                                @csrf
                                                <button type="submit" class="btn btn-ghost btn-sm w-full text-danger">删除</button>
                                            </form>
                                        @endunless
                                    </div>
                                </details>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <section class="panel h-fit">
        <div class="panel-header">
            <div>
                <h2 class="panel-title">新建权限范围</h2>
                <p class="panel-note">标识建议使用 <span class="font-mono">资源:动作</span> 的形式。</p>
            </div>
        </div>
        <form method="POST" action="/admin/scopes" class="panel-body space-y-3">
            @csrf
            <div>
                <label for="identifier" class="field-label">标识</label>
                <input id="identifier" name="identifier" type="text" class="field-input font-mono !text-[12.5px]"
                       placeholder="read:reports" required>
                <p class="field-hint">客户端在 scope 参数里使用此值。</p>
            </div>
            <div>
                <label for="name" class="field-label">显示名称</label>
                <input id="name" name="name" type="text" class="field-input" placeholder="读取报表" required>
            </div>
            <div>
                <label for="description" class="field-label">说明（同意页展示）</label>
                <textarea id="description" name="description" rows="2" class="field-input resize-y"
                          placeholder="允许应用读取你的报表数据"></textarea>
            </div>
            <label class="flex items-center gap-2 text-[13px]">
                <input type="checkbox" name="is_default" value="1" class="accent-brand">
                <span>授权时默认勾选</span>
            </label>
            <button type="submit" class="btn btn-primary btn-block">创建</button>
        </form>
    </section>
</div>
@endsection
