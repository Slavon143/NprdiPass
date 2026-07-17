<x-app-layout>
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Passport Readiness: {{ $product->name }}</h1>
        <div>
            <a href="{{ route('catalog.products.passport.edit', $product->uuid) }}" class="text-blue-600 hover:underline mr-4">Back to Passport Editor</a>
            <a href="{{ route('catalog.products.show', $product->uuid) }}" class="text-blue-600 hover:underline">Back to Product</a>
        </div>
    </div>

    <div class="space-y-6">
        {{-- Publication Action Card --}}
        <div class="bg-white shadow rounded-lg p-6">
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="text-lg font-semibold mb-1">Publication Status</h2>
                    @if($readiness->status->value === 'not_ready')
                        <div class="flex items-center gap-2">
                            <span class="px-3 py-1 rounded-full text-sm font-semibold bg-red-100 text-red-800">Not Ready</span>
                            <span class="text-sm text-gray-600">Resolve blockers before publishing</span>
                        </div>
                    @elseif($readiness->status->value === 'ready_with_warnings')
                        <div class="flex items-center gap-2">
                            <span class="px-3 py-1 rounded-full text-sm font-semibold bg-amber-100 text-amber-800">Ready with Warnings</span>
                            <span class="text-sm text-gray-600">{{ $readiness->counts->warnings }} warning(s)</span>
                        </div>
                    @else
                        <div class="flex items-center gap-2">
                            <span class="px-3 py-1 rounded-full text-sm font-semibold bg-green-100 text-green-800">Ready for Publication</span>
                        </div>
                    @endif
                </div>
                <div>
                    @php
                        $user = auth()->user();
                        $canPublish = $user && ($user->can(\App\Enums\CompanyPermission::PassportsPublish->value, [$company]) || $user->can(\App\Enums\CompanyPermission::PassportsManage->value, [$company]));
                    @endphp
                    @if($canPublish)
                        @if($readiness->status->value === 'not_ready')
                            <button type="button" class="bg-gray-400 text-white px-4 py-2 rounded text-sm cursor-not-allowed" disabled>
                                Publish Passport
                            </button>
                        @else
                            <a href="{{ route('catalog.products.passport.publish-confirm', $product->uuid) }}"
                               class="inline-block bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700 text-sm font-semibold">
                                Publish Passport
                            </a>
                        @endif
                    @endif
                </div>
            </div>
        </div>

        {{-- Status & Score --}}
        <div class="bg-white shadow rounded-lg p-6">
            <div class="flex items-center gap-4 mb-4">
                <span class="px-4 py-2 rounded-full text-sm font-semibold @if($readiness->status->value === 'ready') bg-green-100 text-green-800 @elseif($readiness->status->value === 'ready_with_warnings') bg-amber-100 text-amber-800 @else bg-red-100 text-red-800 @endif">
                    {{ str_replace('_', ' ', ucfirst($readiness->status->value)) }}
                </span>
                <span class="text-lg font-bold">Score: {{ $readiness->score }}%</span>
            </div>

            <div class="w-full bg-gray-200 rounded-full h-4">
                <div class="h-4 rounded-full @if($readiness->score >= 80) bg-green-500 @elseif($readiness->score >= 50) bg-amber-500 @else bg-red-500 @endif" style="width: {{ $readiness->score }}%"></div>
            </div>
        </div>

        {{-- Profile & Passport Info --}}
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-4">Profile &amp; Version Info</h2>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div><strong>Profile:</strong> {{ $readiness->profile }}</div>
                <div><strong>Profile Version:</strong> {{ $readiness->profileVersion }}</div>
                <div><strong>Schema Version:</strong> {{ $readiness->schemaVersion }}</div>
                <div><strong>Passport UUID:</strong> {{ $readiness->passportUuid }}</div>
                <div><strong>Draft Version UUID:</strong> {{ $readiness->draftVersionUuid ?? 'N/A' }}</div>
                <div><strong>Passport Revision:</strong> {{ $readiness->passportRevision }}</div>
            </div>
        </div>

        {{-- Counts Summary --}}
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-4">Rule Summary</h2>
            <div class="flex gap-4 text-sm">
                <span class="px-3 py-1 bg-red-100 text-red-800 rounded-full font-semibold">Blockers: {{ $readiness->counts->blockers }}</span>
                <span class="px-3 py-1 bg-amber-100 text-amber-800 rounded-full font-semibold">Warnings: {{ $readiness->counts->warnings }}</span>
                <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full font-semibold">Recommendations: {{ $readiness->counts->recommendations }}</span>
                <span class="px-3 py-1 bg-green-100 text-green-800 rounded-full font-semibold">Passed: {{ $readiness->counts->passed }}</span>
                <span class="px-3 py-1 bg-gray-100 text-gray-600 rounded-full font-semibold">Not Applicable: {{ $readiness->counts->notApplicable }}</span>
            </div>
        </div>

        {{-- Rules by Group --}}
        @php
            $rulesByGroup = [];
            foreach ($readiness->rules as $rule) {
                $rulesByGroup[$rule->group->value][] = $rule;
            }
        @endphp

        @foreach ($rulesByGroup as $group => $rules)
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-4 capitalize">{{ $group }} Rules</h2>
            <div class="space-y-3">
                @foreach ($rules as $rule)
                <div class="border rounded-lg p-4 @if($rule->status->value === 'passed') border-green-300 bg-green-50 @elseif($rule->status->value === 'failed') border-red-300 bg-red-50 @else border-gray-300 bg-gray-50 @endif">
                    <div class="flex items-center justify-between mb-1">
                        <div class="flex items-center gap-2">
                            @if(app()->isLocal() && config('app.debug'))
                            <span class="text-xs font-mono text-slate-400">{{ $rule->code }}</span>
                            @endif
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold @if($rule->status->value === 'passed') bg-green-200 text-green-800 @elseif($rule->status->value === 'failed') bg-red-200 text-red-800 @else bg-gray-200 text-gray-600 @endif">
                                {{ ucfirst($rule->status->value) }}
                            </span>
                        </div>
                        <span class="px-2 py-0.5 rounded-full text-xs font-semibold @if($rule->severity->value === 'blocker') bg-red-200 text-red-800 @elseif($rule->severity->value === 'warning') bg-amber-200 text-amber-800 @else bg-blue-200 text-blue-800 @endif">
                            {{ ucfirst($rule->severity->value) }}
                        </span>
                    </div>
                    @php
                        $statusSuffix = $rule->status->value;
                        $titleText = __("readiness.{$rule->code}.title", []);
                        if ($titleText === "readiness.{$rule->code}.title") {
                            $titleText = __($rule->titleKey);
                            if ($titleText === $rule->titleKey) {
                                $titleText = ucfirst(trim(str_replace('.', ' ', str_replace('readiness.', '', $rule->titleKey)), '. title'));
                            }
                        }
                        $msgText = __("readiness.{$rule->code}.{$statusSuffix}", []);
                        if ($msgText === "readiness.{$rule->code}.{$statusSuffix}") {
                            $msgText = __("readiness.{$rule->code}.message", []);
                        }
                        if ($msgText === "readiness.{$rule->code}.message" || $msgText === "readiness.{$rule->code}.{$statusSuffix}") {
                            $msgText = __($rule->messageKey);
                            if ($msgText === $rule->messageKey) {
                                $msgText = ucfirst(trim(str_replace('.', ' ', str_replace('readiness.', '', $rule->messageKey)), '. failed passed not_applicable recommendation'));
                            }
                        }
                    @endphp
                    <p class="text-sm font-medium text-slate-900">{{ $titleText }}</p>
                    <p class="text-xs text-slate-600 mt-1">{{ $msgText }}</p>

                    @if ($rule->navigationTarget !== null && $rule->status->value === 'failed')
                    <div class="mt-2">
                        <a href="{{ route($rule->navigationTarget->routeName, $rule->navigationTarget->routeParameters) }}" class="inline-flex items-center gap-1 text-indigo-600 hover:underline text-xs font-semibold">
                            {{ __('Open') }} {{ $rule->navigationTarget->label }} &rarr;
                        </a>
                    </div>
                    @endif
                </div>
                @endforeach
            </div>
        </div>
        @endforeach

        {{-- Legal Disclaimer --}}
        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-xs text-gray-500">
            Readiness is operational validation and not legal certification.
        </div>

        {{-- Evaluated At --}}
        <div class="text-xs text-gray-400">
            Evaluated at: {{ $readiness->evaluatedAt->toISOString() }}
        </div>
    </div>
</div>
</x-app-layout>
