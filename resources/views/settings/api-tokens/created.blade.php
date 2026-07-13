<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('API token created') }}</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ $tokenName }}</h1>
        </div>
    </x-slot>

    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8" x-data="{ copied: false }">
        <section class="rounded-2xl border border-amber-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Copy this token now') }}</h2>
            <p class="mt-2 text-sm text-slate-600">{{ __('This secret is shown only once and cannot be recovered later.') }}</p>
            <div class="mt-5 rounded-xl bg-slate-950 p-4">
                <code id="plain-api-token" class="block break-all text-sm text-emerald-300">{{ $plainTextToken }}</code>
            </div>
            <div class="mt-5 flex flex-wrap items-center gap-3">
                <button type="button" @click="navigator.clipboard.writeText(document.getElementById('plain-api-token').textContent); copied = true" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    <span x-show="!copied">{{ __('Copy token') }}</span>
                    <span x-cloak x-show="copied">{{ __('Copied') }}</span>
                </button>
                <a href="{{ route('settings.api-tokens.index') }}" class="text-sm font-semibold text-slate-700 hover:text-slate-950">{{ __('I have saved the token') }}</a>
            </div>
            <p class="mt-4 text-xs text-slate-500">{{ $expiresAt ? __('Expires :date', ['date' => $expiresAt->format('Y-m-d H:i')]) : __('This token does not expire automatically.') }}</p>
        </section>
    </div>
</x-app-layout>
