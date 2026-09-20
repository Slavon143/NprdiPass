<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-sm font-medium text-indigo-600">{{ __('Operations') }}</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">{{ __('Job center') }}</h1>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Job') }}</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Status') }}</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Progress') }}</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Created') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    @forelse($jobs as $job)
                        <tr>
                            <td class="px-5 py-4">
                                <a href="{{ route('job-center.show', $job) }}" class="font-semibold text-indigo-700 hover:text-indigo-900">{{ $job->name }}</a>
                                <p class="mt-1 text-xs text-slate-500">{{ $job->type }} · {{ $job->queue }}</p>
                            </td>
                            <td class="px-5 py-4 text-sm text-slate-700">{{ $job->status->value }}</td>
                            <td class="px-5 py-4 text-sm text-slate-700">{{ $job->progress_current }}@if($job->progress_total !== null)/{{ $job->progress_total }}@endif</td>
                            <td class="px-5 py-4 text-right text-sm text-slate-500">{{ $job->created_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-12 text-center text-sm text-slate-500">{{ __('No jobs have been tracked yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if($jobs->hasPages())
                <div class="border-t border-slate-200 px-5 py-4">
                    {{ $jobs->links() }}
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
