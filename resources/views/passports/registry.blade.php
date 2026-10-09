<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-bold text-slate-900">{{ __('EU registry preparation') }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ $product->name }}</p>
    </x-slot>
    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8" data-testid="passport-registry-page">
        <a class="text-sm text-indigo-700 hover:underline" href="{{ route('catalog.products.passport.readiness', $product->uuid) }}">{{ __('Publication readiness') }}</a>
        <section class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950">
            <h2 class="font-semibold">{{ __('Registry connection pending') }}</h2>
            <p class="mt-2">{{ __('A local preparation does not register a passport. Verified organisation access, the official submission schema and a registry connection are still required. The hosted passport URL and the EU registration identifier are separate identifiers.') }}</p>
        </section>
        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-lg font-semibold">{{ __('Published version preparations') }}</h2>
            @if ($passport->currentPublishedVersion && $passport->status === \App\Enums\Passports\ProductPassportStatus::Published)
                @can(\App\Enums\CompanyPermission::PassportsManage->value, $company)
                    @if ($product->managed && $product->status !== \App\Enums\Catalog\ProductStatus::Archived && ! app(\App\Services\Billing\CommercialAccessPolicy::class)->isReadOnly($company))
                        <form method="POST" action="{{ route('catalog.products.passport.registry.prepare', $product->uuid) }}" class="my-4 flex flex-wrap items-center gap-3">
                            @csrf
                            <input type="hidden" name="published_version_uuid" value="{{ $passport->currentPublishedVersion->uuid }}">
                            <input type="hidden" name="environment" value="acceptance">
                            <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">{{ __('Prepare test registry registration') }}</button>
                            <span class="text-sm text-slate-600">{{ __('Version') }} {{ $passport->currentPublishedVersion->version_number }} · {{ __('Local record only') }}</span>
                        </form>
                    @endif
                @endcan
            @else
                <p class="my-4 text-sm text-slate-600">{{ __('Publish a passport before creating a registry preparation.') }}</p>
            @endif
            <ul class="divide-y divide-slate-200">
                @forelse ($preparations as $preparation)
                    <li class="py-4 text-sm" data-testid="registry-preparation">
                        <p class="font-semibold">{{ $preparation->environment }} · {{ __('Blocked — no submission') }}</p>
                        <p class="mt-1 text-slate-600">{{ __('Version') }} {{ $preparation->getAttribute('prepared_version_number') }} · {{ $preparation->getAttribute('prepared_version_uuid') }}</p>
                        <p class="mt-1 break-all text-slate-600">{{ __('Snapshot checksum') }}: {{ $preparation->snapshot_checksum }}</p>
                        <p class="mt-1 break-all">{{ __('Hosted passport URL') }}: {{ $preparation->passport_uri ?? __('A valid HTTPS URL is required') }}</p>
                        <p class="mt-1">{{ __('EU registration identifier') }}: {{ __('Not issued') }}</p>
                    </li>
                @empty
                    <li class="py-4 text-sm text-slate-600">{{ __('No registry preparations yet.') }}</li>
                @endforelse
            </ul>
            {{ $preparations->links() }}
        </section>
        @if ($points !== [])
            <section class="rounded-xl border border-slate-200 bg-white p-5" data-testid="battery-guidance-catalogue">
                <h2 class="text-lg font-semibold">{{ __('Battery data mapping') }} · {{ $category }}</h2>
                <p class="mt-2 text-sm text-slate-600">{{ __('71 advisory positions, assessed against the displayed draft or latest publication. Candidate fields still require evidence and semantic verification. This table does not certify compliance or grant access to restricted battery data.') }}</p>
                <p class="mt-2 text-sm"><a class="text-indigo-700 underline" href="{{ config('battery_passport_guidance.source_url') }}" target="_blank" rel="noopener noreferrer">{{ __('EU publication, edition 2.0, August 2026') }}</a> · {{ __('Adapted under CC BY 4.0. This publication is non-authoritative; legal applicability must be assessed separately.') }}</p>
                <p class="mt-2 text-sm text-slate-600">{{ __('Assessed version') }}: {{ $version->uuid }}</p>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead><tr class="border-b"><th class="p-2">#</th><th class="p-2">{{ __('Data point') }}</th><th class="p-2">{{ __('Guidance applicability') }}</th><th class="p-2">{{ __('NordiPass mapping') }}</th></tr></thead>
                        <tbody>
                        @foreach ($points as $number => $point)
                            <tr class="border-b align-top">
                                <td class="p-2">{{ $number }}</td>
                                <td class="p-2"><p class="font-medium">{{ $point['label'] }}</p><p class="text-xs text-slate-500">{{ $point['reference'] }} · {{ __('Page') }} {{ $point['page'] }}</p><p class="mt-1 text-xs text-slate-600">{{ $point['note'] }}</p></td>
                                <td class="p-2">{{ match ($point['guidance_applicability']) {
                                    'mandatory' => __('Required in guidance'),
                                    'conditional' => __('Conditional applicability'),
                                    'optional_when_available' => __('If available'),
                                    'duplicate' => __('Repeated data'),
                                    'deferred_in_guidance' => __('Deferred in guidance; legal review needed'),
                                    default => __('Not displayed for this category'),
                                } }}<p class="mt-1 text-xs text-slate-500">{{ match ($point['access_domain']) {
                                    'public' => __('Public information domain'),
                                    'authorities_notified_bodies' => __('Authorities and notified bodies'),
                                    'legitimate_interest_dynamic' => __('Restricted dynamic information'),
                                    default => __('Restricted information'),
                                } }}</p></td>
                                <td class="p-2">{{ match ($point['mapping']) {
                                    'duplicate' => __('Use previous data points'),
                                    'candidate_only' => __('Candidate fields; review needed'),
                                    default => __('Dedicated field mapping needed'),
                                } }}<p class="mt-1 text-xs text-slate-500">{{ $point['aliases'] !== [] ? implode(', ', $point['aliases']) : ($point['candidate_present'] ? __('Candidate data present; unverified') : __('No candidate value')) }}</p></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @else
            <p class="text-sm text-slate-600">{{ __('Select EV, LMT or industrial battery in the regulatory context to see the battery field catalogue.') }}</p>
        @endif
    </div>
</x-app-layout>
