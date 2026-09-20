<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $viewModel->productName }} — QR Label</title>
    @vite(['resources/css/app.css'])
    <style>
        @page { size: A4; margin: 12mm; }
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; }
            .label-sheet { box-shadow: none !important; border: 0 !important; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-900 antialiased">
    <main class="mx-auto max-w-3xl px-4 py-8">
        <div class="no-print mb-6 flex items-center justify-between">
            <a href="{{ route('catalog.products.passport.qr.show', $product->uuid) }}" class="text-sm font-medium text-blue-700 hover:underline">Back to QR</a>
            <button type="button" onclick="window.print()" class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">Print</button>
        </div>

        <section class="label-sheet bg-white p-8 shadow" aria-label="Printable Product Passport QR label">
            <div class="grid gap-8 sm:grid-cols-[220px_1fr]">
                <div class="rounded border border-slate-300 bg-white p-4">
                    <img src="{{ route('catalog.products.passport.qr.svg', $product->uuid) }}"
                         alt="QR code linking to the public Product Passport for {{ $viewModel->productName }}"
                         width="180"
                         height="180"
                         class="mx-auto h-[180px] w-[180px]">
                </div>

                <div class="flex flex-col justify-between gap-6">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Digital Product Passport</p>
                        <h1 class="mt-2 text-2xl font-bold leading-tight">{{ $viewModel->productName }}</h1>
                        <p class="mt-3 text-sm text-slate-600">Scan to view the public Product Passport, including published product information, safety details, documents, and publication version.</p>
                    </div>

                    <dl class="grid gap-2 text-sm text-slate-700">
                        <div>
                            <dt class="font-semibold">Public URL</dt>
                            <dd class="break-all">{{ $viewModel->publicUrl }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold">Target status</dt>
                            <dd>{{ $viewModel->targetStatusLabel() }}</dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="mt-8 border-t border-slate-200 pt-4 text-xs text-slate-500">
                Print guidance: minimum {{ $viewModel->printSpec['min_recommended_size_mm'] }} x {{ $viewModel->printSpec['min_recommended_size_mm'] }} mm, recommended packaging size {{ $viewModel->printSpec['recommended_packaging_size_mm'] }} x {{ $viewModel->printSpec['recommended_packaging_size_mm'] }} mm or larger, quiet zone {{ $viewModel->printSpec['quiet_zone_modules'] }} modules, contrast {{ number_format($viewModel->printSpec['contrast_ratio'], 2) }}:1.
            </div>
        </section>
    </main>
</body>
</html>
