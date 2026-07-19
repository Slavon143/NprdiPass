<x-app-layout>
<div class="container mx-auto px-4 py-8">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Product Passport') }}</p>
            <h1 class="text-2xl font-bold text-slate-900">{{ $product->name }}</h1>
            <p class="mt-1 text-sm text-slate-500">
                {{ __('Published versions are public snapshots. The current draft remains editable for the next version.') }}
            </p>
        </div>
        <a href="{{ route('catalog.products.show', $product->uuid) }}" class="text-sm font-semibold text-indigo-700 hover:underline">{{ __('Back to Product') }}</a>
    </div>

    @if(session('success'))
        <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800">
            {{ session('error') }}
        </div>
    @endif

    @if($passport === null || !isset($editorData))
        <div class="rounded-lg bg-white p-8 text-center shadow">
            <p class="mb-4 text-gray-600">{{ __('No passport draft exists yet for this product.') }}</p>
            <form method="POST" action="{{ route('catalog.products.passport.store', $product->uuid) }}">
                @csrf
                <button type="submit" class="rounded bg-blue-600 px-6 py-2 text-white hover:bg-blue-700" {{ $canManage ? '' : 'disabled' }}>
                    {{ __('Create Passport Draft') }}
                </button>
            </form>
        </div>
    @else
        @php
            $publishedVersion = $passport->currentPublishedVersion;
            $currentDraft = $passport->currentDraftVersion;
            $draftRevision = $currentDraft?->draft_revision ?? ($editorData['draft_revision'] ?? null);
            $draftSchema = $currentDraft?->schema_version ?? ($editorData['schema_version'] ?? null);
            $canOpenPublish = $canPublish && $currentDraft !== null && $readiness->status->value !== 'not_ready' && ! $passport->isArchived();
            $statusTone = match ($passport->status->value) {
                'published' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                'unpublished' => 'bg-orange-100 text-orange-800 border-orange-200',
                'archived' => 'bg-slate-100 text-slate-700 border-slate-200',
                default => 'bg-indigo-100 text-indigo-800 border-indigo-200',
            };
            $readinessTone = match ($readiness->status->value) {
                'ready' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                'ready_with_warnings' => 'bg-amber-100 text-amber-800 border-amber-200',
                default => 'bg-red-100 text-red-800 border-red-200',
            };
        @endphp

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="space-y-6">
                <section class="rounded-lg bg-white p-6 shadow">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900">{{ __('Passport status') }}</h2>
                            <p class="mt-1 text-sm text-slate-600">
                                @if($passport->isPublished())
                                    {{ __('This passport has a public published version. A new draft is also kept for future edits.') }}
                                @elseif($passport->isUnpublished())
                                    {{ __('This passport has been unpublished. The QR remains stable, but the public page is unavailable until republished.') }}
                                @elseif($passport->isArchived())
                                    {{ __('This passport is archived and cannot be published until restored.') }}
                                @else
                                    {{ __('This passport is still a draft and has not been published publicly yet.') }}
                                @endif
                            </p>
                        </div>
                        <span class="inline-flex w-fit rounded-full border px-3 py-1 text-sm font-semibold {{ $statusTone }}">
                            {{ ucfirst($passport->status->value) }}
                        </span>
                    </div>

                    <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-3">
                        <div>
                            <dt class="text-slate-500">{{ __('Default language') }}</dt>
                            <dd class="mt-1 font-semibold text-slate-900">{{ strtoupper($passport->default_language) }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">{{ __('Schema version') }}</dt>
                            <dd class="mt-1 font-semibold text-slate-900">{{ $draftSchema ?? 'N/A' }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">{{ __('Current draft revision') }}</dt>
                            <dd class="mt-1 font-semibold text-slate-900">{{ $draftRevision ? '#'.$draftRevision : 'N/A' }}</dd>
                        </div>
                    </dl>
                </section>

                <section id="published-version" class="scroll-mt-24 rounded-lg bg-white p-6 shadow target:ring-4 target:ring-emerald-100">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900">{{ __('Published version') }}</h2>
                            <p class="mt-1 text-sm text-slate-600">
                                {{ __('This is the immutable public snapshot customers see through the QR/public link.') }}
                            </p>
                        </div>
                        @if($publishedVersion)
                            <span class="rounded-full bg-emerald-100 px-3 py-1 text-sm font-semibold text-emerald-800">{{ __('Published') }}</span>
                        @else
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-600">{{ __('Not published yet') }}</span>
                        @endif
                    </div>

                    @if($publishedVersion)
                        <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-slate-500">{{ __('Version') }}</dt>
                                <dd class="mt-1 font-semibold text-slate-900">#{{ $publishedVersion->version_number }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">{{ __('Published at') }}</dt>
                                <dd class="mt-1 font-semibold text-slate-900">{{ $publishedVersion->published_at?->format('Y-m-d H:i') ?? 'N/A' }}</dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="text-slate-500">{{ __('Checksum') }}</dt>
                                <dd class="mt-1 break-all font-mono text-xs text-slate-700">{{ $publishedVersion->content_checksum ?? 'N/A' }}</dd>
                            </div>
                        </dl>

                        <div class="mt-5 flex flex-wrap gap-3 border-t border-slate-200 pt-5">
                            @if($passport->isPublished())
                                <a href="{{ route('public.passports.show', $passport->public_id) }}" target="_blank" rel="noopener noreferrer" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">
                                    {{ __('Open public page') }}
                                </a>
                            @endif
                            <a href="{{ route('catalog.products.passport.versions.show', ['product' => $product->uuid, 'version' => $publishedVersion->uuid]) }}" class="rounded-lg border border-indigo-300 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">
                                {{ __('View published snapshot') }}
                            </a>
                        </div>
                    @else
                        <p class="mt-5 rounded-lg bg-slate-50 p-4 text-sm text-slate-600">
                            {{ __('No public version exists yet. Publish the current draft when readiness is complete.') }}
                        </p>
                    @endif
                </section>

                <section class="rounded-lg bg-white p-6 shadow">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900">{{ __('Current draft') }}</h2>
                            <p class="mt-1 text-sm text-slate-600">
                                {{ __('This draft is editable. Publishing creates a new immutable public version and then starts the next draft.') }}
                            </p>
                        </div>
                        @if($currentDraft)
                            <span class="rounded-full bg-indigo-100 px-3 py-1 text-sm font-semibold text-indigo-800">{{ __('Draft revision #:revision', ['revision' => $currentDraft->draft_revision]) }}</span>
                        @else
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-600">{{ __('No draft') }}</span>
                        @endif
                    </div>

                    <div class="mt-5 flex flex-wrap gap-3 border-t border-slate-200 pt-5">
                        @if($currentDraft)
                            <a href="{{ route('catalog.products.passport.preview', $product->uuid) }}" target="_blank" rel="noopener noreferrer" class="rounded-lg border border-amber-400 px-4 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-50">
                                {{ __('Preview draft') }}
                            </a>
                        @endif

                        @if($canManage)
                            <a href="{{ route('catalog.products.passport.edit', $product->uuid) }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                                {{ __('Edit draft') }}
                            </a>
                        @endif

                        @if($canOpenPublish)
                            <a href="{{ route('catalog.products.passport.publish-confirm', $product->uuid) }}" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">
                                {{ $passport->isPublished() ? __('Publish new version') : __('Publish draft') }}
                            </a>
                        @elseif($canPublish && $currentDraft)
                            <a href="{{ route('catalog.products.passport.readiness', $product->uuid) }}" class="rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">
                                {{ __('Fix readiness blockers') }}
                            </a>
                        @endif
                    </div>
                </section>
            </div>

            <aside class="space-y-6">
                <section class="rounded-lg bg-white p-5 shadow">
                    <h2 class="font-semibold text-slate-900">Readiness</h2>
                    <div class="mt-3 flex items-center justify-between gap-3">
                        <span class="rounded-full border px-3 py-1 text-sm font-semibold {{ $readinessTone }}">
                            {{ str_replace('_', ' ', ucfirst($readiness->status->value)) }}
                        </span>
                        <span class="text-lg font-bold text-slate-900">{{ $readiness->score }}%</span>
                    </div>
                    <p class="mt-3 text-xs text-slate-600">
                        {{ __('Red blockers prevent publishing. Yellow warnings do not block publishing but should be reviewed.') }}
                    </p>
                    <a href="{{ route('catalog.products.passport.readiness', $product->uuid) }}" class="mt-4 inline-flex text-sm font-semibold text-indigo-700 hover:underline">
                        {{ __('View readiness report') }} &rarr;
                    </a>
                </section>

                <section class="rounded-lg bg-white p-5 shadow">
                    <h2 class="font-semibold text-slate-900">{{ __('Actions') }}</h2>
                    <div class="mt-4 flex flex-col gap-2">
                        <a href="{{ route('catalog.products.passport.qr.show', $product->uuid) }}" class="rounded-lg border border-indigo-300 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">
                            {{ __('View QR') }}
                        </a>
                        <a href="{{ route('catalog.products.passport.versions.index', $product->uuid) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            {{ __('Version history') }}
                        </a>

                        @if($canPublish && $passport->isPublished())
                            <form method="POST" action="{{ route('catalog.products.passport.unpublish', $product->uuid) }}" class="mt-2">
                                @csrf
                                <button type="submit" class="w-full rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50" onclick="return confirm('{{ __('Unpublish this passport? The QR stays valid, but the public page will be unavailable.') }}')">
                                    {{ __('Unpublish passport') }}
                                </button>
                            </form>
                        @endif
                    </div>
                </section>
            </aside>
        </div>
    @endif
</div>
</x-app-layout>
