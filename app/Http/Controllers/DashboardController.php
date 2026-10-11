<?php

namespace App\Http\Controllers;

use App\Enums\Passports\ProductPassportStatus;
use App\Models\Billing\CompanySubscription;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Services\Billing\EntitlementService;
use App\Services\Catalog\CatalogProductCapacity;
use App\Services\Passports\Readiness\ProductIndexReadinessProvider;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;

class DashboardController extends Controller
{
    public function __invoke(
        CurrentCompany $currentCompany,
        EntitlementService $entitlements,
        CatalogProductCapacity $capacity,
        ProductIndexReadinessProvider $readinessProvider,
    ): View {
        $company = $currentCompany->require();
        $this->authorize('view', $company);

        $membership = request()->attributes->get('currentMembership');
        abort_unless($membership instanceof CompanyMembership, 403);

        $productCount = $capacity->count($company);
        $passportCount = ProductPassport::query()->forCompany($company)->count();
        $publishedPassportCount = ProductPassport::query()
            ->forCompany($company)
            ->where('status', ProductPassportStatus::Published->value)
            ->count();
        $draftPassportCount = ProductPassport::query()
            ->forCompany($company)
            ->where('status', ProductPassportStatus::Draft->value)
            ->count();
        $documentCount = ProductDocument::query()->where('company_id', $company->id)->count();

        $recentProducts = Product::query()
            ->forCompany($company)
            ->with(['passport', 'primaryMedia'])
            ->latest('updated_at')
            ->limit(5)
            ->get();
        $recentProductPaginator = new LengthAwarePaginator(
            $recentProducts,
            $recentProducts->count(),
            max($recentProducts->count(), 1),
        );
        $passportSummaries = $readinessProvider->loadSummaries($company, $recentProductPaginator);

        return view()->make('dashboard.index', [
            'company' => $company,
            'membership' => $membership,
            'memberCount' => $company->memberships()->count(),
            'productCount' => $productCount,
            'passportCount' => $passportCount,
            'publishedPassportCount' => $publishedPassportCount,
            'draftPassportCount' => $draftPassportCount,
            'documentCount' => $documentCount,
            'recentProducts' => $recentProducts,
            'passportSummaries' => $passportSummaries,
            'subscription' => CompanySubscription::query()->forCompany($company)->first(),
            'plans' => config('billing.plans'),
            'productLimit' => $entitlements->limit($company, 'catalog.products.max'),
            'canCreateProducts' => request()->user()?->can('create', [Product::class, $company]) === true,
        ]);
    }
}
