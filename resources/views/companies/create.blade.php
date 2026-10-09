<x-guest-layout>
    <form method="POST" action="{{ route('companies.create.store') }}" class="space-y-5">
        @csrf
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">{{ __('Create your organization') }}</h1>
            <p class="mt-1 text-sm text-slate-600">{{ __('Start a 14-day pilot workspace with you as the owner.') }}</p>
        </div>
        <div>
            <x-input-label for="organization_number" :value="__('Organization number (optional)')" />
            <x-text-input id="organization_number" name="organization_number" type="text" class="mt-1 block w-full" :value="old('organization_number')" />
            <x-input-error :messages="$errors->get('organization_number')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="country_code" :value="__('Country code')" />
            <x-text-input id="country_code" name="country_code" type="text" maxlength="2" required class="mt-1 block w-full" :value="old('country_code', 'SE')" />
            <x-input-error :messages="$errors->get('country_code')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="name" :value="__('Organization name')" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required autofocus />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>
        <x-primary-button>{{ __('Create organization') }}</x-primary-button>
    </form>
</x-guest-layout>
