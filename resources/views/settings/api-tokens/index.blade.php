<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Settings') }}</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('API tokens') }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ __('Manage bearer tokens scoped to :company.', ['company' => $company->name]) }}</p>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Create an API token') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ __('The secret is shown once. Tokens are limited to this company and the selected abilities.') }}</p>

            <form method="POST" action="{{ route('settings.api-tokens.store') }}" class="mt-6 grid gap-5 lg:grid-cols-3">
                @csrf
                <div>
                    <x-input-label for="token-name" :value="__('Name')" />
                    <x-text-input id="token-name" name="name" type="text" :value="old('name')" required maxlength="100" class="mt-1 block w-full" placeholder="Reporting integration" />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <fieldset>
                    <legend class="text-sm font-medium text-slate-700">{{ __('Abilities') }}</legend>
                    <div class="mt-2 space-y-2">
                        @foreach ($abilities as $ability)
                            <label class="flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" name="abilities[]" value="{{ $ability->value }}" @checked(in_array($ability->value, old('abilities', []), true)) class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                <span>{{ $ability->value }}</span>
                            </label>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('abilities')" class="mt-2" />
                    <x-input-error :messages="$errors->get('abilities.*')" class="mt-2" />
                </fieldset>

                <div>
                    <x-input-label for="token-expiration" :value="__('Expiration')" />
                    <select id="token-expiration" name="expiration" required class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="30_days" @selected(old('expiration') === '30_days')>{{ __('30 days') }}</option>
                        <option value="90_days" @selected(old('expiration', $defaultExpirationDays === 90 ? '90_days' : null) === '90_days')>{{ __('90 days') }}</option>
                        <option value="1_year" @selected(old('expiration', $defaultExpirationDays === 365 ? '1_year' : null) === '1_year')>{{ __('1 year') }}</option>
                        @if ($allowNonExpiringTokens)
                            <option value="never" @selected(old('expiration') === 'never')>{{ __('No expiration') }}</option>
                        @endif
                    </select>
                    <x-input-error :messages="$errors->get('expiration')" class="mt-2" />
                </div>

                <div class="lg:col-span-3">
                    <x-primary-button>{{ __('Create token') }}</x-primary-button>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-5 sm:px-6">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Company tokens') }}</h2>
                <p class="text-sm text-slate-500">{{ trans_choice(':count token|:count tokens', $tokens->count(), ['count' => $tokens->count()]) }}</p>
            </div>

            @if ($tokens->isEmpty())
                <div class="px-6 py-12 text-center text-sm text-slate-500">{{ __('No API tokens have been created for this company.') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                @foreach ([__('Name'), __('Created by'), __('Abilities'), __('Created'), __('Last used'), __('Expires'), __('Status'), __('Actions')] as $heading)
                                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $heading }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($tokens as $token)
                                @php($expired = $token->isExpired())
                                <tr class="align-top">
                                    <td class="px-5 py-4 text-sm font-semibold text-slate-900">{{ $token->name }}</td>
                                    <td class="px-5 py-4 text-sm text-slate-600">{{ $token->tokenable?->name ?: __('Former user') }}</td>
                                    <td class="px-5 py-4 text-sm text-slate-600">{{ implode(', ', $token->abilities) }}</td>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm text-slate-600">{{ $token->created_at?->format('Y-m-d H:i') }}</td>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm text-slate-600">{{ $token->last_used_at?->format('Y-m-d H:i') ?: __('Never') }}</td>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm text-slate-600">{{ $token->expires_at?->format('Y-m-d H:i') ?: __('Never') }}</td>
                                    <td class="px-5 py-4"><x-badge :tone="$expired ? 'red' : 'emerald'">{{ $expired ? __('expired') : __('active') }}</x-badge></td>
                                    <td class="px-5 py-4 text-right">
                                        <form method="POST" action="{{ route('settings.api-tokens.destroy', ['token' => $token->getKey()]) }}" x-data="{ message: @js(__('Revoke token :name?', ['name' => $token->name])) }" @submit.prevent="if (window.confirm(message)) $el.submit()">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="rounded-lg px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500">{{ __('Revoke') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
