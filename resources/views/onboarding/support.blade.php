<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-medium text-emerald-700">{{ __('Help center') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Feedback') }}</h1>
                <p class="mt-2 text-sm text-slate-500">{{ __('Ask a question, report a problem, or suggest an improvement.') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('onboarding.help') }}" class="rounded-lg border border-slate-200 px-3 py-1 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    {{ __('Help') }}
                </a>
                <a href="{{ route('onboarding.index') }}" class="rounded-lg border border-slate-200 px-3 py-1 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    {{ __('Onboarding') }}
                </a>
            </div>
        </div>
    </x-slot>

    <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Send a message') }}</h2>
            <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Your message is saved in NordiPass for the platform administrator. You can follow its status below.') }}</p>
            <form method="POST" action="{{ route('onboarding.feedback.store') }}" class="mt-5 space-y-4" data-testid="feedback-form">
                @csrf
                <input type="hidden" name="submission_id" value="{{ old('submission_id', $submissionId) }}">
                <div>
                    <label for="feedback-category" class="block text-sm font-semibold text-slate-700">{{ __('What is your message about?') }}</label>
                    <select id="feedback-category" name="category" required class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-emerald-700 focus:ring-emerald-700">
                        @foreach (['question' => __('Ask a question'), 'bug' => __('Report a problem'), 'idea' => __('Suggest an improvement')] as $value => $label)
                            <option value="{{ $value }}" @selected(old('category', 'question') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('category')" class="mt-1" />
                </div>
                <div>
                    <label for="feedback-subject" class="block text-sm font-semibold text-slate-700">{{ __('Subject') }}</label>
                    <input id="feedback-subject" name="subject" value="{{ old('subject') }}" required maxlength="120" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-emerald-700 focus:ring-emerald-700" placeholder="{{ __('A short description of your question or problem') }}">
                    <x-input-error :messages="$errors->get('subject')" class="mt-1" />
                </div>
                <div>
                    <label for="feedback-message" class="block text-sm font-semibold text-slate-700">{{ __('Message') }}</label>
                    <textarea id="feedback-message" name="message" required minlength="10" maxlength="2000" rows="6" aria-describedby="feedback-message-hint" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-emerald-700 focus:ring-emerald-700" placeholder="{{ __('What happened, and what did you expect? If relevant, include the product or job reference.') }}">{{ old('message') }}</textarea>
                    <p id="feedback-message-hint" class="mt-2 text-xs text-slate-500">{{ __('10–2000 characters. Do not include passwords, access tokens, or payment details.') }}</p>
                    <x-input-error :messages="$errors->get('message')" class="mt-1" />
                </div>
                <x-input-error :messages="$errors->get('submission_id')" />
                <button type="submit" class="rounded-lg bg-emerald-800 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-900 focus:outline-none focus:ring-2 focus:ring-emerald-700 focus:ring-offset-2">{{ __('Send message') }}</button>
            </form>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Your messages') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ __('Only your messages for the selected company appear here.') }}</p>
            <div class="mt-4 space-y-3">
                @forelse ($feedback as $item)
                    <details class="rounded-lg border border-slate-200 p-4">
                        <summary class="cursor-pointer text-sm font-semibold text-slate-800">
                            {{ $item->title }}
                            <span class="ml-2 inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs">{{ $item->status->value === 'resolved' ? __('Resolved') : __('Received') }}</span>
                        </summary>
                        <p class="mt-2 break-all text-xs text-slate-500">{{ __('Reference') }}: {{ $item->uuid }} · {{ $item->created_at->format('Y-m-d H:i') }}</p>
                        <p class="mt-3 whitespace-pre-wrap break-words text-sm text-slate-600">{{ $item->description }}</p>
                        <a href="{{ route('onboarding.feedback.show', $item->uuid) }}" class="mt-3 inline-flex rounded-lg px-1 py-2 text-sm font-semibold text-emerald-800 focus:outline-none focus:ring-2 focus:ring-emerald-700">{{ __('View conversation') }}</a>
                    </details>
                @empty
                    <p class="rounded-lg bg-slate-50 p-4 text-sm text-slate-500">{{ __('No messages yet. Send your first message using the form above.') }}</p>
                @endforelse
            </div>
            <div class="mt-4">{{ $feedback->links() }}</div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Support and recovery') }}</h2>
            <dl class="mt-4 space-y-4 text-sm">
                <div>
                    <dt class="font-medium text-slate-500">{{ __('Company support email') }}</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ $supportEmail ?: __('Not configured') }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-slate-500">{{ __('What support covers') }}</dt>
                    <dd class="mt-1 text-slate-700">{{ __('Guided onboarding questions, stalled journeys, billing recovery, import and sync failures, and QR or public-page verification.') }}</dd>
                </div>
            </dl>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Recovery paths') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('Use these paths when the onboarding journey stalls.') }}</p>
            </div>
            <div class="divide-y divide-slate-200">
                <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="font-semibold text-slate-900">{{ __('Billing recovery') }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ __('Fix failed or past-due subscriptions before publication and QR steps unlock.') }}</p>
                    </div>
                    <a href="{{ route('settings.billing.index') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Open billing') }}</a>
                </div>
                <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="font-semibold text-slate-900">{{ __('Job center retry') }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ __('Retry or cancel failed imports, Fortnox syncs, and publication jobs safely.') }}</p>
                    </div>
                    <a href="{{ route('job-center.index') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Job center') }}</a>
                </div>
                <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="font-semibold text-slate-900">{{ __('Publication recovery') }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ __('Resolve readiness blockers, wait for approval decisions, and re-verify the QR after each publication.') }}</p>
                    </div>
                    <a href="{{ route('catalog.products.index') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Products') }}</a>
                </div>
                <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="font-semibold text-slate-900">{{ __('Company details') }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ __('Keep the support email and company details current so beta users can reach you.') }}</p>
                    </div>
                    <a href="{{ route('settings.company.edit') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Company settings') }}</a>
                </div>
            </div>
        </section>
    </div>
</x-app-layout>
