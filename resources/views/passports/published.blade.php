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
