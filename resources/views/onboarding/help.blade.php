<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-medium text-emerald-700">{{ __('Help center') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Help') }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ __('Find answers, learn the workflow, or send us feedback.') }}</p>
            </div>
            <a href="{{ route('onboarding.index') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                {{ __('Back to onboarding') }}
            </a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <section class="rounded-2xl border border-emerald-200 bg-emerald-50 p-6">
            <h2 class="text-xl font-semibold text-slate-900">{{ __('How can we help?') }}</h2>
            <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Start with the guides below. For a question, a problem, or a suggestion, send a message and follow its status in Feedback.') }}</p>
            <a href="{{ route('onboarding.support') }}" class="mt-4 inline-flex rounded-lg bg-emerald-800 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-900">{{ __('Send feedback') }}</a>
        </section>

        <section class="grid gap-4 sm:grid-cols-2">
            @foreach ([
                [__('Products and variants'), __('Create a product or import your catalog. Variants describe different versions, such as sizes or colors. Add a category, identifiers, and a primary image before preparing a passport.')],
                [__('Import and Fortnox'), __('Upload CSV or XLSX, map the columns, review the preview, and apply the import. For Fortnox, connect your account and review sync results and conflicts. Use Job center to inspect progress and failures.')],
                [__('Documents and evidence'), __('Open a product and add manuals, declarations, and other evidence in Documents. Check the language, current version, review status, and expiry date. Link the appropriate documents to the passport.')],
                [__('Catalog and passport slots'), __('Your catalog can include products that do not have an active passport workflow. Managed products use passport slots. Review your current limits and usage in Billing.')],
            ] as [$guideTitle, $guideText])
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="font-semibold text-slate-900">{{ $guideTitle }}</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ $guideText }}</p>
                </article>
            @endforeach
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Frequently asked questions') }}</h2>
            @foreach ([
                [__('Why can I not publish a passport?'), __('Open the readiness report and resolve all blockers. Review required documents and any pending publication approval. Your company and plan must also permit publication.')],
                [__('What happens when I edit a published passport?'), __('Changes go into a draft. The published version remains unchanged until a new version is published.')],
                [__('Where can I see import and sync progress?'), __('Open Job center. Review the job details and any error report before retrying. For Fortnox conflicts, choose the intended values in the integration page.')],
                [__('Why is a notification still listed after I read it?'), __('Read notifications stay in your history. Opening Notifications marks the visible entries as read; Dismiss removes an entry from the inbox.')],
                [__('How do I report a problem?'), __('Open Feedback, choose Report a problem, and describe what happened and what you expected. You will receive a reference number. Your messages are visible to you and the platform administrator.')],
            ] as [$question, $answer])
                <details class="mt-3 rounded-lg border border-slate-200 px-4 py-3">
                    <summary class="cursor-pointer text-sm font-semibold text-slate-800">{{ $question }}</summary>
                    <p class="mt-3 text-sm leading-6 text-slate-600">{{ $answer }}</p>
                </details>
            @endforeach
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Onboarding help') }}</h2>
            <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Open Onboarding to follow the steps for your company: add products, prepare a passport, review readiness, publish, and verify the QR link.') }}</p>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('The beta journey') }}</h2>
            <p class="mt-2 text-sm text-slate-600">
                {{ __('Create or import products, add the required information and evidence, prepare a passport draft, resolve readiness blockers, and publish when ready. Onboarding shows which steps your company has completed.') }}
            </p>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Sample data isolation') }}</h2>
            <p class="mt-2 text-sm text-slate-600">
                {{ __('Use the sample product to explore the workflow. It belongs to your selected company and can be created once. Switch company from the header to work in another organization.') }}
            </p>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Readiness explained') }}</h2>
            <div class="mt-3 space-y-3 text-sm text-slate-600">
                <p>
                    {{ __('Before publication, each Passport is evaluated against a versioned readiness profile. The evaluation produces a score together with blockers and warnings:') }}
                </p>
                <ul class="list-disc space-y-2 pl-5">
                    <li>
                        <strong class="font-semibold text-slate-900">{{ __('Blockers') }}</strong>
                        {{ __('prevent publication and must be resolved first. Each blocker links to the section or document that fixes it.') }}
                    </li>
                    <li>
                        <strong class="font-semibold text-slate-900">{{ __('Warnings') }}</strong>
                        {{ __('do not block publication but should be reviewed before the Passport becomes publicly visible.') }}
                    </li>
                    <li>
                        <strong class="font-semibold text-slate-900">{{ __('Score') }}</strong>
                        {{ __('is computed from the versioned rule weights of the selected profile and remains reproducible for historical published versions.') }}
                    </li>
                </ul>
                <p>
                    {{ __('Readiness is not legal certification and does not replace an official compliance review.') }}
                </p>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Publication and QR') }}</h2>
            <div class="mt-3 space-y-2 text-sm text-slate-600">
                <p>
                    {{ __('Publishing freezes the draft into an immutable published version with a stable public URL. Later edits never mutate what visitors already saw. Publication may require an approval decision depending on company policy.') }}
                </p>
                <p>
                    {{ __('The QR resolver points at the stable public URL. After publishing, open the QR page to verify the label scans to the live Passport.') }}
                </p>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Billing and entitlements') }}</h2>
            <p class="mt-2 text-sm text-slate-600">
                {{ __('Publication and public QR links depend on your plan and subscription status. Open Billing to review your current plan, usage, and available options.') }}
            </p>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Recovery paths') }}</h2>
            <div class="mt-3 space-y-2 text-sm text-slate-600">
                <p>{{ __('When the journey stalls, use the documented recovery paths:') }}</p>
                <ul class="list-disc space-y-2 pl-5">
                    <li>{{ __('Billing recovery for failed or past-due subscriptions.') }}</li>
                    <li>{{ __('Job center retry for failed imports, syncs, and publication jobs.') }}</li>
                    <li>{{ __('Publication approvals for requests waiting on a decision.') }}</li>
                    <li>{{ __('QR verification after every publication or unpublish.') }}</li>
                    <li>{{ __('The configured company support email for platform help.') }}</li>
                </ul>
            </div>
            <div class="mt-5 flex flex-wrap gap-3">
                <a href="{{ route('settings.billing.index') }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Billing') }}</a>
                <a href="{{ route('job-center.index') }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Job center') }}</a>
                <a href="{{ route('onboarding.support') }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Support') }}</a>
            </div>
        </section>
    </div>
</x-app-layout>
