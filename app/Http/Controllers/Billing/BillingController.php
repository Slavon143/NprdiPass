<?php

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\CreateBillingPortalSessionAction;
use App\Actions\Billing\StartBillingCheckoutAction;
use App\Enums\CompanyPermission;
use App\Exceptions\Billing\BillingProviderException;
use App\Http\Controllers\Controller;
use App\Models\Billing\CompanySubscription;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BillingController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany, EntitlementService $entitlements): View
    {
        $company = $currentCompany->require();
        $this->authorize(CompanyPermission::BillingManage->value, $company);

        $checkout = (string) $request->query('checkout', '');

        return view('billing.index', [
            'company' => $company,
            'subscription' => CompanySubscription::query()->forCompany($company)->first(),
            'plans' => config('billing.plans'),
            'productLimit' => $entitlements->limit($company, 'catalog.products.max'),
            'productCount' => $company->products()->count(),
            'checkoutStatus' => in_array($checkout, ['success', 'cancelled', 'fake'], true) ? $checkout : null,
        ]);
    }

    public function checkout(Request $request, CurrentCompany $currentCompany, StartBillingCheckoutAction $action): RedirectResponse
    {
        $validated = $request->validate(['plan' => ['required', 'string']]);

        try {
            return redirect()->away($action->execute($this->actor($request), $currentCompany->require(), (string) $validated['plan']));
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
