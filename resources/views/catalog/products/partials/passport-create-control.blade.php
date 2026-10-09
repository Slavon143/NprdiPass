@if ($canManagePassports)
    <form method="POST" action="{{ route('catalog.products.passport.store', $product->uuid) }}" class="mt-4">
        @csrf
        <button type="submit" class="rounded-lg bg-emerald-900 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">{{ __('Create Product Passport') }}</button>
    </form>
@elseif ($isArchived)
    <p class="mt-4 text-sm text-slate-600">{{ __('Restore this product before creating a Product Passport.') }}</p>
@elseif (! $product->managed)
    <p class="mt-4 text-sm text-slate-600">{{ __('Enroll this product in DPP before creating its Product Passport. Enrollment uses one available product slot.') }}</p>
    @if ($canEnrollInDpp)
        <form method="POST" action="{{ route('catalog.products.managed', $product->uuid) }}" class="mt-4">
            @csrf
            @method('PATCH')
            <input type="hidden" name="managed" value="1">
            <button type="submit" class="rounded-lg bg-emerald-900 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">{{ __('Enroll in DPP') }}</button>
        </form>
    @endif
@endif
