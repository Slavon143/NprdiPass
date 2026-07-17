<x-app-layout>
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Passport Version History: {{ $product->name }}</h1>
        <div>
            <a href="{{ route('catalog.products.passport.show', $product->uuid) }}" class="text-blue-600 hover:underline mr-4">Back to Passport</a>
            <a href="{{ route('catalog.products.show', $product->uuid) }}" class="text-blue-600 hover:underline">Back to Product</a>
        </div>
    </div>

    <div class="bg-white shadow rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Version #</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Status</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Published Date</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Source Revision</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($versions as $version)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium">{{ $version->version_number ?? 'N/A' }}</td>
                    <td class="px-4 py-3">
                        @if($version->isPublished())
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800">Published</span>
                        @elseif($version->isSuperseded())
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-800">Superseded</span>
                        @elseif($version->isWithdrawn())
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800">Withdrawn</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-gray-600">
                        {{ $version->published_at?->toISOString() ?? 'N/A' }}
                    </td>
                    <td class="px-4 py-3 text-gray-600">
                        #{{ $version->draft_revision }}
                    </td>
                    <td class="px-4 py-3">
                        <a href="{{ route('catalog.products.passport.versions.show', ['product' => $product->uuid, 'version' => $version->uuid]) }}"
                           class="text-indigo-600 hover:underline font-medium">
                            View Detail
                        </a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-gray-500">No published versions yet.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
</x-app-layout>
