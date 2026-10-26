@extends('layouts.auth')

@section('title', '验证邮箱')

@section('content')
<h1 class="text-[20px] font-semibold tracking-[-0.025em] text-ink">查收验证邮件</h1>
<p class="mt-1.5 text-[13px] leading-[1.65] text-muted">我们已向下面的地址发送了一封验证邮件，请在 24 小时内完成验证。</p>

<div class="mt-5 border border-line bg-surface px-3.5 py-3">
    <p class="font-mono text-[12.5px] text-ink">{{ $email ?: '你的邮箱' }}</p>
</div>

<ul class="mt-6 space-y-1.5 text-[12.5px] leading-[1.7] text-muted">
    <li class="flex gap-2"><span class="text-faint">—</span><span>若未收到邮件，请先检查垃圾邮件目录。</span></li>
    <li class="flex gap-2"><span class="text-faint">—</span><span>验证完成后即可使用该邮箱登录。</span></li>
    <li class="flex gap-2"><span class="text-faint">—</span><span>开发环境邮件不会真正发送，链接写在 <code class="rounded-[2px] bg-sunk px-1 py-0.5 text-[11px] text-ink-soft">runtime/logs/mail.log</code>。</span></li>
</ul>

<a href="/login" class="btn btn-secondary btn-block mt-7">返回登录</a>
@endsection
