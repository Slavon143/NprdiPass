@extends('layouts.invitation')

@section('content')
    <div class="px-5 py-7 sm:px-8 sm:py-9">
        <p class="text-sm font-semibold text-indigo-600">{{ __('Invitation registration') }}</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">{{ __('Create your NordiPass account') }}</h1>
        <p class="mt-3 text-sm leading-6 text-slate-600">{{ __('Your account will join :company as :role.', ['company' => $invitation->company->name, 'role' => $invitation->role->value]) }}</p>

        <div class="mt-6 rounded-xl bg-slate-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Verified invitation email') }}</p>
            <p class="mt-1 break-all text-sm font-semibold text-slate-900">{{ $invitation->email }}</p>
        </div>

        <form method="POST" action="{{ route('invitations.register', ['invitation' => $invitation]) }}" class="mt-7 space-y-5">
            @csrf
            <input type="hidden" name="token" value="{{ $plainTextToken }}">

            <div>
                <x-input-label for="name" :value="__('Name')" />
                <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required autofocus autocomplete="name" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="password" :value="__('Password')" />
                <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="password_confirmation" :value="__('Confirm password')" />
                <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
            </div>

            <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">{{ __('Create account and accept') }}</button>
        </form>
    </div>
@endsection
