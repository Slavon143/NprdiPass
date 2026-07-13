<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-slate-50 font-sans text-slate-900 antialiased">
        <div class="min-h-screen">
            @include('layouts.navigation')

            @if (session('success') || session('error') || session('warning') || $errors->any())
                <div class="mx-auto max-w-7xl space-y-3 px-4 pt-5 sm:px-6 lg:px-8">
                    @if (session('success'))
                        <x-alert type="success">{{ session('success') }}</x-alert>
                    @endif

                    @if (session('error'))
                        <x-alert type="error">{{ session('error') }}</x-alert>
                    @endif

                    @if (session('warning'))
                        <x-alert type="warning">{{ session('warning') }}</x-alert>
                    @endif

                    @if ($errors->any())
                        <x-alert type="error">
                            <p class="font-semibold">{{ __('Please review the highlighted fields.') }}</p>
                            <ul class="mt-1 list-inside list-disc">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </x-alert>
                    @endif
                </div>
            @endif

            <!-- Page Heading -->
            @isset($header)
                <header class="border-b border-slate-200 bg-white">
                    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main class="pb-12">
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
