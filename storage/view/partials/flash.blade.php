@php
    /** @var array{type?: string, message?: string}|null $flash */
    $flash = $flash ?? null;
    $errorBag = $errorBag ?? null;

    $icon = function (string $type): string {
        return match ($type) {
            'success' => '<path d="M3.2 8.4l3.1 3.1L12.8 4.9" stroke="currentColor" stroke-width="1.7" stroke-linecap="square" stroke-linejoin="miter"/>',
            'error', 'danger' => '<path d="M8 4.4v4.8" stroke="currentColor" stroke-width="1.7" stroke-linecap="square"/><rect x="7.2" y="11.2" width="1.6" height="1.6" fill="currentColor"/>',
            default => '<circle cx="8" cy="8" r="6.2" stroke="currentColor" stroke-width="1.3"/><path d="M8 7v4.2" stroke="currentColor" stroke-width="1.4" stroke-linecap="square"/><rect x="7.35" y="4.4" width="1.3" height="1.3" fill="currentColor"/>',
        };
    };
@endphp

@if (is_array($flash) && ! empty($flash['message']))
    @php
        $type = $flash['type'] ?? 'info';
        $class = match ($type) {
            'success' => 'alert-success',
            'error', 'danger' => 'alert-error',
            'warning' => 'alert-warning',
            default => 'alert-info',
        };
    @endphp
    <div class="alert {{ $class }} mb-4" role="status">
        <svg class="mt-[3px] h-4 w-4 shrink-0 opacity-70" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            {!! $icon($type) !!}
        </svg>
        <span>{{ $flash['message'] }}</span>
    </div>
@endif

@if (is_string($errorBag) && $errorBag !== '')
    <div class="alert alert-error mb-4" role="alert">
        <svg class="mt-[3px] h-4 w-4 shrink-0 opacity-70" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            {!! $icon('error') !!}
        </svg>
        <span>{{ $errorBag }}</span>
    </div>
@endif
