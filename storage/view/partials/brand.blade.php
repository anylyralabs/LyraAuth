@php
    /** @var string $size sm | md | lg */
    $size = $size ?? 'md';
    $showTagline = $showTagline ?? false;

    $mark = match ($size) {
        'sm' => 'h-6 w-6',
        'lg' => 'h-10 w-10',
        default => 'h-8 w-8',
    };
    $text = match ($size) {
        'sm' => 'text-[14px]',
        'lg' => 'text-[19px]',
        default => 'text-[15px]',
    };
@endphp
<span class="inline-flex items-center gap-2.5">
    {{-- 标记：一个钥匙孔形状的 L 形切口，配黄铜色的环形 --}}
    <svg class="{{ $mark }} shrink-0" viewBox="0 0 32 32" fill="none" aria-hidden="true">
        <rect x="0.5" y="0.5" width="31" height="31" rx="2.5" fill="#14171A" stroke="#14171A"/>
        <path d="M10 23V9" stroke="#F5F5F2" stroke-width="2.1" stroke-linecap="square"/>
        <path d="M10 16.4L16.6 9" stroke="#F5F5F2" stroke-width="2.1" stroke-linecap="square"/>
        <path d="M10 16.4L17.2 23" stroke="#F5F5F2" stroke-width="2.1" stroke-linecap="square"/>
        <circle cx="22.6" cy="16" r="3.1" stroke="#96631C" stroke-width="1.7"/>
    </svg>
    <span class="inline-flex items-baseline gap-2">
        <span class="{{ $text }} font-semibold tracking-[-0.02em] text-ink" style="font-family: var(--font-display)">LyraAuth</span>
        @if ($showTagline)
            <span class="hidden text-[11px] tracking-[0.02em] text-muted sm:inline">统一身份认证</span>
        @endif
    </span>
</span>
