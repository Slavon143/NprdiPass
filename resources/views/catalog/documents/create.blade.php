<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Catalog') }} / {{ $product->name }} / {{ __('Documents') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Add document') }}</h1>
            </div>
        </div>
    </x-slot>

    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
        <a href="{{ route('catalog.products.documents.index', $product->uuid) }}" class="text-sm text-indigo-600 hover:text-indigo-900">{{ __('← Back to documents') }}</a>

        <form method="POST" action="{{ route('catalog.products.documents.store', $product->uuid) }}" enctype="multipart/form-data" class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            @csrf
            <div class="space-y-5">
                <div>
                    <x-input-label for="document_type" :value="__('Document type')" />
                    <select id="document_type" name="document_type" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                        <option value="">{{ __('Select type...') }}</option>
                        @foreach(\App\Enums\Documents\ProductDocumentType::cases() as $type)
                        <option value="{{ $type->value }}" @if(old('document_type') === $type->value) selected @endif>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('document_type')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="title" :value="__('Title')" />
                    <x-text-input id="title" name="title" type="text" class="mt-1 block w-full" :value="old('title')" required maxlength="500" />
                    <x-input-error :messages="$errors->get('title')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="description" :value="__('Description')" />
                    <textarea id="description" name="description" rows="3" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" maxlength="5000">{{ old('description') }}</textarea>
                    <x-input-error :messages="$errors->get('description')" class="mt-1" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="language" :value="__('Language')" />
                        <select id="language" name="language" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                            <option value="sv" @if(old('language') === 'sv') selected @endif>{{ __('Swedish') }} (sv)</option>
                            <option value="en" @if(old('language') === 'en') selected @endif>{{ __('English') }} (en)</option>
                        </select>
                        <x-input-error :messages="$errors->get('language')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="visibility" :value="__('Visibility')" />
                        <select id="visibility" name="visibility" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                            <option value="internal" @if(old('visibility') === 'internal') selected @endif>{{ __('Internal only') }}</option>
                            <option value="passport_public" @if(old('visibility') === 'passport_public') selected @endif>{{ __('Passport public') }}</option>
                        </select>
                        <x-input-error :messages="$errors->get('visibility')" class="mt-1" />
                    </div>
                </div>

                <div>
                    <x-input-label for="issuer_name" :value="__('Issuer name')" />
                    <x-text-input id="issuer_name" name="issuer_name" type="text" class="mt-1 block w-full" :value="old('issuer_name')" maxlength="500" />
                    <x-input-error :messages="$errors->get('issuer_name')" class="mt-1" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="issue_date" :value="__('Issue date')" />
                        <x-text-input id="issue_date" name="issue_date" type="date" class="mt-1 block w-full" :value="old('issue_date')" max="{{ now()->toDateString() }}" />
                        <x-input-error :messages="$errors->get('issue_date')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="expires_at" :value="__('Expiry date')" />
                        <x-text-input id="expires_at" name="expires_at" type="date" class="mt-1 block w-full" :value="old('expires_at')" />
                        <x-input-error :messages="$errors->get('expires_at')" class="mt-1" />
                    </div>
                </div>

                <div>
                    <x-input-label for="file" :value="__('PDF file')" />
                    <input type="file" id="file" name="file" accept=".pdf,application/pdf" required class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 file:mr-4 file:rounded-md file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100" />
                    <p class="mt-1 text-xs text-slate-500">{{ __('Accepted: PDF. Max size: :size KB.', ['size' => config('documents.max_size_kb', 25600)]) }}</p>
                    <x-input-error :messages="$errors->get('file')" class="mt-1" />
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <a href="{{ route('catalog.products.documents.index', $product->uuid) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Cancel') }}</a>
                <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">{{ __('Upload document') }}</button>
            </div>
        </form>
    </div>
</x-app-layout>
