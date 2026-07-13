<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Select company') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 space-y-4">
                    <p class="text-sm text-gray-600">
                        {{ __('Choose the company you want to work with.') }}
                    </p>

                    @foreach ($companies as $company)
                        <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-200 p-4">
                            <div>
                                <div class="font-semibold text-gray-900">{{ $company->name }}</div>

                                @if ($company->legal_name)
                                    <div class="text-sm text-gray-500">{{ $company->legal_name }}</div>
                                @endif

                                <div class="mt-1 text-xs uppercase tracking-wide text-gray-500">
                                    {{ $company->pivot->role->value }}
                                </div>
                            </div>

                            <form method="POST" action="{{ route('companies.switch', $company) }}">
                                @csrf
                                <x-primary-button>{{ __('Switch') }}</x-primary-button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
