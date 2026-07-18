<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Settings') }}</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Members') }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ __('Manage access to :company.', ['company' => $company->name]) }}</p>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        @if ($canManageInvitations)
            <section class="mb-8 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="grid gap-6 border-b border-slate-200 px-5 py-5 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,0.8fr)] lg:px-6">
                    <div>
                        <p class="text-sm font-medium text-indigo-600">{{ __('Invitations') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-slate-900">{{ __('Invite a company member') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm text-slate-500">{{ __('A secure, expiring link will be sent by email. Existing pending links for the same address are cancelled.') }}</p>
                    </div>

                    <form method="POST" action="{{ route('settings.members.invitations.store') }}" class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_9rem_auto] sm:items-start">
                        @csrf
                        <div>
                            <label for="invitation-email" class="sr-only">{{ __('Email') }}</label>
                            <input id="invitation-email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email" placeholder="name@example.com" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </div>
                        <div>
                            <label for="invitation-role" class="sr-only">{{ __('Role') }}</label>
                            <select id="invitation-role" name="role" required class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                @foreach ($invitationRoleOptions as $invitationRole)
                                    <option value="{{ $invitationRole->value }}" @selected(old('role', \App\Enums\CompanyRole::Viewer->value) === $invitationRole->value)>{{ ucfirst($invitationRole->value) }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('role')" class="mt-2" />
                        </div>
                        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">{{ __('Send invite') }}</button>
                    </form>
                </div>

                @if ($invitations->isEmpty())
                    <div class="px-6 py-10 text-center">
                        <p class="font-semibold text-slate-900">{{ __('No invitations yet.') }}</p>
                        <p class="mt-1 text-sm text-slate-500">{{ __('New invitations and their recent history will appear here.') }}</p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th scope="col" class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500 sm:px-6">{{ __('Email') }}</th>
                                    <th scope="col" class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Role') }}</th>
                                    <th scope="col" class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Invited by') }}</th>
                                    <th scope="col" class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Expires') }}</th>
                                    <th scope="col" class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Status') }}</th>
                                    <th scope="col" class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500 sm:px-6">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @foreach ($invitations as $invitation)
                                    @php
                                        $invitationStatus = match (true) {
                                            $invitation->isAccepted() => 'accepted',
                                            $invitation->isCancelled() => 'cancelled',
                                            $invitation->isExpired() => 'expired',
                                            default => 'pending',
                                        };
                                    @endphp
                                    <tr class="align-top">
                                        <td class="max-w-xs break-all px-5 py-4 text-sm font-semibold text-slate-900 sm:px-6">{{ $invitation->email }}</td>
                                        <td class="whitespace-nowrap px-5 py-4"><x-badge tone="slate">{{ $invitation->role->value }}</x-badge></td>
                                        <td class="whitespace-nowrap px-5 py-4 text-sm text-slate-600">{{ $invitation->inviter?->name ?: __('Former member') }}</td>
                                        <td class="whitespace-nowrap px-5 py-4 text-sm text-slate-600">{{ $invitation->expires_at->format('Y-m-d H:i') }}</td>
                                        <td class="whitespace-nowrap px-5 py-4">
                                            <x-badge :tone="match ($invitationStatus) { 'pending' => 'amber', 'accepted' => 'emerald', 'cancelled' => 'slate', default => 'red' }">{{ $invitationStatus }}</x-badge>
                                        </td>
                                        <td class="px-5 py-4 sm:px-6">
                                            <div class="flex min-w-40 items-center justify-end gap-2">
                                                @if ($invitation->isPending())
                                                    @can('resend', $invitation)
                                                        <form method="POST" action="{{ route('settings.members.invitations.resend', ['invitation' => $invitation->uuid]) }}">
                                                            @csrf
                                                            <button type="submit" class="rounded-lg px-3 py-2 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">{{ __('Resend') }}</button>
                                                        </form>
                                                    @endcan
                                                    @can('delete', $invitation)
                                                        <form method="POST" action="{{ route('settings.members.invitations.destroy', ['invitation' => $invitation->uuid]) }}" x-data="{ message: @js(__('Cancel the invitation for :email?', ['email' => $invitation->email])) }" @submit.prevent="if (window.confirm(message)) $el.submit()">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="rounded-lg px-3 py-2 text-xs font-semibold text-red-700 transition hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">{{ __('Cancel') }}</button>
                                                        </form>
                                                    @endcan
                                                @else
                                                    <span class="text-xs text-slate-400">{{ __('No actions available') }}</span>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($invitations->hasPages())
                        <div class="border-t border-slate-200 px-5 py-4 sm:px-6">{{ $invitations->links() }}</div>
                    @endif
                @endif
            </section>
        @endif

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-2 border-b border-slate-200 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">{{ __('Company members') }}</h2>
                    <p class="text-sm text-slate-500">{{ trans_choice(':count member|:count members', $memberships->total(), ['count' => $memberships->total()]) }}</p>
                </div>
                <x-badge tone="indigo">{{ __('Your role: :role', ['role' => ucfirst($currentMembership->role->value)]) }}</x-badge>
            </div>

            @if ($memberships->isEmpty())
                <div class="px-6 py-16 text-center">
                    <p class="font-semibold text-slate-900">{{ __('No members found.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th scope="col" class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500 sm:px-6">{{ __('Member') }}</th>
                                <th scope="col" class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Role') }}</th>
                                <th scope="col" class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Status') }}</th>
                                <th scope="col" class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('Joined') }}</th>
                                <th scope="col" class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500 sm:px-6">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @foreach ($memberships as $membership)
                                <tr class="align-top">
                                    <td class="max-w-xs px-5 py-4 sm:px-6">
                                        <div class="font-semibold text-slate-900">{{ $membership->user->name }}</div>
                                        <div class="break-all text-sm text-slate-500">{{ $membership->user->email }}</div>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-badge :tone="$membership->is_owner ? 'indigo' : 'slate'">{{ __(ucfirst($membership->role->value)) }}</x-badge>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4">
                                        <x-badge :tone="match ($membership->user->status->value) { 'active' => 'emerald', 'suspended' => 'red', default => 'amber' }">
                                            {{ __(ucfirst($membership->user->status->value)) }}
                                        </x-badge>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm text-slate-600">
                                        {{ $membership->joined_at?->format('Y-m-d') ?: __('Not provided') }}
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <div class="flex min-w-48 flex-col items-end gap-2">
                                            @can('updateRole', $membership)
                                                @if ($membership->role === \App\Enums\CompanyRole::Owner && $ownerCount === 1)
                                                    <p class="max-w-48 text-right text-xs text-slate-500">{{ __('At least one owner is required.') }}</p>
                                                @else
                                                    <form method="POST" action="{{ route('settings.members.role.update', ['membership' => $membership->getKey()]) }}" class="flex items-center gap-2">
                                                        @csrf
                                                        @method('PATCH')
                                                        <label class="sr-only" for="role-{{ $membership->getKey() }}">{{ __('Role for :name', ['name' => $membership->user->name]) }}</label>
                                                        <select id="role-{{ $membership->getKey() }}" name="role" class="rounded-lg border-slate-300 py-1.5 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                            @foreach ($roleOptions as $roleOption)
                                                                <option value="{{ $roleOption->value }}" @selected($membership->role === $roleOption)>{{ ucfirst($roleOption->value) }}</option>
                                                            @endforeach
                                                        </select>
                                                        <button type="submit" class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white transition hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">{{ __('Update') }}</button>
                                                    </form>
                                                @endif
                                            @endcan

                                            @can('remove', $membership)
                                                <form method="POST" action="{{ route('settings.members.destroy', ['membership' => $membership->getKey()]) }}" x-data="{ message: @js(__('Remove :name from this company?', ['name' => $membership->user->name])) }" @submit.prevent="if (window.confirm(message)) $el.submit()">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="rounded-lg px-3 py-2 text-xs font-semibold text-red-700 transition hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">{{ __('Remove') }}</button>
                                                </form>
                                            @endcan

                                            @cannot('updateRole', $membership)
                                                @cannot('remove', $membership)
                                                    <span class="text-xs text-slate-400">{{ __('No actions available') }}</span>
                                                @endcannot
                                            @endcannot
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($memberships->hasPages())
                    <div class="border-t border-slate-200 px-5 py-4 sm:px-6">
                        {{ $memberships->links() }}
                    </div>
                @endif
            @endif
        </section>
    </div>
</x-app-layout>
