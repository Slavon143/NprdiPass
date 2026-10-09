<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\PlatformOperations;
use App\Actions\Platform\ResolveFeedbackAction;
use App\Enums\Platform\PlatformIncidentSeverity;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Platform\FeatureFlagOverride;
use App\Models\Platform\PlatformIncident;
use App\Models\Platform\SupportAccessGrant;
use App\Models\User;
use App\Services\Observability\ObservabilitySnapshotService;
use App\Services\Platform\FeatureFlagManager;
use App\Services\Platform\TenantHealthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Illuminate\View\View;

class PlatformOperationsController extends Controller
{
    public function feedback(): View
    {
        return view('platform.operations.feedback', [
            'feedback' => PlatformIncident::query()->where('type', 'customer_feedback')
                ->with(['company', 'reporter'])->orderByRaw("case when status = 'open' then 0 else 1 end")
                ->latest('updated_at')->latest('id')->paginate(20),
        ]);
    }

    public function resolveFeedback(Request $request, string $feedback, ResolveFeedbackAction $action): RedirectResponse
    {
        $action->execute($this->actor($request), $feedback);

        return redirect()->route('platform.operations.feedback')->with('success', __('Feedback marked as resolved.'));
    }

    public function index(TenantHealthService $health): View
    {
        $companies = Company::query()->latest()->limit(25)->get();

        return view('platform.operations.index', [
            'companies' => $companies,
            'health' => $companies->mapWithKeys(fn (Company $company): array => [$company->uuid => $health->snapshot($company)]),
            'openIncidents' => PlatformIncident::query()->where('status', 'open')->latest()->limit(10)->get(),
        ]);
    }

    public function observability(ObservabilitySnapshotService $observability): View
    {
        return view('platform.operations.observability', [
            'snapshot' => $observability->snapshot(),
        ]);
    }

    public function showCompany(Company $company, TenantHealthService $health, FeatureFlagManager $flags): View
    {
        return view('platform.operations.company', [
            'company' => $company,
            'snapshot' => $health->snapshot($company),
            'supportGrants' => SupportAccessGrant::query()->where('company_id', $company->getKey())->latest()->limit(10)->get(),
            'flagOverrides' => FeatureFlagOverride::query()->forCompanyOrGlobal($company)->orderBy('flag')->get(),
            'knownFlags' => $flags->knownFlags(),
            'incidents' => PlatformIncident::query()->where('company_id', $company->getKey())->latest()->limit(10)->get(),
        ]);
    }

    public function grantSupport(Request $request, Company $company, PlatformOperations $operations): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'minutes' => ['required', 'integer', 'min:15', 'max:1440'],
        ]);

        $operations->grantSupportAccess($this->actor($request), $company, $validated['reason'], (int) $validated['minutes']);

        return back()->with('status', 'Support access granted.');
    }

    public function revokeSupport(Request $request, Company $company, string $grant, PlatformOperations $operations): RedirectResponse
    {
        $model = SupportAccessGrant::query()
            ->where('company_id', $company->getKey())
            ->where('uuid', $grant)
            ->firstOrFail();

        $operations->revokeSupportAccess($this->actor($request), $model);

        return back()->with('status', 'Support access revoked.');
    }

    public function updateFlag(Request $request, Company $company, PlatformOperations $operations): RedirectResponse
    {
        $validated = $request->validate([
            'flag' => ['required', 'string', 'max:100'],
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $operations->setFeatureFlag(
            $this->actor($request),
            $company,
            $validated['flag'],
            (bool) $validated['enabled'],
            $validated['reason'],
        );

        return back()->with('status', 'Feature flag override saved.');
    }

    public function reportIncident(Request $request, Company $company, PlatformOperations $operations): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'max:100'],
            'severity' => ['required', new Enum(PlatformIncidentSeverity::class)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $operations->reportIncident(
            $this->actor($request),
            $company,
            $validated['type'],
            PlatformIncidentSeverity::from($validated['severity']),
            $validated['title'],
            $validated['description'] ?? null,
        );

        return back()->with('status', 'Incident reported.');
    }

    public function resolveIncident(Request $request, string $incident, PlatformOperations $operations): RedirectResponse
    {
        $model = PlatformIncident::query()->where('uuid', $incident)->firstOrFail();
        $operations->resolveIncident($this->actor($request), $model);

        return back()->with('status', 'Incident resolved.');
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
