<x-app-layout>
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Product Passport: {{ $product->name }}</h1>
        <a href="{{ route('catalog.products.show', $product->uuid) }}" class="text-blue-600 hover:underline">Back to Product</a>
    </div>

    @if($passport === null || !isset($editorData))
        <div class="bg-white shadow rounded-lg p-8 text-center">
            <p class="text-gray-600 mb-4">No passport draft exists yet for this product.</p>
            <form method="POST" action="{{ route('catalog.products.passport.store', $product->uuid) }}">
                @csrf
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700" {{ $canManage ? '' : 'disabled' }}>
                    Create Passport Draft
                </button>
            </form>
        </div>
    @else
        <div class="bg-white shadow rounded-lg p-6">
            <div class="mb-4">
                <p><strong>Status:</strong> {{ $passport->status->value }}</p>
                <p><strong>Language:</strong> {{ $passport->default_language }}</p>
                <p><strong>Schema Version:</strong> {{ $editorData['schema_version'] ?? 'N/A' }}</p>
                <p><strong>Draft Revision:</strong> {{ $editorData['draft_revision'] ?? 'N/A' }}</p>
            </div>

            <div class="flex flex-wrap gap-2">
                @if($passport->isPublished() && $passport->currentPublishedVersion)
                    <a href="{{ route('public.passports.show', $passport->public_id) }}" target="_blank" rel="noopener noreferrer"
                       class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 text-sm">
                        Open Public Page
                    </a>
                    <a href="{{ route('catalog.products.passport.qr.show', $product->uuid) }}"
                       class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-sm">
                        View QR
                    </a>
                @elseif(!$passport->isPublished() && $passport->hasPublishedVersion())
                    <a href="{{ route('catalog.products.passport.qr.show', $product->uuid) }}"
                       class="bg-yellow-600 text-white px-4 py-2 rounded hover:bg-yellow-700 text-sm">
                        View QR
                    </a>
                @else
                    <a href="{{ route('catalog.products.passport.qr.show', $product->uuid) }}"
                       class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-sm">
                        View QR
                    </a>
                @endif

                @if($canManage)
                    <a href="{{ route('catalog.products.passport.edit', $product->uuid) }}" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 text-sm">
                        Edit Passport
                    </a>
                    <a href="{{ route('catalog.products.passport.versions.index', $product->uuid) }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700 text-sm">
                        Version History
                    </a>
                @endif
            </div>
        </div>
    @endif
</div>
</x-app-layout>
