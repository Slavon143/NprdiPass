<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('No company access') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-700 space-y-3">
                    <p>{{ __('Your account does not currently have access to an active company.') }}</p>
                    <p>{{ __('Ask a company administrator for an invitation or contact the platform administrator.') }}</p>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
