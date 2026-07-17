@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Edit Product Passport: {{ $product->name }}</h1>
        <a href="{{ route('catalog.products.passport.show', $product->uuid) }}" class="text-blue-600 hover:underline">View Passport</a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <div class="bg-white shadow rounded-lg p-6">
                <div class="mb-4 flex justify-between items-center">
                    <h2 class="text-xl font-semibold">DPP Sections</h2>
                    <span>Revision: <strong id="draftRevision">{{ $editorData['draft_revision'] ?? 'N/A' }}</strong></span>
                </div>

                <div class="space-y-6" id="sectionsContainer">
                    @foreach($sectionKeys as $sectionKey)
                        @php
                            $sectionDef = $sections[$sectionKey] ?? null;
                            if(!$sectionDef) continue;
                            $isEnabled = in_array($sectionKey, $editorData['payload']['enabled_sections'] ?? []);
                        @endphp
                        <div class="border rounded-lg p-4 {{ $isEnabled ? '' : 'opacity-50' }}" data-section="{{ $sectionKey }}">
                            <div class="flex justify-between items-center mb-2">
                                <h3 class="text-lg font-medium">
                                    {{ $sectionDef->key->label() }}
                                    @if($sectionDef->core)
                                        <span class="text-xs bg-gray-200 rounded px-2 py-0.5">Core</span>
                                    @endif
                                </h3>
                                @if(!$sectionDef->core && $canManage)
                                    <button type="button" class="section-toggle text-sm text-blue-600 hover:underline"
                                            data-section="{{ $sectionKey }}"
                                            data-action="{{ $isEnabled ? 'disable' : 'enable' }}">
                                        {{ $isEnabled ? 'Disable' : 'Enable' }}
                                    </button>
                                @endif
                            </div>

                            @if($isEnabled)
                                <div class="section-fields space-y-4">
                                    @foreach($sectionDef->fields as $field)
                                        @php
                                            $value = null;
                                            if($sectionDef->translatable) {
                                                $value = $editorData['payload']['translations'][$passport->default_language][$sectionKey][$field->key] ?? null;
                                            } else {
                                                $value = $editorData['payload']['data'][$sectionKey][$field->key] ?? null;
                                            }
                                        @endphp
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700">
                                                {{ $field->key }}
                                                @if($field->translatable)
                                                    <span class="text-xs bg-blue-100 rounded px-1">translatable</span>
                                                @endif
                                            </label>
                                            @switch($field->type->value)
                                                @case('boolean')
                                                    <input type="checkbox" name="{{ $field->key }}" class="section-input mt-1"
                                                           data-field="{{ $field->key }}"
                                                           {{ $value ? 'checked' : '' }}
                                                           {{ $canManage ? '' : 'disabled' }}>
                                                    @break
                                                @case('long_text')
                                                    <textarea name="{{ $field->key }}" rows="3" class="section-input mt-1 w-full border rounded px-3 py-2"
                                                              data-field="{{ $field->key }}"
                                                              {{ $canManage ? '' : 'disabled' }}>{{ $value ?? '' }}</textarea>
                                                    @break
                                                @default
                                                    <input type="text" name="{{ $field->key }}" class="section-input mt-1 w-full border rounded px-3 py-2"
                                                           data-field="{{ $field->key }}"
                                                           value="{{ $value ?? '' }}"
                                                           {{ $canManage ? '' : 'disabled' }}>
                                            @endswitch
                                            @if($field->maxLength)
                                                <span class="text-xs text-gray-400">Max: {{ $field->maxLength }} chars</span>
                                            @endif
                                        </div>
                                    @endforeach

                                    @if($canManage)
                                        <div class="flex gap-2 pt-2">
                                            <button type="button" class="save-section bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700"
                                                    data-section="{{ $sectionKey }}">
                                                Save Section
                                            </button>
                                            @if(!$sectionDef->core)
                                                <button type="button" class="reset-section bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700"
                                                        data-section="{{ $sectionKey }}">
                                                    Reset
                                                </button>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="lg:col-span-1">
            <div class="bg-white shadow rounded-lg p-6">
                <h2 class="text-xl font-semibold mb-4">Catalog Context</h2>
                <dl class="space-y-2 text-sm">
                    <dt class="font-medium">Product</dt>
                    <dd class="text-gray-600">{{ $catalogContext['product_name'] ?? 'N/A' }}</dd>
                    <dt class="font-medium">Brand</dt>
                    <dd class="text-gray-600">{{ $catalogContext['brand'] ?? 'N/A' }}</dd>
                    <dt class="font-medium">Manufacturer</dt>
                    <dd class="text-gray-600">{{ $catalogContext['manufacturer'] ?? 'N/A' }}</dd>
                    <dt class="font-medium">Status</dt>
                    <dd class="text-gray-600">{{ $catalogContext['status'] ?? 'N/A' }}</dd>
                </dl>

                @if(!empty($catalogContext['default_variant']))
                    <h3 class="text-lg font-semibold mt-4 mb-2">Default Variant</h3>
                    <dl class="space-y-1 text-sm">
                        <dt class="font-medium">SKU</dt>
                        <dd class="text-gray-600">{{ $catalogContext['default_variant']['sku'] ?? 'N/A' }}</dd>
                        <dt class="font-medium">GTIN</dt>
                        <dd class="text-gray-600">{{ $catalogContext['default_variant']['gtin'] ?? 'N/A' }}</dd>
                        <dt class="font-medium">MPN</dt>
                        <dd class="text-gray-600">{{ $catalogContext['default_variant']['mpn'] ?? 'N/A' }}</dd>
                    </dl>
                @endif

                @if(!empty($catalogContext['categories']))
                    <h3 class="text-lg font-semibold mt-4 mb-2">Categories</h3>
                    <ul class="list-disc list-inside text-sm text-gray-600">
                        @foreach($catalogContext['categories'] as $category)
                            <li>{{ $category['name'] }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="bg-white shadow rounded-lg p-6 mt-4">
                <h2 class="text-xl font-semibold mb-4">Documents</h2>
                <div id="documentReferencesContainer">
                    @php
                        $docRefs = $editorData['payload']['document_references'] ?? [];
                    @endphp
                    @foreach($docRefs as $ref)
                        <div class="text-sm mb-2 border-b pb-1">
                            {{ $ref['document_uuid'] }} - {{ $ref['role'] ?? 'other' }}
                        </div>
                    @endforeach
                    @if(empty($docRefs))
                        <p class="text-gray-500 text-sm">No documents referenced</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

@if($canManage)
<script>
document.addEventListener('DOMContentLoaded', function() {
    const productUuid = '{{ $product->uuid }}';
    const baseUrl = `/catalog/products/${productUuid}/passport`;

    function getCurrentRevision() {
        const el = document.getElementById('draftRevision');
        return el ? parseInt(el.textContent) : 0;
    }

    document.querySelectorAll('.save-section').forEach(btn => {
        btn.addEventListener('click', async function() {
            const section = this.dataset.section;
            const container = document.querySelector(`[data-section="${section}"]`);
            const inputs = container.querySelectorAll('.section-input');
            const payload = {};

            inputs.forEach(input => {
                if (input.type === 'checkbox') {
                    payload[input.dataset.field] = input.checked;
                } else {
                    payload[input.dataset.field] = input.value;
                }
            });

            const response = await fetch(`${baseUrl}/sections/${section}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                },
                body: JSON.stringify({
                    section_payload: payload,
                    expected_revision: getCurrentRevision(),
                }),
            });

            if (response.status === 409) {
                const data = await response.json();
                alert('Conflict: ' + (data.message || 'Revision mismatch. Please refresh.'));
                location.reload();
            } else if (response.ok) {
                const data = await response.json();
                document.getElementById('draftRevision').textContent = data.draft_revision;
                alert('Section saved!');
            } else {
                const data = await response.json();
                alert('Error saving section: ' + (data.message || 'Unknown error'));
            }
        });
    });

    document.querySelectorAll('.reset-section').forEach(btn => {
        btn.addEventListener('click', async function() {
            const section = this.dataset.section;
            if (!confirm(`Reset section "${section}"? All data will be cleared.`)) return;

            const response = await fetch(`${baseUrl}/sections/${section}/reset`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                },
                body: JSON.stringify({
                    expected_revision: getCurrentRevision(),
                }),
            });

            if (response.status === 409) {
                alert('Conflict: Revision mismatch. Please refresh.');
                location.reload();
            } else if (response.ok) {
                location.reload();
            }
        });
    });
});
</script>
@endif
@endsection
