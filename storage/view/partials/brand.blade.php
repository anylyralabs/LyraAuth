@php
    /** @var string|null $size 尺寸：sm | md | lg */
    $size = $size ?? 'md';
    $markSize = match ($size) {
        'sm' => 'h-7 w-7',
        'lg' => 'h-11 w-11',
        default => 'h-9 w-9',
    };
    $textSize = match ($size) {
        'sm' => 'text-[15px]',
        'lg' => 'text-xl',
        default => 'text-base',
    };
    $showTagline = $showTagline ?? false;
@endphp
<span class="inline-flex items-center gap-2.5">
    <svg class="{{ $markSize }} shrink-0" viewBox="0 0 32 32" fill="none" aria-hidden="true">
        <rect width="32" height="32" rx="8" fill="#2F4D5E"/>
        <path d="M9 21.5V10.5" stroke="#F5F6F7" stroke-width="2" stroke-linecap="round"/>
        <path d="M9 16.2L15.4 10.5" stroke="#F5F6F7" stroke-width="2" stroke-linecap="round"/>
        <path d="M9 16.2L16 21.5" stroke="#F5F6F7" stroke-width="2" stroke-linecap="round"/>
        <circle cx="22.5" cy="16" r="3.2" stroke="#9DB6C4" stroke-width="1.6"/>
    </svg>
    <span class="inline-flex items-baseline gap-2">
        <span class="{{ $textSize }} font-semibold tracking-tight text-ink">LyraAuth</span>
        @if ($showTagline)
            <span class="hidden text-xs text-muted sm:inline">统一身份认证</span>
        @endif
    </span>
</span>
