<?php

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\CancelPendingBillingCheckoutAction;
use App\Actions\Billing\CreateBillingPortalSessionAction;
use App\Actions\Billing\StartBillingCheckoutAction;
use App\Enums\CompanyPermission;
use App\Exceptions\Billing\BillingProviderException;
use App\Http\Controllers\Controller;
use App\Models\Billing\CompanySubscription;
use App\Models\User;
use App\Services\Billing\CommercialAccessPolicy;
use App\Services\Billing\CommercialUsageOverview;
use App\Services\Billing\EntitlementService;
use App\Services\Catalog\CatalogProductCapacity;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BillingController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany, EntitlementService $entitlements, CatalogProductCapacity $capacity): View
    {
        $company = $currentCompany->require();
        $this->authorize(CompanyPermission::BillingManage->value, $company);

        $checkout = (string) $request->query('checkout', '');
        $subscription = CompanySubscription::query()->forCompany($company)->first();
        $commercial = (bool) config('billing.commercial_enabled');
        $plans = (array) config('billing.plans');
        if ($commercial) {
            $plans = array_replace(array_fill_keys(['starter', 'growth', 'business', 'enterprise'], []), $plans);
            unset($plans['pilot']);
        }

        return view('billing.index', [
            'company' => $company,
            'subscription' => $subscription,
            'license' => $subscription?->localTrialBeforeCheckout() ?? $subscription,
            'plans' => $plans,
            'commercial' => $commercial,
            'commercialReadOnly' => app(CommercialAccessPolicy::class)->isReadOnly($company),
            'productLimit' => $entitlements->limit($company, 'catalog.products.max'),
            'productCount' => $capacity->count($company),
            'usageOverview' => $company->commercial_model_version === 1
                ? app(CommercialUsageOverview::class)->forCompany($company, $subscription) : null,
            'checkoutStatus' => in_array($checkout, ['success', 'cancelled', 'fake'], true) ? $checkout : null,
        ]);
    }

    public function checkout(Request $request, CurrentCompany $currentCompany, StartBillingCheckoutAction $action): RedirectResponse
    {
        $validated = $request->validate(['plan' => ['required', 'string'], 'interval' => ['sometimes', 'string', 'in:month,year']]);

        try {
            return redirect()->away($action->execute($this->actor($request), $currentCompany->require(), (string) $validated['plan'], (string) ($validated['interval'] ?? 'month')));
        } catch (ValidationException|BillingProviderException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function cancelPending(Request $request, CurrentCompany $currentCompany, CancelPendingBillingCheckoutAction $action): RedirectResponse
    {
        try {
            $action->execute($this->actor($request), $currentCompany->require());

            return back()->with('success', __('Pending checkout cancelled. You can choose a plan again.'));
        } catch (ValidationException|BillingProviderException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function portal(Request $request, CurrentCompany $currentCompany, CreateBillingPortalSessionAction $action): RedirectResponse
    {
        try {
            return redirect()->away($action->execute($this->actor($request), $currentCompany->require()));
        } catch (BillingProviderException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
