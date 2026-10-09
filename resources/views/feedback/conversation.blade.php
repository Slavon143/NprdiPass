<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-sm font-medium text-emerald-700">{{ $support ? __('Customer feedback') : __('Your conversation') }}</p>
                <h1 class="mt-1 break-words text-2xl font-bold text-slate-900">{{ $feedback->title }}</h1>
                <span class="mt-2 inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold">{{ $feedback->status->value === 'resolved' ? __('Resolved') : __('Received') }}</span>
            </div>
            <a href="{{ route($support ? 'platform.operations.feedback' : 'onboarding.support') }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700">{{ __('Back to messages') }}</a>
        </div>
    </x-slot>
    <div class="mx-auto max-w-3xl space-y-5 px-4 py-8 sm:px-6">
        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="font-semibold text-slate-900">{{ __('Original message') }}</h2>
            <p class="mt-2 break-all text-xs text-slate-500">{{ __('Reference') }}: {{ $feedback->uuid }} · {{ $feedback->created_at->format('Y-m-d H:i') }}</p>
            <p class="mt-3 whitespace-pre-wrap break-words text-sm text-slate-700">{{ $feedback->description }}</p>
        </section>
        <section class="space-y-3" aria-label="{{ __('Replies') }}">
            <h2 class="font-semibold text-slate-900">{{ __('Replies') }}</h2>
            @if ($replies->hasPages())
                <p class="text-xs text-slate-500">{{ __('Latest replies are on the first page. Use the next page for older replies.') }}</p>
            @endif
            @forelse ($replies->getCollection()->reverse() as $reply)
                <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" data-testid="feedback-reply">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
                        <span class="font-semibold text-emerald-800">{{ $reply->author_type === 'support' ? __('Support') : ($support ? __('Customer') : __('You')) }}</span>
                        <time datetime="{{ $reply->created_at->toIso8601String() }}">{{ $reply->created_at->format('Y-m-d H:i') }}</time>
                    </div>
                    <p class="mt-3 whitespace-pre-wrap break-words text-sm text-slate-700">{{ $reply->message }}</p>
                </article>
            @empty
                <p class="rounded-lg bg-slate-100 p-4 text-sm text-slate-500">{{ __('No replies yet.') }}</p>
            @endforelse
            {{ $replies->links() }}
        </section>
        @if ($canReply)
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <form method="POST" action="{{ route($support ? 'platform.operations.feedback.reply' : 'onboarding.feedback.reply', $feedback->uuid) }}" class="space-y-3" data-testid="feedback-reply-form">
                    @csrf
                    <input type="hidden" name="submission_id" value="{{ old('submission_id', $submissionId) }}">
                    <label for="reply-message" class="block font-semibold text-slate-900">{{ __('Your reply') }}</label>
                    <textarea id="reply-message" name="message" required maxlength="2000" rows="4" aria-describedby="reply-hint" class="w-full rounded-lg border-slate-300 text-sm focus:border-emerald-700 focus:ring-emerald-700">{{ old('message') }}</textarea>
                    <p id="reply-hint" class="text-xs text-slate-500">{{ __('Up to 2000 characters. Do not include passwords, access tokens, or payment details.') }}</p>
                    @if (! $support && $feedback->status->value === 'resolved')
                        <p class="text-sm text-slate-600">{{ __('Replying to a closed request reopens it.') }}</p>
                    @endif
                    <x-input-error :messages="$errors->get('message')" />
                    <x-input-error :messages="$errors->get('submission_id')" />
                    <button type="submit" class="rounded-lg bg-emerald-800 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-900 focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-2">{{ __('Send reply') }}</button>
                </form>
            </section>
        @endif
    </div>
</x-app-layout>
