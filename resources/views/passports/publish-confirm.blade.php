<x-app-layout>
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Publish Passport: {{ $product->name }}</h1>
        <div>
            <a href="{{ route('catalog.products.passport.edit', $product->uuid) }}" class="text-blue-600 hover:underline mr-4">Back to Editor</a>
            <a href="{{ route('catalog.products.passport.show', $product->uuid) }}" class="text-blue-600 hover:underline">Back to Passport</a>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Draft Summary --}}
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-xl font-semibold mb-4">Draft Summary</h2>
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="font-medium text-gray-700">Current Draft Revision</dt>
                    <dd class="text-gray-600">#{{ $draft->draft_revision }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-700">Schema Version</dt>
                    <dd class="text-gray-600">{{ $draft->schema_version }}</dd>
                </div>
            </dl>
        </div>

        {{-- Readiness Score --}}
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-xl font-semibold mb-4">Readiness Score</h2>
            <div class="flex items-center gap-4 mb-3">
                <span class="px-3 py-1 rounded-full text-sm font-semibold @if($readiness->status->value === 'ready') bg-green-100 text-green-800 @elseif($readiness->status->value === 'ready_with_warnings') bg-amber-100 text-amber-800 @else bg-red-100 text-red-800 @endif">
                    {{ str_replace('_', ' ', ucfirst($readiness->status->value)) }}
                </span>
                <span class="text-lg font-bold">{{ $readiness->score }}%</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-4">
                <div class="h-4 rounded-full @if($readiness->score >= 80) bg-green-500 @elseif($readiness->score >= 50) bg-amber-500 @else bg-red-500 @endif" style="width: {{ $readiness->score }}%"></div>
            </div>
        </div>

        {{-- Counts --}}
        <div class="bg-white shadow rounded-lg p-6 lg:col-span-2">
            <h2 class="text-xl font-semibold mb-4">Validation Summary</h2>
            <div class="flex flex-wrap gap-3 text-sm">
                <span class="px-3 py-1 bg-red-100 text-red-800 rounded-full font-semibold">Blockers: {{ $readiness->counts->blockers }}</span>
                <span class="px-3 py-1 bg-amber-100 text-amber-800 rounded-full font-semibold">Warnings: {{ $readiness->counts->warnings }}</span>
                <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full font-semibold">Recommendations: {{ $readiness->counts->recommendations }}</span>
                <span class="px-3 py-1 bg-green-100 text-green-800 rounded-full font-semibold">Passed: {{ $readiness->counts->passed }}</span>
                <span class="px-3 py-1 bg-gray-100 text-gray-600 rounded-full font-semibold">Not Applicable: {{ $readiness->counts->notApplicable }}</span>
            </div>
            @include('passports.partials.readiness-score-breakdown', ['readiness' => $readiness])
        </div>

        {{-- Documents & Media Count --}}
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-xl font-semibold mb-4">Content</h2>
            <dl class="space-y-3 text-sm">
                @php
                    $docRefs = $draft->payload['document_references'] ?? [];
                    $mediaRefs = $draft->payload['media_references'] ?? [];
                    $enabledSections = $draft->payload['enabled_sections'] ?? [];
                @endphp
                <div>
                    <dt class="font-medium text-gray-700">Documents Count</dt>
                    <dd class="text-gray-600">{{ count($docRefs) }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-700">Media Assets Count</dt>
                    <dd class="text-gray-600">{{ count($mediaRefs) }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-700">Enabled Sections</dt>
                    <dd class="text-gray-600">{{ count($enabledSections) }}</dd>
                </div>
            </dl>
        </div>

        {{-- Estimated Version Number --}}
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-xl font-semibold mb-4">Estimated Version</h2>
            @php
                $latestVersion = $passport->versions()
                    ->whereNotNull('version_number')
                    ->orderByDesc('version_number')
                    ->first();
                $estimatedVersion = $latestVersion ? $latestVersion->version_number + 1 : 1;
            @endphp
            <p class="text-lg font-bold text-indigo-600">Version {{ $estimatedVersion }}</p>
        </div>

        {{-- Publish Form --}}
        @if($canPublish && $readiness->status->value !== 'not_ready')
        <div class="bg-white shadow rounded-lg p-6 lg:col-span-2">
            <h2 class="text-xl font-semibold mb-4">Confirm Publication</h2>

            <form method="POST" action="{{ route('catalog.products.passport.publish', $product->uuid) }}">
                @csrf
                <input type="hidden" name="expected_revision" value="{{ $draft->draft_revision }}">

                @if($readiness->counts->warnings > 0)
                <div class="mb-4">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="acknowledge_warnings" value="1" class="rounded border-gray-300">
                        <span class="text-gray-700">
                            I acknowledge the <strong>{{ $readiness->counts->warnings }}</strong> warning(s) and wish to proceed with publication.
                        </span>
                    </label>
                </div>
                @endif

                <div class="flex items-center gap-4">
                    <button type="submit" class="bg-indigo-600 text-white px-6 py-2 rounded hover:bg-indigo-700 text-sm font-semibold">
                        Publish Passport
                    </button>
                    <a href="{{ route('catalog.products.passport.edit', $product->uuid) }}" class="text-gray-600 hover:underline text-sm">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
        @endif

        @if($readiness->status->value === 'not_ready')
        <div class="bg-white shadow rounded-lg p-6 lg:col-span-2">
            <div class="flex items-center gap-2 text-red-700">
                <span class="font-semibold">Cannot publish:</span>
                <span>Passport is not ready. Resolve all blockers before publishing.</span>
            </div>
        </div>
        @endif
    </div>
</div>
</x-app-layout>
