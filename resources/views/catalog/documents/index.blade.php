<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Catalog') }} / {{ $product->name }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Documents') }}</h1>
            </div>
            @if($canManage)
            <a href="{{ route('catalog.products.documents.create', $product->uuid) }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">{{ __('Add document') }}</a>
            @endif
        </div>
    </x-slot>

    <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
        <a href="{{ route('catalog.products.show', $product->uuid) }}" class="text-sm text-indigo-600 hover:text-indigo-900">{{ __('← Back to product') }}</a>

        <div id="product-documents" class="mt-6 scroll-mt-24 rounded-2xl border border-slate-200 bg-white shadow-sm target:ring-4 target:ring-amber-100">
            @if($documents->isEmpty())
            <div class="flex flex-col items-center justify-center px-6 py-16 text-center">
                <p class="text-lg font-semibold text-slate-600">{{ __('No documents yet') }}</p>
                <p class="mt-1 text-sm text-slate-500">{{ __('Upload PDF documents, certificates, and declarations for this product.') }}</p>
                @if($canManage)
                <a href="{{ route('catalog.products.documents.create', $product->uuid) }}" class="mt-4 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">{{ __('Add your first document') }}</a>
                @endif
            </div>
            @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">{{ __('Title') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">{{ __('Type') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">{{ __('Language') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">{{ __('Status') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">{{ __('Expires') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-slate-500">{{ __('Versions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($documents as $document)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('catalog.products.documents.show', [$product->uuid, $document->uuid]) }}" class="font-medium text-indigo-700 hover:text-indigo-900">
                                    {{ $document->currentVersion?->title ?? __('Untitled') }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600">
                                <x-badge tone="slate">{{ $document->currentVersion?->document_type?->label() ?? '-' }}</x-badge>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600">{{ strtoupper($document->currentVersion?->language ?? '-') }}</td>
                            <td class="px-4 py-3">
                                @if($document->isArchived())
                                <x-badge tone="amber">{{ __('Archived') }}</x-badge>
                                @else
                                <x-badge tone="emerald">{{ __('Active') }}</x-badge>
                                @endif
                                @if($document->currentVersion?->expires_at)
                                    @if($document->currentVersion->isExpired())
                                    <x-badge tone="red" class="ml-1">{{ __('Expired') }}</x-badge>
                                    @elseif($document->currentVersion->expiresSoon())
                                    <x-badge tone="amber" class="ml-1">{{ __('Expiring soon') }}</x-badge>
                                    @endif
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-600">
                                {{ $document->currentVersion?->expires_at?->format('Y-m-d') ?? '-' }}
                            </td>
                            <td class="px-4 py-3 text-right text-sm text-slate-600">{{ $document->versions_count }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>

        @if($documents->hasPages())
        <div class="mt-4">
            {{ $documents->links() }}
        </div>
        @endif
    </div>
</x-app-layout>
