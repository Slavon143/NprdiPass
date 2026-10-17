<form method="POST" action="{{ route('ui-language.update') }}" class="flex items-center gap-2" data-testid="ui-language-selector">
    @csrf
    <label for="ui-language" class="sr-only">{{ __('Interface language') }}</label>
    <select id="ui-language" name="locale" class="max-w-28 rounded-lg border-slate-300 py-1 text-sm" aria-label="{{ __('Interface language') }}">
        @foreach(\App\Support\UiLocale::LABELS as $locale => $label)<option value="{{ $locale }}" @selected(app()->getLocale() === $locale)>{{ $label }}</option>@endforeach
    </select>
    <button type="submit" class="rounded-lg px-2 py-1 text-sm font-semibold text-emerald-800 focus:ring-2 focus:ring-emerald-700">{{ __('Apply') }}</button>
</form>
