@php
    /** @var array{type?: string, message?: string}|null $flash */
    $flash = $flash ?? null;
    $errorBag = $errorBag ?? null;
@endphp

@if (is_array($flash) && ! empty($flash['message']))
    @php
        $type = $flash['type'] ?? 'info';
        $alertClass = match ($type) {
            'success' => 'alert-success',
            'error', 'danger' => 'alert-error',
            'warning' => 'alert-warning',
            default => 'alert-info',
        };
    @endphp
    <div class="alert {{ $alertClass }} mb-5" role="status">
        <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.4"/>
            <path d="M8 5v4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
            <circle cx="8" cy="11.2" r="0.8" fill="currentColor"/>
        </svg>
        <span>{{ $flash['message'] }}</span>
    </div>
@endif

@if (is_string($errorBag) && $errorBag !== '')
    <div class="alert alert-error mb-5" role="alert">
        <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.4"/>
            <path d="M8 4.8v4.4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
            <circle cx="8" cy="11.6" r="0.8" fill="currentColor"/>
        </svg>
        <span>{{ $errorBag }}</span>
    </div>
@endif
