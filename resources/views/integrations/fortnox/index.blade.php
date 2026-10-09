<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div><p class="text-sm font-medium text-indigo-600">{{ __('Integrations') }}</p><h1 class="text-2xl font-semibold text-slate-900">{{ __('Fortnox') }}</h1></div>
        </div>
    </x-slot>

    <div class="space-y-6">
        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Connection') }}</h2>
            @if ($authorizationBlocked)
                <p class="mt-3 text-sm text-amber-700" data-testid="fortnox-authorization-blocked">{{ __('New Fortnox connections are blocked in this environment. Contact your administrator to verify the permitted test account.') }}</p>
            @endif
            @if ($connection === null || $connection->status === \App\Enums\Fortnox\FortnoxConnectionStatus::Revoked)
                <p class="mt-1 text-sm text-slate-600">{{ __('Connect your Fortnox account to synchronize article data into the catalog.') }}</p>
                @unless ($authorizationBlocked)
                <form class="mt-4" method="POST" action="{{ route('settings.integrations.fortnox.connect') }}" data-testid="fortnox-connect-form">
                    @csrf
                    <x-primary-button>{{ __('Connect Fortnox') }}</x-primary-button>
                </form>
                @endunless
            @else
                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-slate-500">{{ __('Status') }}</dt><dd class="mt-1 font-medium text-slate-900">{{ $connection->status->label() }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Connected') }}</dt><dd class="mt-1 font-medium text-slate-900">{{ $connection->connected_at?->format('Y-m-d H:i') ?? '—' }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Last sync') }}</dt><dd class="mt-1 font-medium text-slate-900">{{ $connection->last_synced_at?->format('Y-m-d H:i') ?? '—' }}</dd></div>
                    <div><dt class="text-slate-500">{{ __('Fortnox account') }}</dt><dd class="mt-1 font-medium text-slate-900">{{ $connection->external_tenant_id ?? __('Not recorded') }}</dd></div>
                </dl>
                @if ($connection->failure_message)
                    <p class="mt-3 text-sm text-amber-700">{{ $connection->failure_message }}</p>
                @endif
                <div class="mt-4 flex gap-2">
                    <form method="POST" action="{{ route('settings.integrations.fortnox.sync') }}" data-testid="fortnox-sync-form">@csrf<x-primary-button>{{ __('Sync now') }}</x-primary-button></form>
                    @unless ($authorizationBlocked)
                        <form method="POST" action="{{ route('settings.integrations.fortnox.connect') }}">@csrf<x-secondary-button type="submit">{{ __('Reconnect') }}</x-secondary-button></form>
                    @endunless
                    <form method="POST" action="{{ route('settings.integrations.fortnox.disconnect') }}" data-testid="fortnox-disconnect-form">@csrf<x-danger-button>{{ __('Disconnect') }}</x-danger-button></form>
                </div>
            @endif
        </section>

        <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-200 px-6 py-4"><h2 class="font-semibold text-slate-900">{{ __('Open conflicts') }}</h2></div>
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-sm"><thead class="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th class="px-6 py-3">{{ __('Article') }}</th><th class="px-6 py-3">{{ __('Field') }}</th><th class="px-6 py-3">{{ __('Fortnox value') }}</th><th class="px-6 py-3">{{ __('NordiPass value') }}</th><th class="px-6 py-3">{{ __('Resolve') }}</th></tr></thead><tbody class="divide-y divide-slate-100">
                @forelse($conflicts as $conflict)
                    <tr>
                        <td class="px-6 py-4">{{ $conflict->mapping?->fortnox_article_number }}</td>
                        <td class="px-6 py-4">{{ $conflict->field->label() }}</td>
                        <td class="px-6 py-4">{{ data_get($conflict->fortnox_value, 'value') ?? '—' }}</td>
                        <td class="px-6 py-4">{{ data_get($conflict->nordipass_value, 'value') ?? '—' }}</td>
                        <td class="px-6 py-4">
                            <div class="flex gap-2" data-testid="fortnox-conflict-row">
                                <form method="POST" action="{{ route('settings.integrations.fortnox.conflicts.resolve', $conflict) }}">@csrf<input type="hidden" name="resolution" value="fortnox"><x-secondary-button type="submit">{{ __('Use Fortnox') }}</x-secondary-button></form>
                                <form method="POST" action="{{ route('settings.integrations.fortnox.conflicts.resolve', $conflict) }}">@csrf<input type="hidden" name="resolution" value="nordipass"><x-secondary-button type="submit">{{ __('Keep NordiPass') }}</x-secondary-button></form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-6 py-10 text-center text-slate-500">{{ __('No open conflicts.') }}</td></tr>
                @endforelse
            </tbody></table></div>
        </section>

        <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-200 px-6 py-4"><h2 class="font-semibold text-slate-900">{{ __('Sync history') }}</h2></div>
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-sm"><thead class="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th class="px-6 py-3">{{ __('Status') }}</th><th class="px-6 py-3">{{ __('Fetched') }}</th><th class="px-6 py-3">{{ __('Created') }}</th><th class="px-6 py-3">{{ __('Updated') }}</th><th class="px-6 py-3">{{ __('Conflicts') }}</th><th class="px-6 py-3">{{ __('Started') }}</th></tr></thead><tbody class="divide-y divide-slate-100">
                @forelse($syncRuns as $run)
                    <tr>
                        <td class="px-6 py-4 font-medium {{ $run->status->value === 'failed' ? 'text-red-600' : 'text-slate-900' }}">{{ $run->status->value }}</td>
                        <td class="px-6 py-4">{{ $run->articles_fetched }}</td>
                        <td class="px-6 py-4">{{ $run->articles_created }}</td>
                        <td class="px-6 py-4">{{ $run->articles_updated }}</td>
                        <td class="px-6 py-4">{{ $run->conflicts_raised }}</td>
                        <td class="px-6 py-4 text-slate-500">{{ $run->started_at?->diffForHumans() ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-6 py-10 text-center text-slate-500">{{ __('No sync runs yet.') }}</td></tr>
                @endforelse
            </tbody></table></div>
        </section>

        <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-200 px-6 py-4"><h2 class="font-semibold text-slate-900">{{ __('Article mappings') }}</h2></div>
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-sm"><thead class="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th class="px-6 py-3">{{ __('Article number') }}</th><th class="px-6 py-3">{{ __('Product') }}</th><th class="px-6 py-3">{{ __('Variant SKU') }}</th><th class="px-6 py-3">{{ __('Last modified') }}</th></tr></thead><tbody class="divide-y divide-slate-100">
                @forelse($mappings as $mapping)
                    <tr>
                        <td class="px-6 py-4 font-medium text-slate-900">{{ $mapping->fortnox_article_number }}</td>
                        <td class="px-6 py-4">{{ $mapping->product?->name ?? '—' }}</td>
                        <td class="px-6 py-4">{{ $mapping->variant?->sku ?? '—' }}</td>
                        <td class="px-6 py-4 text-slate-500">{{ $mapping->fortnox_last_modified?->format('Y-m-d H:i') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-6 py-10 text-center text-slate-500">{{ __('No mappings yet.') }}</td></tr>
                @endforelse
            </tbody></table></div>
            <div class="px-6 py-4">{{ $mappings->links() }}</div>
        </section>
    </div>
</x-app-layout>
