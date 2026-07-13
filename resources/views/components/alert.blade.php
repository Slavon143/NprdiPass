@props(['type' => 'success'])

@php
    $classes = match ($type) {
        'error' => 'border-red-200 bg-red-50 text-red-800',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-900',
        default => 'border-emerald-200 bg-emerald-50 text-emerald-800',
    };
@endphp

<div {{ $attributes->merge(['class' => "rounded-xl border px-4 py-3 text-sm shadow-sm {$classes}", 'role' => $type === 'error' ? 'alert' : 'status']) }}>
    {{ $slot }}
</div>
