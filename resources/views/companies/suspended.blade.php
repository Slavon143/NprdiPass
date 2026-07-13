<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Company suspended') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 space-y-4">
                    <p class="text-gray-700">
                        {{ __('Tenant actions are unavailable while a company is suspended. Contact the platform administrator for assistance.') }}
                    </p>

                    @foreach ($suspendedCompanies as $company)
                        <div class="rounded-lg border border-amber-300 bg-amber-50 p-4">
                            <div class="font-semibold text-gray-900">{{ $company->name }}</div>
                            <div class="mt-1 text-sm text-amber-800">{{ $company->status->value }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            @if ($availableCompanies->isNotEmpty())
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6 space-y-4">
                        <h3 class="font-semibold text-gray-900">{{ __('Switch to an active company') }}</h3>

                        @foreach ($availableCompanies as $company)
                            <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-200 p-4">
                                <div>
                                    <div class="font-medium text-gray-900">{{ $company->name }}</div>
                                    <div class="text-xs uppercase tracking-wide text-gray-500">{{ $company->pivot->role->value }}</div>
                                </div>

                                <form method="POST" action="{{ route('companies.switch', $company) }}">
                                    @csrf
                                    <x-primary-button>{{ __('Switch') }}</x-primary-button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
