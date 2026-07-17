<x-app-layout>
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold">Version {{ $version->version_number ?? 'N/A' }}: {{ $product->name }}</h1>
            <span class="px-3 py-1 rounded-full text-sm font-semibold @if($version->isPublished()) bg-green-100 text-green-800 @elseif($version->isSuperseded()) bg-blue-100 text-blue-800 @elseif($version->isWithdrawn()) bg-red-100 text-red-800 @endif">
                {{ ucfirst($version->status->value) }}
            </span>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('catalog.products.passport.versions.index', $product->uuid) }}" class="text-blue-600 hover:underline">Version History</a>
            <a href="{{ route('catalog.products.passport.show', $product->uuid) }}" class="text-blue-600 hover:underline">Back to Passport</a>
        </div>
    </div>

    <div class="space-y-6">
        {{-- Publication Metadata --}}
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-xl font-semibold mb-4">Publication Metadata</h2>
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="font-medium text-gray-700">Version Number</dt>
                    <dd class="text-gray-600">{{ $version->version_number ?? 'N/A' }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-700">Status</dt>
                    <dd class="text-gray-600">{{ ucfirst($version->status->value) }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-700">Published At</dt>
                    <dd class="text-gray-600">{{ $version->published_at?->toISOString() ?? 'N/A' }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-700">Published By</dt>
                    <dd class="text-gray-600">{{ $version->publisher?->name ?? 'N/A' }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-700">Draft Revision</dt>
                    <dd class="text-gray-600">#{{ $version->draft_revision }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-700">Schema Version</dt>
                    <dd class="text-gray-600">{{ $version->schema_version }}</dd>
                </div>
                @if($version->superseded_at)
                <div>
                    <dt class="font-medium text-gray-700">Superseded At</dt>
                    <dd class="text-gray-600">{{ $version->superseded_at->toISOString() }}</dd>
                </div>
                @endif
                @if($version->withdrawn_at)
                <div>
                    <dt class="font-medium text-gray-700">Withdrawn At</dt>
                    <dd class="text-gray-600">{{ $version->withdrawn_at->toISOString() }}</dd>
                </div>
                @endif
                <div class="col-span-2">
                    <dt class="font-medium text-gray-700">Content Checksum</dt>
                    <dd class="text-gray-600 font-mono text-xs break-all">{{ $version->content_checksum ?? 'N/A' }}</dd>
                </div>
            </dl>
        </div>

        {{-- Product Info --}}
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-xl font-semibold mb-4">Product Information</h2>
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="font-medium text-gray-700">Product Name</dt>
                    <dd class="text-gray-600">{{ $product->name }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-700">Brand</dt>
                    <dd class="text-gray-600">{{ $product->brand ?? 'N/A' }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-700">Manufacturer</dt>
                    <dd class="text-gray-600">{{ $product->manufacturer ?? 'N/A' }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-700">Status</dt>
                    <dd class="text-gray-600">{{ $product->status->value }}</dd>
                </div>
            </dl>
        </div>

        {{-- DPP Sections (Snapshot) --}}
        @php
            $payload = $version->payload ?? [];
            $sections = $payload['data'] ?? [];
            $translations = $payload['translations'] ?? [];
            $enabledSections = $payload['enabled_sections'] ?? array_keys($sections);
        @endphp

        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-xl font-semibold mb-4">DPP Sections (Snapshot)</h2>

            @if(empty($sections) && empty($translations))
                <p class="text-gray-500 text-sm">No DPP section data in this snapshot.</p>
            @else
                <div class="space-y-4">
                    @foreach($sections as $sectionKey => $sectionData)
                        @if(in_array($sectionKey, $enabledSections))
                        <div class="border rounded-lg p-4">
                            <h3 class="text-lg font-medium mb-2">{{ $sectionKey }}</h3>
                            @if(!empty($sectionData))
                            <dl class="space-y-1 text-sm">
                                @foreach($sectionData as $field => $value)
                                    <div class="grid grid-cols-3 gap-2">
                                        <dt class="font-medium text-gray-700">{{ $field }}</dt>
                                        <dd class="col-span-2 text-gray-600">{{ is_array($value) ? json_encode($value) : ($value ?? 'N/A') }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                            @else
                                <p class="text-gray-500 text-sm">No data in this section.</p>
                            @endif
                        </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Documents --}}
        @php
            $docRefs = $payload['document_references'] ?? [];
        @endphp
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-xl font-semibold mb-4">Documents</h2>
            @if(!empty($docRefs))
                <ul class="space-y-2 text-sm">
                    @foreach($docRefs as $ref)
                        <li class="border-b pb-1">
                            <span class="font-medium">{{ $ref['document_uuid'] ?? 'N/A' }}</span>
                            @if(isset($ref['role']))
                                <span class="text-gray-500">({{ $ref['role'] }})</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-gray-500 text-sm">No documents referenced in this snapshot.</p>
            @endif
        </div>

        {{-- Media --}}
        @php
            $mediaRefs = $payload['media_references'] ?? [];
        @endphp
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-xl font-semibold mb-4">Media</h2>
            @if(!empty($mediaRefs))
                <ul class="space-y-2 text-sm">
                    @foreach($mediaRefs as $ref)
                        <li class="border-b pb-1">
                            <span class="font-medium">{{ $ref['media_uuid'] ?? 'N/A' }}</span>
                            @if(isset($ref['role']))
                                <span class="text-gray-500">({{ $ref['role'] }})</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-gray-500 text-sm">No media assets referenced in this snapshot.</p>
            @endif
        </div>

        {{-- Checksums --}}
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-xl font-semibold mb-4">Checksums</h2>
            <dl class="space-y-2 text-sm">
                <div>
                    <dt class="font-medium text-gray-700">Content Checksum</dt>
                    <dd class="text-gray-600 font-mono text-xs break-all">{{ $version->content_checksum ?? 'N/A' }}</dd>
                </div>
            </dl>
        </div>
    </div>
</div>
</x-app-layout>
