<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-medium text-indigo-600">{{ __('Operations') }}</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Notifications') }}</h1>
            </div>
            <form method="POST" action="{{ route('notifications.mark-all-read') }}">
                @csrf
                <button type="submit" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    {{ __('Mark all read') }}
                </button>
            </form>
        </div>
    </x-slot>

    <div class="mx-auto grid max-w-7xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:px-8">
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Inbox') }}</h2>
            </div>

            <div class="divide-y divide-slate-200">
                @forelse($notifications as $notification)
                    <article class="px-5 py-4 {{ $notification->read_at ? 'bg-white' : 'bg-indigo-50/40' }}">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold uppercase text-slate-600">{{ $notification->severity->value }}</span>
                                    <span class="text-xs text-slate-500">{{ $notification->created_at->diffForHumans() }}</span>
                                    @unless($notification->read_at)
                                        <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-700">{{ __('Unread') }}</span>
                                    @endunless
                                </div>
                                <h3 class="mt-2 text-base font-semibold text-slate-900">{{ $notification->title }}</h3>
                                @if($notification->body)
                                    <p class="mt-1 text-sm text-slate-600">{{ $notification->body }}</p>
                                @endif
                                @if($notification->action_url)
                                    <a href="{{ $notification->action_url }}" class="mt-2 inline-flex text-sm font-semibold text-indigo-700 hover:text-indigo-900">{{ __('Open related item') }}</a>
                                @endif
                            </div>
                            <div class="flex shrink-0 gap-2">
                                @unless($notification->read_at)
                                    <form method="POST" action="{{ route('notifications.read', $notification) }}">
                                        @csrf
                                        <button type="submit" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">{{ __('Read') }}</button>
                                    </form>
                                @endunless
                                <form method="POST" action="{{ route('notifications.dismiss', $notification) }}">
                                    @csrf
                                    <button type="submit" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">{{ __('Dismiss') }}</button>
                                </form>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="px-5 py-12 text-center text-sm text-slate-500">{{ __('No notifications yet.') }}</div>
                @endforelse
            </div>

            @if($notifications->hasPages())
                <div class="border-t border-slate-200 px-5 py-4">
                    {{ $notifications->links() }}
                </div>
            @endif
        </section>

        <section class="h-fit rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Preferences') }}</h2>
            <form method="POST" action="{{ route('notifications.preferences.update') }}" class="mt-4 space-y-4">
                @csrf
                @foreach($types as $type)
                    @php($preference = $preferences->get($type->value))
                    <div class="rounded-xl border border-slate-200 p-4">
                        <p class="text-sm font-semibold text-slate-900">{{ $type->value }}</p>
                        <label class="mt-3 flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" name="preferences[{{ $type->value }}][in_app_enabled]" value="1" @checked($preference?->in_app_enabled ?? true) class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            {{ __('In-app') }}
                        </label>
                        <label class="mt-2 flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" name="preferences[{{ $type->value }}][email_enabled]" value="1" @checked($preference?->email_enabled ?? false) class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            {{ __('Email') }}
                        </label>
                    </div>
                @endforeach
                <button type="submit" class="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2">{{ __('Save preferences') }}</button>
            </form>
        </section>
    </div>
</x-app-layout>
