<x-app-layout>
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold">{{ $viewModel->productName }} — QR Code</h1>
            <p class="text-gray-600 mt-1">Product Passport QR</p>
        </div>
        <a href="{{ route('catalog.products.passport.show', $product->uuid) }}"
           class="text-blue-600 hover:underline">Back to Passport</a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <div class="bg-white shadow rounded-lg p-6">
                <h2 class="text-lg font-semibold mb-2">{{ $viewModel->productName }}</h2>

                <div class="mb-4">
                    <span @class([
                        'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                        'bg-green-100 text-green-800' => $viewModel->isPublished,
                        'bg-yellow-100 text-yellow-800' => !$viewModel->isPublished && $viewModel->hasBeenPublished,
                        'bg-gray-100 text-gray-800' => !$viewModel->hasBeenPublished,
                    ])>
                        Status: {{ $viewModel->targetStatusLabel() }}
                    </span>
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Public link</label>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <input type="text" readonly
                               class="flex-1 border rounded px-3 py-2 text-sm bg-gray-50 text-gray-700"
                               value="{{ $viewModel->publicUrl }}"
                               id="passport-public-url">
                        <button type="button"
                                id="copy-public-link"
                                class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded text-sm border"
                                aria-label="Copy public link">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                            <span id="copy-public-link-label">Copy</span>
                        </button>
                        @if($viewModel->isPublished)
                            <a href="{{ $viewModel->publicUrl }}" target="_blank" rel="noopener noreferrer"
                               class="rounded border border-blue-200 px-3 py-2 text-center text-sm font-medium text-blue-700 hover:bg-blue-50">
                                Open
                            </a>
                        @endif
                    </div>
                    <div id="copy-feedback" class="text-sm text-green-600 mt-1 hidden" aria-live="polite">Public link copied</div>
                    <div id="copy-fallback" class="text-sm text-amber-700 mt-1 hidden" aria-live="polite">Copy is blocked by the browser. The link is selected — press Ctrl+C.</div>
                </div>

                @if(!$viewModel->isPublished)
                <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded">
                    @if($viewModel->passportStatus === 'Archived')
                        <p class="text-sm text-yellow-800 font-medium">Passport is archived</p>
                        <p class="text-sm text-yellow-700 mt-1">The QR remains valid, but the public page is not accessible.</p>
                    @elseif($viewModel->hasBeenPublished)
                        <p class="text-sm text-yellow-800 font-medium">Target currently unavailable</p>
                        <p class="text-sm text-yellow-700 mt-1">The QR remains valid, but the public page will return 404 until the Passport is published again.</p>
                    @else
                        <p class="text-sm text-yellow-800 font-medium">Not published yet</p>
                        <p class="text-sm text-yellow-700 mt-1">You may preview the stable QR, but the public page is not active.</p>
                    @endif
                </div>
                @endif

                <div class="flex justify-center mb-6 p-4 bg-gray-50 rounded-lg">
                    <div class="inline-block bg-white p-4 rounded shadow">
                        <img src="{{ route('catalog.products.passport.qr.svg', $product->uuid) }}"
                             alt="QR code linking to the public Product Passport for {{ $viewModel->productName }}"
                             class="w-64 h-64"
                             width="280" height="280">
                    </div>
                </div>

                <div class="flex gap-3 justify-center">
                    <a href="{{ route('catalog.products.passport.qr.svg', $product->uuid) }}"
                       class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-sm">
                        Download SVG
                    </a>
                    <a href="{{ route('catalog.products.passport.qr.png', $product->uuid) }}"
                       class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 text-sm">
                        Download PNG
                    </a>
                </div>
            </div>
        </div>

        <div class="lg:col-span-1">
            <div class="bg-white shadow rounded-lg p-6">
                <h3 class="text-md font-semibold mb-3">Print guidance</h3>
                <ul class="text-sm text-gray-600 space-y-2">
                    <li>Minimum recommended printed size: <strong>{{ config('passports.qr.print.min_recommended_size_mm') }} &times; {{ config('passports.qr.print.min_recommended_size_mm') }} mm</strong></li>
                    <li>Recommended for packaging: <strong>{{ config('passports.qr.print.recommended_packaging_size_mm') }} &times; {{ config('passports.qr.print.recommended_packaging_size_mm') }} mm</strong> or larger</li>
                    <li>Keep the white quiet zone</li>
                    <li>Do not stretch</li>
                    <li>Do not crop</li>
                    <li>Use dark QR on a light background</li>
                    <li>Test the printed sample before mass printing</li>
                </ul>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var copyButton = document.getElementById('copy-public-link');
    var input = document.getElementById('passport-public-url');
    var feedback = document.getElementById('copy-feedback');
    var fallback = document.getElementById('copy-fallback');
    var label = document.getElementById('copy-public-link-label');

    if (!copyButton || !input) {
        return;
    }

    copyButton.addEventListener('click', function () {
        copyPublicLink(input.value);
    });

    function copyPublicLink(url) {
        hideMessages();

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url).then(showCopied).catch(selectForManualCopy);
            return;
        }

        selectForManualCopy();
    }

    function selectForManualCopy() {
        input.focus();
        input.select();

        try {
            if (document.execCommand('copy')) {
                showCopied();
                return;
            }
        } catch (e) {
            // Browser blocked scripted copy; leave the input selected for Ctrl+C.
        }

        if (fallback) {
            fallback.classList.remove('hidden');
        }
    }

    function showCopied() {
        if (feedback) {
            feedback.classList.remove('hidden');
        }

        if (label) {
            label.textContent = 'Copied';
        }

        window.setTimeout(function () {
            hideMessages();
            if (label) {
                label.textContent = 'Copy';
            }
        }, 2500);
    }

    function hideMessages() {
        if (feedback) {
            feedback.classList.add('hidden');
        }

        if (fallback) {
            fallback.classList.add('hidden');
        }
    }
});
</script>
@endpush
</x-app-layout>
