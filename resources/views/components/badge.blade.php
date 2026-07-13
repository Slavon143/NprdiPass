@props(['tone' => 'slate'])

@php
    $classes = match ($tone) {
        'indigo' => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        'amber' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        'red' => 'bg-red-50 text-red-700 ring-red-600/20',
        default => 'bg-slate-100 text-slate-700 ring-slate-500/20',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold capitalize ring-1 ring-inset {$classes}"]) }}>
    {{ $slot }}
</span>
