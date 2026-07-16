<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Catalog') }} / {{ $product->name }} / {{ __('Documents') }}</p>
                <h1 class="mt-1 text-xl font-bold tracking-tight text-slate-900">{{ $document->currentVersion?->title ?? __('Document') }}</h1>
            </div>
            <div class="flex items-center gap-3">
                @if($document->isArchived())
                    <x-badge tone="amber">{{ __('Archived') }}</x-badge>
                    @if($canManage)
                    <form method="POST" action="{{ route('catalog.products.documents.restore', [$product->uuid, $document->uuid]) }}">
                        @csrf
                        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">{{ __('Restore') }}</button>
                    </form>
                    @endif
                @else
                    <x-badge tone="emerald">{{ __('Active') }}</x-badge>
                    @if($canManage)
                    <form method="POST" action="{{ route('catalog.products.documents.archive', [$product->uuid, $document->uuid]) }}" onsubmit="return confirm('{{ __('Archive this document?') }}')">
                        @csrf
                        <button type="submit" class="rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">{{ __('Archive') }}</button>
                    </form>
                    @endif
                @endif
            </div>
        </div>
    </x-slot>

    <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
        <a href="{{ route('catalog.products.documents.index', $product->uuid) }}" class="text-sm text-indigo-600 hover:text-indigo-900">{{ __('← Back to documents') }}</a>

        @if($document->currentVersion)
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Current version') }} #{{ $document->currentVersion->version_number }}</h2>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                <div><dt class="text-sm text-slate-500">{{ __('Type') }}</dt><dd class="font-semibold text-slate-900">{{ $document->currentVersion->document_type->label() }}</dd></div>
                <div><dt class="text-sm text-slate-500">{{ __('Language') }}</dt><dd class="font-semibold text-slate-900">{{ strtoupper($document->currentVersion->language) }}</dd></div>
                <div><dt class="text-sm text-slate-500">{{ __('Visibility') }}</dt><dd class="font-semibold text-slate-900">{{ $document->currentVersion->visibility->value }}</dd></div>
                <div><dt class="text-sm text-slate-500">{{ __('File') }}</dt><dd class="font-semibold text-slate-900">{{ $document->currentVersion->original_filename }} ({{ number_format($document->currentVersion->size_bytes / 1024, 1) }} KB)</dd></div>
                @if($document->currentVersion->issuer_name)<div><dt class="text-sm text-slate-500">{{ __('Issuer') }}</dt><dd class="font-semibold text-slate-900">{{ $document->currentVersion->issuer_name }}</dd></div>@endif
                @if($document->currentVersion->issue_date)<div><dt class="text-sm text-slate-500">{{ __('Issue date') }}</dt><dd class="font-semibold text-slate-900">{{ $document->currentVersion->issue_date->format('Y-m-d') }}</dd></div>@endif
                @if($document->currentVersion->expires_at)
                <div>
                    <dt class="text-sm text-slate-500">{{ __('Expires') }}</dt>
                    <dd class="font-semibold text-slate-900">
                        {{ $document->currentVersion->expires_at->format('Y-m-d') }}
                        @if($document->currentVersion->isExpired())<x-badge tone="red" class="ml-1">{{ __('Expired') }}</x-badge>@endif
                        @if($document->currentVersion->expiresSoon())<x-badge tone="amber" class="ml-1">{{ __('Expiring soon') }}</x-badge>@endif
                    </dd>
                </div>
                @endif
                @if($document->currentVersion->description)<div class="sm:col-span-2"><dt class="text-sm text-slate-500">{{ __('Description') }}</dt><dd class="mt-1 whitespace-pre-line text-slate-800">{{ $document->currentVersion->description }}</dd></div>@endif
            </dl>
            <div class="mt-4 border-t border-slate-200 pt-4">
                <a href="{{ route('catalog.products.documents.versions.download', [$product->uuid, $document->uuid, $document->currentVersion->uuid]) }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">{{ __('Download') }}</a>
            </div>
        </div>
        @endif

        @if($canManage && !$document->isArchived())
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Add new version') }}</h2>
            <form method="POST" action="{{ route('catalog.products.documents.versions.store', [$product->uuid, $document->uuid]) }}" enctype="multipart/form-data" class="mt-4 space-y-4">
                @csrf
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="new_title" :value="__('Title')" />
                        <x-text-input id="new_title" name="title" type="text" class="mt-1 block w-full" required maxlength="500" />
                    </div>
                    <div>
                        <x-input-label for="new_document_type" :value="__('Type')" />
                        <select id="new_document_type" name="document_type" class="mt-1 block w-full rounded-lg border-slate-300" required>
                            @foreach(\App\Enums\Documents\ProductDocumentType::cases() as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="new_language" :value="__('Language')" />
                        <select id="new_language" name="language" class="mt-1 block w-full rounded-lg border-slate-300" required>
                            <option value="sv">Svenska (sv)</option>
                            <option value="en">English (en)</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="new_visibility" :value="__('Visibility')" />
                        <select id="new_visibility" name="visibility" class="mt-1 block w-full rounded-lg border-slate-300" required>
                            <option value="internal">Internal</option>
                            <option value="passport_public">Passport public</option>
                        </select>
                    </div>
                </div>
                <div>
                    <x-input-label for="new_file" :value="__('PDF file')" />
                    <input type="file" id="new_file" name="file" accept=".pdf,application/pdf" required class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 file:mr-4 file:rounded-md file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-indigo-700" />
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">{{ __('Upload new version') }}</button>
                </div>
            </form>
        </div>
        @endif

        @if($document->versions->count() > 1)
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="p-6">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Version history') }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">#</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">{{ __('Title') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold uppercase text-slate-500">{{ __('Uploaded') }}</th>
                            <th class="px-4 py-2 text-right text-xs font-semibold uppercase text-slate-500">{{ __('Download') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($document->versions as $version)
                        <tr class="hover:bg-slate-50 @if($document->current_version_id === $version->getKey()) bg-indigo-50 @endif">
                            <td class="px-4 py-2 text-sm font-mono text-slate-600">{{ $version->version_number }}</td>
                            <td class="px-4 py-2 text-sm text-slate-900">{{ $version->title }}</td>
                            <td class="px-4 py-2 text-sm text-slate-500">{{ $version->created_at->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-2 text-right">
                                <a href="{{ route('catalog.products.documents.versions.download', [$product->uuid, $document->uuid, $version->uuid]) }}" class="text-xs font-semibold text-indigo-700 hover:text-indigo-900">{{ __('Download') }}</a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>
</x-app-layout>
