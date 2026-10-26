@extends('layouts.auth')

@section('title', '验证邮箱')

@section('content')
<div class="mb-7">
    <h1 class="text-[22px] font-semibold tracking-tight text-ink">查收验证邮件</h1>
    <p class="mt-1.5 text-sm leading-6 text-muted">我们已向下面的地址发送了一封验证邮件，请在 24 小时内完成验证。</p>
</div>

<div class="panel panel-body">
    <p class="font-mono text-[13px] text-ink">{{ $email ?: '你的邮箱' }}</p>
</div>

<ul class="mt-6 space-y-2 text-[13px] leading-6 text-muted">
    <li>· 若未收到邮件，请先检查垃圾邮件目录。</li>
    <li>· 验证完成后即可使用该邮箱登录。</li>
    <li>· 开发环境邮件不会真正发送，链接写在 <code class="rounded bg-[#f1f3f5] px-1.5 py-0.5 text-[12px] text-ink-soft">runtime/logs/mail.log</code>。</li>
</ul>

<div class="mt-7 flex flex-col gap-2">
    <a href="/login" class="btn btn-secondary btn-block">返回登录</a>
</div>
@endsection
