<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="referrer" content="no-referrer">
        <title>{{ __('Company invitation') }} · {{ config('app.name', 'NordiPass') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-slate-50 font-sans text-slate-900 antialiased">
        <main class="flex min-h-screen items-center justify-center px-4 py-10 sm:px-6">
            <div class="w-full max-w-xl">
                <a href="{{ url('/') }}" class="mx-auto flex w-fit items-center gap-3 text-lg font-bold text-slate-900">
                    <x-application-logo class="h-10 w-10" />
                    <span>NordiPass</span>
                </a>

                <div class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    @if (session('error') || $errors->any())
                        <div class="space-y-3 border-b border-slate-200 px-5 py-4 sm:px-7">
                            @if (session('error'))
                                <x-alert type="error">{{ session('error') }}</x-alert>
                            @endif
                            @if ($errors->any())
                                <x-alert type="error">
                                    <ul class="list-inside list-disc">
                                        @foreach ($errors->all() as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </x-alert>
                            @endif
                        </div>
                    @endif

                    @yield('content')
                </div>

                <p class="mt-5 text-center text-xs text-slate-500">{{ __('Invitation pages are private and are not stored by your browser.') }}</p>
            </div>
        </main>
    </body>
</html>
