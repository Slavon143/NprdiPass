<x-app-layout>
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold">Passport: {{ $product->name }}</h1>
            <span class="px-3 py-1 rounded-full text-sm font-semibold bg-green-100 text-green-800">Published</span>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('catalog.products.passport.edit', $product->uuid) }}" class="text-blue-600 hover:underline">Back to Editor</a>
            <a href="{{ route('catalog.products.show', $product->uuid) }}" class="text-blue-600 hover:underline">Back to Product</a>
        </div>
    </div>

    @php
        $publishedVersion = $passport->currentPublishedVersion;
        $currentDraft = $passport->currentDraftVersion;
        $draftMatchesPublished = false;

        if ($publishedVersion && $currentDraft && $currentDraft->isDraft()) {
            $draftPayload = $currentDraft->payload ?? [];
            $publishedPayload = $publishedVersion->payload ?? [];
            $draftMatchesPublished = $draftPayload === $publishedPayload;
        }
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Published Version Info --}}
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-xl font-semibold mb-4">Published Version</h2>
            <dl class="space-y-2 text-sm">
                <dt class="font-medium">Version Number</dt>
                <dd class="text-gray-600">{{ $publishedVersion?->version_number ?? 'N/A' }}</dd>

                <dt class="font-medium">Published Date</dt>
                <dd class="text-gray-600">{{ $publishedVersion?->published_at?->toISOString() ?? 'N/A' }}</dd>

                <dt class="font-medium">Snapshot Checksum</dt>
                <dd class="text-gray-600 font-mono text-xs">{{ $publishedVersion?->content_checksum ?? 'N/A' }}</dd>

                <dt class="font-medium">Schema Version</dt>
                <dd class="text-gray-600">{{ $publishedVersion?->schema_version ?? 'N/A' }}</dd>
            </dl>
        </div>

        {{-- Actions --}}
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-xl font-semibold mb-4">Actions</h2>
            <div class="space-y-3">
                @if($passport->isPublished() && $publishedVersion)
                    <div class="mb-3 pb-3 border-b">
                        <a href="{{ route('public.passports.show', $publishedVersion->passport->public_id) }}"
                           target="_blank" rel="noopener noreferrer"
                           class="inline-flex items-center gap-1 text-indigo-600 hover:underline text-sm font-medium">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z" /><path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z" /></svg>
                            Open Public Page
                        </a>
                        <button type="button"
                                onclick="navigator.clipboard.writeText('{{ route('public.passports.show', $publishedVersion->passport->public_id) }}').then(function(){this.textContent='Public link copied';var el=this;setTimeout(function(){el.textContent='Copy public link';},2000)}.bind(this))"
                                class="block mt-1 text-xs text-slate-500 hover:text-slate-700 cursor-pointer">
                            Copy public link
                        </button>
                    </div>
                @endif

                <a href="{{ route('catalog.products.passport.versions.show', ['product' => $product->uuid, 'version' => $publishedVersion?->uuid ?? '']) }}"
                   class="inline-block text-blue-600 hover:underline text-sm">
                    View Published Version Detail
                </a>

                <a href="{{ route('catalog.products.passport.versions.index', $product->uuid) }}"
                   class="inline-block text-blue-600 hover:underline text-sm">
                    View Version History
                </a>
            </div>

            @if($canPublish)
                <div class="mt-4 pt-4 border-t">
                    <form method="POST" action="{{ route('catalog.products.passport.unpublish', $product->uuid) }}">
                        @csrf
                        <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700 text-sm"
                                onclick="return confirm('Are you sure you want to unpublish this passport?')">
                            Unpublish Passport
                        </button>
                    </form>
                </div>
            @endif
        </div>

        {{-- Draft Status --}}
        @if($currentDraft && $currentDraft->isDraft())
        <div class="bg-white shadow rounded-lg p-6 lg:col-span-2">
            <h2 class="text-xl font-semibold mb-4">Current Draft</h2>

            @if($draftMatchesPublished)
                <div class="flex items-center gap-2 mb-2">
                    <span class="px-3 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800">Draft Revision #{{ $currentDraft->draft_revision }}</span>
                    <span class="text-sm text-gray-600">Next draft matches published version</span>
                </div>
            @else
                <div class="flex items-center gap-2 mb-2">
                    <span class="px-3 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800">Draft Revision #{{ $currentDraft->draft_revision }}</span>
                    <span class="text-sm text-gray-600">Draft has been modified since publication</span>
                </div>
                <a href="{{ route('catalog.products.passport.edit', $product->uuid) }}"
                   class="inline-block mt-2 bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-sm">
                    Continue Editing
                </a>
            @endif
        </div>
        @endif
    </div>
</div>
</x-app-layout>
