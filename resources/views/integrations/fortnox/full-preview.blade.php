<x-app-layout>
    <x-slot name="header"><h1 class="text-2xl font-semibold text-slate-900">{{ __('fortnox_sync.preview_title') }}</h1></x-slot>
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200" data-testid="fortnox-full-preview">
        <p class="text-sm text-slate-700">{{ __('fortnox_sync.preview_intro') }}</p>
        <dl class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach (['total_articles' => 'total', 'existing_mappings' => 'mapped', 'unmapped_articles' => 'unmapped', 'pages_fetched' => 'pages'] as $key => $label)
                <div><dt class="text-sm text-slate-500">{{ __('fortnox_sync.'.$label) }}</dt><dd class="mt-1 text-xl font-semibold" data-testid="preview-{{ $key }}">{{ $preview[$key] }}</dd></div>
            @endforeach
        </dl>
        <p class="mt-6 text-sm text-slate-600">{{ __('fortnox_sync.preview_note') }}</p>
        <div class="mt-6 flex flex-wrap items-center gap-4">
            <form method="POST" action="{{ route('settings.integrations.fortnox.full-import') }}">
                @csrf
                <input type="hidden" name="preview_token" value="{{ $preview['token'] }}">
                <x-primary-button>{{ __('fortnox_sync.confirm') }}</x-primary-button>
            </form>
            <a class="text-sm font-semibold text-indigo-700" href="{{ route('settings.integrations.fortnox.index') }}">{{ __('fortnox_sync.cancel') }}</a>
        </div>
    </section>
</x-app-layout>
