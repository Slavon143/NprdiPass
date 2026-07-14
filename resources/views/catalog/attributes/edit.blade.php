<x-app-layout>
    <x-slot name="header"><div><p class="text-sm font-medium text-indigo-600">{{ __('Catalog · Attributes') }}</p><h1 class="mt-1 text-2xl font-bold text-slate-900">{{ __('Edit :name', ['name' => $definition->name]) }}</h1></div></x-slot>
    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('catalog.attributes.update', $definition->uuid) }}" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">@csrf @method('PATCH') @include('catalog.attributes._form')<div class="flex justify-end gap-3"><a href="{{ route('catalog.attributes.show', $definition->uuid) }}" class="px-4 py-2 text-sm font-semibold text-slate-600">{{ __('Cancel') }}</a><x-primary-button>{{ __('Save changes') }}</x-primary-button></div></form>
    </div>
</x-app-layout>
