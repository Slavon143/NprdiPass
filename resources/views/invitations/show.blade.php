@extends('layouts.invitation')

@section('content')
    <div class="px-5 py-7 sm:px-8 sm:py-9">
        @if ($state === 'valid')
            <p class="text-sm font-semibold text-indigo-600">{{ __('Company invitation') }}</p>
            <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">{{ __('Join :company', ['company' => $invitation->company->name]) }}</h1>
            <p class="mt-3 text-sm leading-6 text-slate-600">{{ __('You have been invited as :role. This link expires :time.', ['role' => $invitation->role->value, 'time' => $invitation->expires_at->format('Y-m-d H:i T')]) }}</p>

            <dl class="mt-6 grid gap-4 rounded-xl bg-slate-50 p-5 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-slate-500">{{ __('Email') }}</dt>
                    <dd class="mt-1 break-all font-semibold text-slate-900">{{ $invitation->email }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">{{ __('Role') }}</dt>
                    <dd class="mt-1 font-semibold capitalize text-slate-900">{{ $invitation->role->value }}</dd>
                </div>
            </dl>

            <div class="mt-7">
                @guest
                    @if ($hasAccount)
                        <p class="text-sm text-slate-600">{{ __('An account already exists for this email. Sign in to review and accept the invitation.') }}</p>
                        <a href="{{ route('login') }}" class="mt-4 inline-flex w-full justify-center rounded-lg bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">{{ __('Sign in to continue') }}</a>
                    @else
                        <p class="text-sm text-slate-600">{{ __('Create an account using the invited email address to accept this invitation.') }}</p>
                        <a href="{{ route('invitations.register', ['invitation' => $invitation, 'token' => $plainTextToken]) }}" class="mt-4 inline-flex w-full justify-center rounded-lg bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">{{ __('Create account and continue') }}</a>
                    @endif
                @else
                    @if ($authenticatedEmailMatches)
                        <form method="POST" action="{{ route('invitations.accept', ['invitation' => $invitation]) }}">
                            @csrf
                            <input type="hidden" name="token" value="{{ $plainTextToken }}">
                            <button type="submit" class="inline-flex w-full justify-center rounded-lg bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">{{ __('Accept invitation') }}</button>
                        </form>
                    @else
                        <x-alert type="warning">{{ __('You are signed in with a different email address. Log out and sign in with the address that received this invitation.') }}</x-alert>
                        <form method="POST" action="{{ route('logout') }}" class="mt-4">
                            @csrf
                            <button type="submit" class="inline-flex w-full justify-center rounded-lg border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">{{ __('Log out') }}</button>
                        </form>
                    @endif
                @endguest
            </div>
        @else
            <p class="text-sm font-semibold text-slate-500">{{ __('Company invitation') }}</p>
            <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">
                {{ match ($state) { 'expired' => __('This invitation has expired'), 'accepted' => __('This invitation was already accepted'), default => __('This invitation was cancelled') } }}
            </h1>
            <p class="mt-3 text-sm leading-6 text-slate-600">
                {{ match ($state) { 'expired' => __('Ask a company owner or administrator to send a new invitation.'), 'accepted' => __('Sign in to NordiPass to access your companies.'), default => __('This link can no longer be used. Ask the company to send a new invitation if needed.') } }}
            </p>
            <a href="{{ route('login') }}" class="mt-6 inline-flex rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-700">{{ __('Go to sign in') }}</a>
        @endif
    </div>
@endsection
