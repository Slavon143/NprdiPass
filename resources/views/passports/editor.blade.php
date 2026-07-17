<x-app-layout>
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Edit Product Passport: {{ $product->name }}</h1>
        <a href="{{ route('catalog.products.passport.show', $product->uuid) }}" class="text-blue-600 hover:underline">View Passport</a>
    </div>

    {{-- Publication Section --}}
    <div class="bg-white shadow rounded-lg p-6 mb-6">
        <div class="flex justify-between items-center">
            <div>
                @if($passport->isDraft())
                    <span class="px-3 py-1 rounded-full text-sm font-semibold bg-gray-100 text-gray-800">Draft</span>
                    <a href="{{ route('catalog.products.passport.readiness', $product->uuid) }}"
                       class="ml-4 text-sm text-blue-600 hover:underline">
                        View Readiness
                    </a>
                @elseif($passport->isPublished())
                    <span class="px-3 py-1 rounded-full text-sm font-semibold bg-green-100 text-green-800">
                        Published &middot; Version {{ $passport->currentPublishedVersion?->version_number ?? 'N/A' }}
                    </span>
                    <a href="{{ route('catalog.products.passport.versions.show', ['product' => $product->uuid, 'version' => $passport->currentPublishedVersion?->uuid ?? '']) }}"
                       class="ml-4 text-sm text-blue-600 hover:underline">
                        View Published Version
                    </a>
                    <a href="{{ route('catalog.products.passport.versions.index', $product->uuid) }}"
                       class="ml-4 text-sm text-blue-600 hover:underline">
                        Version History
                    </a>
                @elseif($passport->isUnpublished())
                    <span class="px-3 py-1 rounded-full text-sm font-semibold bg-orange-100 text-orange-800">Unpublished</span>
                @elseif($passport->isArchived())
                    <span class="px-3 py-1 rounded-full text-sm font-semibold bg-red-100 text-red-800">Archived</span>
                @endif
            </div>
            @if($passport->isDraft() && $canManage)
                <a href="{{ route('catalog.products.passport.publish-confirm', $product->uuid) }}"
                   class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700 text-sm font-semibold">
                    Publish Passport
                </a>
            @elseif(!$canManage)
                <span class="text-sm text-gray-500 italic">Read-only</span>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <div class="bg-white shadow rounded-lg p-6">
                <div class="mb-4 flex justify-between items-center">
                    <h2 class="text-xl font-semibold">DPP Sections</h2>
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-600">Revision: <strong id="draftRevision">{{ $editorData['draft_revision'] ?? 'N/A' }}</strong></span>
                        <span id="readinessSummary" class="text-sm font-medium" aria-live="polite"></span>
                    </div>
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
                                    {{-- Section-level error summary --}}
                                    <div class="section-error-summary hidden" role="alert" aria-live="assertive">
                                        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"></div>
                                    </div>

                                    @foreach($sectionDef->fields as $field)
                                        @php
                                            if($sectionDef->translatable) {
                                                $value = $editorData['payload']['translations'][$passport->default_language][$sectionKey][$field->key] ?? null;
                                            } else {
                                                $value = $editorData['payload']['data'][$sectionKey][$field->key] ?? null;
                                            }
                                            $errorId = "error-{$sectionKey}-{$field->key}";
                                            $fieldId = "field-{$sectionKey}-{$field->key}";
                                            $inputType = match($field->type->value) {
                                                'boolean' => 'checkbox',
                                                'decimal', 'integer' => 'number',
                                                'date' => 'date',
                                                'email' => 'email',
                                                'url' => 'url',
                                                default => 'text',
                                            };
                                            $displayValue = $value;
                                            if (is_array($value)) {
                                                $displayValue = implode(', ', $value);
                                            }
                                        @endphp
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700" for="{{ $fieldId }}">
                                                {{ $field->key }}
                                                @if($field->translatable)
                                                    <span class="text-xs bg-blue-100 rounded px-1">translatable</span>
                                                @endif
                                            </label>

                                            @if($field->type->value === 'boolean')
                                                <input type="checkbox" name="{{ $field->key }}"
                                                       id="{{ $fieldId }}"
                                                       class="section-input mt-1"
                                                       data-field="{{ $field->key }}"
                                                       data-field-type="{{ $field->type->value }}"
                                                       aria-invalid="false"
                                                       aria-describedby="{{ $errorId }}"
                                                       {{ $value ? 'checked' : '' }}
                                                       {{ $canManage ? '' : 'disabled' }}>
                                            @elseif($field->type->value === 'long_text')
                                                <textarea name="{{ $field->key }}" rows="3"
                                                          id="{{ $fieldId }}"
                                                          class="section-input mt-1 w-full border rounded px-3 py-2"
                                                          data-field="{{ $field->key }}"
                                                          data-field-type="{{ $field->type->value }}"
                                                          @if($field->maxLength) maxlength="{{ $field->maxLength }}" @endif
                                                          aria-invalid="false"
                                                          aria-describedby="{{ $errorId }}"
                                                          {{ $canManage ? '' : 'disabled' }}>{{ $displayValue }}</textarea>
                                            @elseif($field->type->value === 'decimal' || $field->type->value === 'integer')
                                                <input type="number"
                                                       name="{{ $field->key }}"
                                                       id="{{ $fieldId }}"
                                                       class="section-input mt-1 w-full border rounded px-3 py-2"
                                                       data-field="{{ $field->key }}"
                                                       data-field-type="{{ $field->type->value }}"
                                                       value="{{ $displayValue }}"
                                                       @if($field->min !== null) min="{{ $field->min }}" @endif
                                                       @if($field->max !== null) max="{{ $field->max }}" @endif
                                                       step="any"
                                                       aria-invalid="false"
                                                       aria-describedby="{{ $errorId }}"
                                                       {{ $canManage ? '' : 'disabled' }}>
                                            @elseif($field->type->value === 'date')
                                                <input type="date"
                                                       name="{{ $field->key }}"
                                                       id="{{ $fieldId }}"
                                                       class="section-input mt-1 w-full border rounded px-3 py-2"
                                                       data-field="{{ $field->key }}"
                                                       data-field-type="{{ $field->type->value }}"
                                                       value="{{ $displayValue }}"
                                                       aria-invalid="false"
                                                       aria-describedby="{{ $errorId }}"
                                                       {{ $canManage ? '' : 'disabled' }}>
                                            @else
                                                <input type="{{ $inputType }}"
                                                       name="{{ $field->key }}"
                                                       id="{{ $fieldId }}"
                                                       class="section-input mt-1 w-full border rounded px-3 py-2"
                                                       data-field="{{ $field->key }}"
                                                       data-field-type="{{ $field->type->value }}"
                                                       value="{{ $displayValue }}"
                                                       @if($field->maxLength) maxlength="{{ $field->maxLength }}" @endif
                                                       autocomplete="{{ $field->type->value === 'email' ? 'email' : ($field->type->value === 'url' ? 'url' : 'off') }}"
                                                       aria-invalid="false"
                                                       aria-describedby="{{ $errorId }}"
                                                       {{ $canManage ? '' : 'disabled' }}>
                                            @endif

                                            <div id="{{ $errorId }}" class="field-error mt-1 text-sm text-red-600 hidden" role="alert"></div>

                                            @if($field->maxLength)
                                                <span class="text-xs text-gray-400">Max: {{ $field->maxLength }} chars</span>
                                            @endif
                                        </div>
                                    @endforeach

                                    @if($canManage)
                                        <div class="flex gap-2 pt-2 items-center">
                                            <input type="hidden" class="expected-revision" value="{{ $editorData['draft_revision'] ?? 0 }}" data-section="{{ $sectionKey }}">
                                            <button type="button"
                                                    class="save-section bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                                    data-section="{{ $sectionKey }}"
                                                    aria-label="Save section {{ $sectionDef->key->label() }}">
                                                Save Section
                                            </button>
                                            @if(!$sectionDef->core)
                                                <button type="button" class="reset-section bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700"
                                                        data-section="{{ $sectionKey }}">
                                                    Reset
                                                </button>
                                            @endif
                                            <span class="section-status text-sm text-gray-500 ml-2" data-section="{{ $sectionKey }}" aria-live="polite"></span>
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

<x-toast />

@if($canManage)
<input type="hidden" id="productUuid" value="{{ $product->uuid }}">
<input type="hidden" id="csrfToken" value="{{ csrf_token() }}">

<script>
(function() {
    const sectionStates = {};
    let globalRevision = 0;
    let hasUnsavedChanges = false;

    function init() {
        globalRevision = getGlobalRevision();

        document.querySelectorAll('.save-section').forEach(function(btn) {
            var section = btn.dataset.section;
            if (!section) return;
            sectionStates[section] = { dirty: false, saving: false, saved: false, error: false };
            btn.addEventListener('click', function() { saveSection(section); });
        });

        document.querySelectorAll('.reset-section').forEach(function(btn) {
            var section = btn.dataset.section;
            if (!section) return;
            btn.addEventListener('click', function() { resetSection(section); });
        });

        document.querySelectorAll('.section-input').forEach(function(input) {
            input.addEventListener('input', function() { onFieldChange(input); });
            input.addEventListener('change', function() { onFieldChange(input); });
        });

        window.addEventListener('beforeunload', onBeforeUnload);
    }

    function getGlobalRevision() {
        var el = document.getElementById('draftRevision');
        return el ? parseInt(el.textContent, 10) || 0 : 0;
    }

    function getExpectedRevision(section) {
        var el = document.querySelector('.expected-revision[data-section="' + section + '"]');
        return el ? parseInt(el.value, 10) || globalRevision : globalRevision;
    }

    function setAllExpectedRevisions(revision) {
        document.querySelectorAll('.expected-revision').forEach(function(el) {
            el.value = revision;
        });
    }

    function onFieldChange(input) {
        var section = input.closest('[data-section]');
        if (!section) return;
        var sectionKey = section.dataset.section;
        var state = sectionStates[sectionKey];
        if (!state || state.saving || state.saved) return;

        state.dirty = true;
        state.saved = false;
        hasUnsavedChanges = true;
        updateSaveButton(sectionKey);
    }

    function updateSaveButton(sectionKey) {
        var btn = document.querySelector('.save-section[data-section="' + sectionKey + '"]');
        if (!btn) return;
        var state = sectionStates[sectionKey];
        if (!state) return;

        btn.classList.remove('bg-blue-600', 'bg-green-600', 'bg-amber-500', 'opacity-50', 'cursor-not-allowed');

        if (state.saving) {
            btn.textContent = 'Saving\u2026';
            btn.disabled = true;
            btn.classList.add('bg-blue-600', 'opacity-50', 'cursor-not-allowed');
        } else if (state.saved && !state.dirty) {
            btn.textContent = 'Saved';
            btn.disabled = false;
            btn.classList.add('bg-green-600');
        } else if (state.dirty) {
            btn.textContent = 'Unsaved changes';
            btn.disabled = false;
            btn.classList.add('bg-blue-600');
        } else if (state.error) {
            btn.textContent = 'Save Section';
            btn.disabled = false;
            btn.classList.add('bg-amber-500');
        } else {
            btn.textContent = 'Save Section';
            btn.disabled = false;
            btn.classList.add('bg-blue-600');
        }
    }

    function updateSectionStatus(sectionKey, text, cls) {
        var status = document.querySelector('.section-status[data-section="' + sectionKey + '"]');
        if (!status) return;
        status.textContent = text;
        status.className = 'section-status text-sm ml-2 ' + (cls || 'text-gray-500');
    }

    function clearSectionErrors(sectionKey) {
        var container = document.querySelector('[data-section="' + sectionKey + '"]');
        if (!container) return;

        container.querySelectorAll('.field-error').forEach(function(el) {
            el.textContent = '';
            el.classList.add('hidden');
        });

        container.querySelectorAll('.section-input').forEach(function(input) {
            input.setAttribute('aria-invalid', 'false');
            input.classList.remove('border-red-500');
        });

        var summary = container.querySelector('.section-error-summary');
        if (summary) summary.classList.add('hidden');
    }

    function showFieldErrors(sectionKey, errors) {
        var container = document.querySelector('[data-section="' + sectionKey + '"]');
        if (!container) return;

        var errorCount = 0;
        var firstErrorInput = null;

        Object.keys(errors).forEach(function(fieldKey) {
            var messages = errors[fieldKey];

            if (fieldKey === 'section' || fieldKey === 'fields') {
                messages.forEach(function(msg) {
                    container.querySelectorAll('.section-input').forEach(function(input) {
                        var errEl = document.getElementById('error-' + sectionKey + '-' + (input.dataset.field || ''));
                        if (!errEl || !msg.includes(input.dataset.field)) return;
                        errEl.textContent = msg;
                        errEl.classList.remove('hidden');
                        input.setAttribute('aria-invalid', 'true');
                        input.classList.add('border-red-500');
                        errorCount++;
                        if (!firstErrorInput) firstErrorInput = input;
                    });
                });
                return;
            }

            var input = container.querySelector('.section-input[data-field="' + fieldKey + '"]');
            var errorEl = document.getElementById('error-' + sectionKey + '-' + fieldKey);

            if (input) {
                input.setAttribute('aria-invalid', 'true');
                input.classList.add('border-red-500');
                if (!firstErrorInput) firstErrorInput = input;
            }

            if (errorEl && Array.isArray(messages) && messages.length > 0) {
                errorEl.textContent = messages[0];
                errorEl.classList.remove('hidden');
                errorCount++;
            }
        });

        if (firstErrorInput) {
            firstErrorInput.focus({ preventScroll: false });
        }

        if (errorCount > 0) {
            showToast('error', 'Please correct ' + errorCount + ' field' + (errorCount !== 1 ? 's' : '') + '.', 6000);
        }
    }

    function showToast(type, message, duration) {
        window.dispatchEvent(new CustomEvent('toast', {
            detail: { type: type, message: message, duration: duration || 5000 }
        }));
    }

    function updateReadiness(readiness) {
        var el = document.getElementById('readinessSummary');
        if (!el) return;
        var statusText = readiness.status === 'ready' ? 'Ready' :
                         readiness.status === 'not_ready' ? 'Not ready' :
                         readiness.status === 'ready_with_warnings' ? 'Ready with warnings' : readiness.status;
        el.textContent = readiness.score + '% - ' + statusText;

        if (readiness.status === 'not_ready') {
            el.className = 'text-sm font-medium text-red-600';
        } else if (readiness.status === 'ready_with_warnings') {
            el.className = 'text-sm font-medium text-amber-600';
        } else {
            el.className = 'text-sm font-medium text-green-600';
        }
    }

    async function saveSection(sectionKey) {
        var state = sectionStates[sectionKey];
        if (!state || state.saving) return;

        state.saving = true;
        state.saved = false;
        state.error = false;
        updateSaveButton(sectionKey);
        updateSectionStatus(sectionKey, 'Saving\u2026', 'text-blue-600');
        clearSectionErrors(sectionKey);

        var container = document.querySelector('[data-section="' + sectionKey + '"]');
        if (!container) return;

        var inputs = container.querySelectorAll('.section-input');
        var payload = {};

        inputs.forEach(function(input) {
            var fieldKey = input.dataset.field;
            if (!fieldKey) return;
            var fieldType = input.dataset.fieldType || 'text';

            if (input.type === 'checkbox') {
                payload[fieldKey] = input.checked;
            } else if (fieldType === 'string_list') {
                payload[fieldKey] = input.value.split(',').map(function(s) { return s.trim(); }).filter(Boolean);
            } else if (fieldType === 'decimal' || fieldType === 'integer') {
                var val = input.value.trim();
                if (val === '') {
                    payload[fieldKey] = null;
                } else {
                    payload[fieldKey] = fieldType === 'integer' ? parseInt(val, 10) : parseFloat(val);
                }
            } else {
                payload[fieldKey] = input.value;
            }
        });

        var productUuid = document.getElementById('productUuid').value;
        var csrfToken = document.getElementById('csrfToken').value;
        var expectedRevision = getExpectedRevision(sectionKey);

        try {
            var response = await fetch('/catalog/products/' + productUuid + '/passport/sections/' + sectionKey, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    section_payload: payload,
                    expected_revision: expectedRevision,
                }),
            });

            if (response.status === 409) {
                state.saving = false;
                state.error = true;
                updateSaveButton(sectionKey);
                updateSectionStatus(sectionKey, '');
                handleConflict(await response.json());
                return;
            }

            if (response.status === 422) {
                var errData = await response.json();
                state.saving = false;
                state.error = true;
                updateSaveButton(sectionKey);
                updateSectionStatus(sectionKey, 'Validation failed', 'text-red-600');
                if (errData.errors) showFieldErrors(sectionKey, errData.errors);
                return;
            }

            if (response.status === 419) {
                state.saving = false;
                state.error = true;
                updateSaveButton(sectionKey);
                updateSectionStatus(sectionKey, '');
                showToast('error', 'Your session has expired. Reload the page and try again.', 0);
                return;
            }

            if (response.status === 403) {
                state.saving = false;
                state.error = true;
                updateSaveButton(sectionKey);
                updateSectionStatus(sectionKey, '');
                showToast('error', 'You do not have permission to edit this passport.', 0);
                return;
            }

            if (response.status === 404) {
                state.saving = false;
                state.error = true;
                updateSaveButton(sectionKey);
                updateSectionStatus(sectionKey, '');
                showToast('error', 'The product or passport could not be found.', 0);
                return;
            }

            if (!response.ok) {
                state.saving = false;
                state.error = true;
                updateSaveButton(sectionKey);
                updateSectionStatus(sectionKey, '');
                showToast('error', 'Unable to save changes. No data was intentionally discarded.', 0);
                return;
            }

            var data = await response.json();

            if (data.data) {
                if (data.data.draft_revision) {
                    globalRevision = data.data.draft_revision;
                    document.getElementById('draftRevision').textContent = globalRevision;
                    setAllExpectedRevisions(globalRevision);
                }
                if (data.data.readiness) {
                    updateReadiness(data.data.readiness);
                }
            }

            state.saving = false;
            state.saved = true;
            state.dirty = false;
            state.error = false;
            hasUnsavedChanges = false;
            updateSaveButton(sectionKey);
            updateSectionStatus(sectionKey, 'Saved', 'text-green-600');

        } catch (e) {
            state.saving = false;
            state.error = true;
            updateSaveButton(sectionKey);
            updateSectionStatus(sectionKey, '');
            showToast('error', 'Unable to save changes.', 0);
        }
    }

    function handleConflict(data) {
        var message = data.message || 'This passport was changed in another tab or by another user.';
        showToast('error', message, 0);

        if (confirm(message + '\n\nReload the latest version before saving again.\n\nPress OK to reload, Cancel to stay.')) {
            location.reload();
        }
    }

    async function resetSection(sectionKey) {
        if (!confirm('Reset section "' + sectionKey + '"? All data will be cleared.')) return;

        var productUuid = document.getElementById('productUuid').value;
        var csrfToken = document.getElementById('csrfToken').value;
        var expectedRevision = getExpectedRevision(sectionKey);

        try {
            var response = await fetch('/catalog/products/' + productUuid + '/passport/sections/' + sectionKey + '/reset', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    expected_revision: expectedRevision,
                }),
            });

            if (response.status === 409) {
                handleConflict(await response.json());
                return;
            }

            if (response.status === 422) {
                var errData = await response.json();
                showToast('error', errData.message || 'Validation failed.', 0);
                return;
            }

            if (!response.ok) {
                showToast('error', 'Unable to reset section.', 0);
                return;
            }

            location.reload();
        } catch (e) {
            showToast('error', 'Unable to reset section.', 0);
        }
    }

    function onBeforeUnload(e) {
        if (hasUnsavedChanges) {
            e.preventDefault();
            e.returnValue = 'You have unsaved changes.';
            return e.returnValue;
        }
    }

    document.addEventListener('DOMContentLoaded', init);
})();
</script>
@endif
</x-app-layout>
