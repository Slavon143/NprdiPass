<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Catalog · Categories') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Taxonomy template') }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ $category->name }}</p>
            </div>
            <a href="{{ route('catalog.categories.edit', $category->uuid) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Back to category') }}</a>
        </div>
    </x-slot>

    <div class="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6 lg:px-8" data-testid="category-template-page">
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">{{ __('Active template') }}</h2>
                    @if ($activeTemplate)
                        <p class="mt-1 text-sm text-slate-600">{{ $activeTemplate->name }} · v{{ $activeTemplate->version }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ __('Readiness profile') }}: {{ $activeTemplate->readiness_profile }} v{{ $activeTemplate->readiness_profile_version }}</p>
                    @else
                        <p class="mt-1 text-sm text-slate-500">{{ __('No active template. Products continue to use the global readiness profile.') }}</p>
                    @endif
                </div>
                @if (! $draft)
                    <form method="POST" action="{{ route('catalog.categories.template.store', $category->uuid) }}" class="flex flex-col gap-2 sm:flex-row sm:items-end">
                        @csrf
                        <div>
                            <x-input-label for="new_template_name" :value="__('Draft name')" />
                            <x-text-input id="new_template_name" name="name" class="mt-1 block w-full" :value="__('Category standard v:version', ['version' => ($templates->max('version') ?? 0) + 1])" required />
                        </div>
                        <x-primary-button>{{ __('Create draft') }}</x-primary-button>
                    </form>
                @endif
            </div>
        </section>

        @if ($draft)
            <form method="POST" action="{{ route('catalog.categories.template.update', [$category->uuid, $draft->uuid]) }}" class="space-y-6">
                @csrf
                @method('PUT')

                <section class="rounded-2xl border border-indigo-200 bg-white p-5 shadow-sm sm:p-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div class="flex items-center gap-2">
                                <h2 class="text-lg font-semibold text-slate-900">{{ __('Draft v:version', ['version' => $draft->version]) }}</h2>
                                <x-badge tone="indigo">{{ __('Draft') }}</x-badge>
                            </div>
                            <p class="mt-1 text-sm text-slate-500">{{ __('Only a draft can be changed. Activation retires the previous version and flags affected products for review.') }}</p>
                        </div>
                        <div class="text-xs text-slate-500">{{ $draft->readiness_profile }} v{{ $draft->readiness_profile_version }}</div>
                    </div>

                    <div class="mt-5">
                        <x-input-label for="name" :value="__('Template name')" />
                        <x-text-input id="name" name="name" class="mt-1 block w-full" :value="old('name', $draft->name)" required />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">{{ __('Category attributes') }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ __('Required attributes must be completed before a product can acknowledge this taxonomy version. Recommended attributes stay visible but do not block acknowledgement.') }}</p>
                    </div>

                    <div class="mt-5 overflow-hidden rounded-xl border border-slate-200">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr><th class="px-4 py-3">{{ __('Attribute') }}</th><th class="px-4 py-3">{{ __('Scope') }}</th><th class="px-4 py-3">{{ __('Requirement') }}</th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 bg-white">
                                @forelse ($attributeDefinitions as $definition)
                                    @php($assignment = $selectedAttributes->get($definition->getKey()))
                                    <tr>
                                        <td class="px-4 py-3"><span class="font-semibold text-slate-900">{{ $definition->name }}</span><span class="ml-2 font-mono text-xs text-slate-500">{{ $definition->code }}</span></td>
                                        <td class="px-4 py-3 capitalize text-slate-600">{{ $definition->scope->value }}</td>
                                        <td class="px-4 py-3">
                                            <select name="attributes[{{ $definition->uuid }}]" class="rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="none" @selected(old('attributes.'.$definition->uuid, $assignment?->requirement->value ?? 'none') === 'none')>{{ __('Not included') }}</option>
                                                <option value="recommended" @selected(old('attributes.'.$definition->uuid, $assignment?->requirement->value) === 'recommended')>{{ __('Recommended') }}</option>
                                                <option value="required" @selected(old('attributes.'.$definition->uuid, $assignment?->requirement->value) === 'required')>{{ __('Required') }}</option>
                                            </select>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="px-4 py-8 text-center text-slate-500">{{ __('Create active attribute definitions before configuring a template.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <x-input-error :messages="$errors->get('attributes')" class="mt-2" />
                </section>

                <details class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <summary class="cursor-pointer px-5 py-4 font-semibold text-slate-900 sm:px-6">{{ __('Advanced · DPP section defaults') }}</summary>
                    <div class="border-t border-slate-200 px-5 py-5 sm:px-6">
                        <p class="text-sm text-slate-500">{{ __('JSON object keyed by DPP section. Unknown sections and fields are rejected before save.') }}</p>
                        <textarea name="dpp_defaults_json" rows="12" class="mt-3 block w-full rounded-lg border-slate-300 font-mono text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('dpp_defaults_json', $dppDefaultsJson) }}</textarea>
                        <x-input-error :messages="$errors->get('dpp_defaults_json')" class="mt-2" />
                    </div>
                </details>

                <div class="flex flex-col gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-5 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-amber-900">{{ __('Save first, then activate when the definition is final. Activation cannot be edited in place.') }}</p>
                    <div class="flex gap-3">
                        <x-secondary-button type="submit">{{ __('Save draft') }}</x-secondary-button>
                    </div>
                </div>
            </form>

            <form method="POST" action="{{ route('catalog.categories.template.activate', [$category->uuid, $draft->uuid]) }}" class="flex justify-end" onsubmit="return confirm('{{ __('Activate this version and mark category products as needing review?') }}')">
                @csrf
                <x-primary-button data-testid="activate-category-template">{{ __('Activate v:version', ['version' => $draft->version]) }}</x-primary-button>
            </form>
        @endif

        @if ($templates->isNotEmpty())
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Version history') }}</h2>
                <div class="mt-4 divide-y divide-slate-200">
                    @foreach ($templates as $template)
                        <div class="flex items-center justify-between gap-4 py-3">
                            <div><p class="font-semibold text-slate-900">v{{ $template->version }} · {{ $template->name }}</p><p class="text-xs text-slate-500">{{ $template->attributes->count() }} {{ __('attributes') }} · {{ $template->readiness_profile }} v{{ $template->readiness_profile_version }}</p></div>
                            <x-badge :tone="$template->status->value === 'active' ? 'emerald' : ($template->status->value === 'draft' ? 'indigo' : 'slate')">{{ ucfirst($template->status->value) }}</x-badge>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-app-layout>
