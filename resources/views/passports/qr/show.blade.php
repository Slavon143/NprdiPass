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
                    <div class="flex items-center gap-2">
                        <input type="text" readonly
                               class="flex-1 border rounded px-3 py-2 text-sm bg-gray-50 text-gray-700"
                               value="{{ $viewModel->publicUrl }}"
                               id="passport-public-url">
                        <button type="button"
                                class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded text-sm border"
                                onclick="copyPublicLink('{{ $viewModel->publicUrl }}')"
                                aria-label="Copy public link">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                            Copy
                        </button>
                    </div>
                    <div id="copy-feedback" class="text-sm text-green-600 mt-1 hidden" aria-live="polite">Public link copied</div>
                </div>

                @if($viewModel->isPublished)
                <div class="mb-6">
                    <a href="{{ $viewModel->publicUrl }}" target="_blank" rel="noopener noreferrer"
                       class="text-blue-600 hover:underline text-sm">
                        Open public page &rarr;
                    </a>
                </div>
                @endif

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
function copyPublicLink(url) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function() {
            showCopyFeedback();
        }).catch(function() {
            fallbackCopy(url);
        });
    } else {
        fallbackCopy(url);
    }
}

function fallbackCopy(text) {
    var input = document.createElement('textarea');
    input.value = text;
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.appendChild(input);
    input.select();
    try {
        document.execCommand('copy');
        showCopyFeedback();
    } catch (e) {
        // silently fail
    }
    document.body.removeChild(input);
}

function showCopyFeedback() {
    var feedback = document.getElementById('copy-feedback');
    feedback.classList.remove('hidden');
    setTimeout(function() {
        feedback.classList.add('hidden');
    }, 3000);
}
</script>
@endpush
</x-app-layout>
