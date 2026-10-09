<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm font-medium text-emerald-700">{{ __('Platform') }}</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-900">{{ __('Customer feedback') }}</h1>
            </div>
            @can(\App\Enums\PlatformPermission::CompaniesView->value)
                <a href="{{ route('platform.operations.index') }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700">{{ __('Back to operations') }}</a>
            @endcan
        </div>
    </x-slot>
    <div class="mx-auto max-w-5xl space-y-4 px-4 py-8 sm:px-6">
        @forelse ($feedback as $item)
            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap justify-between gap-2">
                    <h2 class="font-semibold text-slate-900">{{ $item->title }}</h2>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold">{{ $item->status->value === 'resolved' ? __('Resolved') : __('Received') }}</span>
                </div>
                <p class="mt-2 text-sm text-slate-500">{{ $item->company?->name }} · {{ $item->reporter?->name }} · {{ $item->created_at->format('Y-m-d H:i') }}</p>
                <p class="mt-2 break-all text-xs text-slate-500">{{ __('Reference') }}: {{ $item->uuid }} · {{ $item->context['category'] ?? '' }}</p>
                <p class="mt-4 whitespace-pre-wrap break-words text-sm text-slate-700">{{ $item->description }}</p>
                <a href="{{ route('platform.operations.feedback.show', $item->uuid) }}" class="mt-3 inline-flex rounded-lg px-1 py-2 text-sm font-semibold text-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-700">{{ __('View conversation') }}</a>
                @can(\App\Enums\PlatformPermission::FeedbackManage->value)
                    @if ($item->status->value === 'open')
                        <form method="POST" action="{{ route('platform.operations.feedback.resolve', $item) }}" class="mt-4">
                            @csrf
                            <button class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-emerald-800">{{ __('Mark resolved') }}</button>
                        </form>
                    @endif
                @endcan
                @can(\App\Enums\PlatformPermission::CompaniesView->value)
                @if ($item->company)
                    <a href="{{ route('platform.operations.companies.show', $item->company) }}" class="mt-4 inline-flex text-sm font-semibold text-emerald-800">{{ __('Review company') }} →</a>
                @endif
                @endcan
            </article>
        @empty
            <p class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">{{ __('No customer feedback yet.') }}</p>
        @endforelse
        {{ $feedback->links() }}
    </div>
</x-app-layout>
