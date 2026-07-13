<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Settings') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Company settings') }}</h1>
            </div>
            @unless ($canUpdate)
                <x-badge>{{ __('Read only') }}</x-badge>
            @endunless
        </div>
    </x-slot>

    <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-5 sm:px-6">
                <div class="flex flex-wrap items-center gap-3">
                    <h2 class="text-lg font-semibold text-slate-900">{{ $company->name }}</h2>
                    <x-badge :tone="$company->status->value === 'active' ? 'emerald' : 'amber'">{{ $company->status->value }}</x-badge>
                </div>
                <p class="mt-1 text-sm text-slate-500">{{ __('Only owners and admins can update these fields.') }}</p>
            </div>

            @if ($canUpdate)
                <form method="POST" action="{{ route('settings.company.update') }}" class="space-y-6 px-5 py-6 sm:px-6">
                    @csrf
                    @method('PATCH')

                    <div>
                        <x-input-label for="name" :value="__('Company name')" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $company->name)" required autofocus autocomplete="organization" />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="legal_name" :value="__('Legal name')" />
                        <x-text-input id="legal_name" name="legal_name" type="text" class="mt-1 block w-full" :value="old('legal_name', $company->legal_name)" autocomplete="organization" />
                        <x-input-error :messages="$errors->get('legal_name')" class="mt-2" />
                    </div>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <div>
                            <x-input-label for="organization_number" :value="__('Organization number')" />
                            <x-text-input id="organization_number" name="organization_number" type="text" class="mt-1 block w-full" :value="old('organization_number', $company->organization_number)" />
                            <x-input-error :messages="$errors->get('organization_number')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="country_code" :value="__('Country code')" />
                            <x-text-input id="country_code" name="country_code" type="text" class="mt-1 block w-full uppercase" :value="old('country_code', $company->country_code)" required maxlength="2" inputmode="text" />
                            <x-input-error :messages="$errors->get('country_code')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="billing_email" :value="__('Billing email')" />
                        <x-text-input id="billing_email" name="billing_email" type="email" class="mt-1 block w-full" :value="old('billing_email', $company->billing_email)" autocomplete="email" />
                        <x-input-error :messages="$errors->get('billing_email')" class="mt-2" />
                    </div>

                    <div class="flex justify-end border-t border-slate-200 pt-5">
                        <x-primary-button>{{ __('Save company') }}</x-primary-button>
                    </div>
                </form>
            @else
                <dl class="grid gap-x-8 gap-y-6 px-5 py-6 sm:grid-cols-2 sm:px-6">
                    <div>
                        <dt class="text-sm font-medium text-slate-500">{{ __('Company name') }}</dt>
                        <dd class="mt-1 font-semibold text-slate-900">{{ $company->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-slate-500">{{ __('Legal name') }}</dt>
                        <dd class="mt-1 font-semibold text-slate-900">{{ $company->legal_name ?: __('Not provided') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-slate-500">{{ __('Organization number') }}</dt>
                        <dd class="mt-1 font-semibold text-slate-900">{{ $company->organization_number ?: __('Not provided') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-slate-500">{{ __('Country code') }}</dt>
                        <dd class="mt-1 font-semibold uppercase text-slate-900">{{ $company->country_code }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-sm font-medium text-slate-500">{{ __('Billing email') }}</dt>
                        <dd class="mt-1 break-all font-semibold text-slate-900">{{ $company->billing_email ?: __('Not provided') }}</dd>
                    </div>
                </dl>
            @endif
        </section>
    </div>
</x-app-layout>
