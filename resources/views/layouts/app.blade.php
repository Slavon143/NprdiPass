<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-slate-50 font-sans text-slate-900 antialiased">
        @php
            $flashMessages = function (mixed $message): array {
                if ($message === null || $message === false || $message === '') {
                    return [];
                }

                if ($message instanceof \Illuminate\Support\MessageBag) {
                    return $message->all();
                }

                if (is_array($message)) {
                    return collect($message)
                        ->flatten()
                        ->filter(fn (mixed $value): bool => is_scalar($value) || $value instanceof \Stringable)
                        ->map(fn (mixed $value): string => (string) $value)
                        ->values()
                        ->all();
                }

                if (is_scalar($message) || $message instanceof \Stringable) {
                    return [(string) $message];
                }

                return [];
            };

            $successMessages = $flashMessages(session('success'));
            $errorMessages = $flashMessages(session('error'));
            $warningMessages = $flashMessages(session('warning'));
        @endphp

        <div class="app-shell min-h-screen" x-data="appShell" :data-sidebar-collapsed="sidebarCollapsed" @keydown.escape.window="closeMobileSidebar">
            @include('layouts.navigation')

            <div class="app-shell-content lg:pl-64">
                @include('layouts.header')

                @if ($successMessages !== [] || $errorMessages !== [] || $warningMessages !== [] || $errors->any())
                    <div class="mx-auto max-w-7xl space-y-3 px-4 pt-5 sm:px-6 lg:px-8">
                        @if ($successMessages !== [])
                            <x-alert type="success">
                                @if (count($successMessages) === 1)
                                    {{ $successMessages[0] }}
                                @else
                                    <ul class="list-inside list-disc">
                                        @foreach ($successMessages as $message)
                                            <li>{{ $message }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </x-alert>
                        @endif

                        @if ($errorMessages !== [])
                            <x-alert type="error">
                                @if (count($errorMessages) === 1)
                                    {{ $errorMessages[0] }}
                                @else
                                    <ul class="list-inside list-disc">
                                        @foreach ($errorMessages as $message)
                                            <li>{{ $message }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </x-alert>
                        @endif

                        @if ($warningMessages !== [])
                            <x-alert type="warning">
                                @if (count($warningMessages) === 1)
                                    {{ $warningMessages[0] }}
                                @else
                                    <ul class="list-inside list-disc">
                                        @foreach ($warningMessages as $message)
                                            <li>{{ $message }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </x-alert>
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
        </div>
        @stack('scripts')
    </body>
</html>
