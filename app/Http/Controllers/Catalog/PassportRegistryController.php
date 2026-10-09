<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Passports\PrepareDppRegistryRegistration;
use App\Enums\CompanyPermission;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Models\Passports\DppRegistryPreparation;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Services\Dpp\BatteryPassportFieldCatalogue;
use App\Services\Passports\Qr\PassportQrPayloadFactory;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PassportRegistryController extends Controller
{
    public function show(Product $product, CurrentCompany $currentCompany, BatteryPassportFieldCatalogue $catalogue): View
    {
        $company = $currentCompany->require();
        abort_unless((int) $product->company_id === (int) $company->getKey(), 404);
        $this->authorize(CompanyPermission::PassportsView->value, $company);
        $passport = ProductPassport::query()->forCompany($company)->where('product_id', $product->getKey())->firstOrFail();
        $passport->load(['currentDraftVersion', 'currentPublishedVersion']);
        $version = $passport->currentDraftVersion ?? $passport->currentPublishedVersion;
        $payload = $version->payload ?? [];
        if (! is_array($payload['languages'] ?? null)) {
            $payload['languages'] = [];
        }
        $payload['languages']['default'] ??= $passport->default_language;
        $category = data_get($payload, 'regulatory_context.product_type');
        $uri = app(PassportQrPayloadFactory::class)->create($passport->public_id);
        $points = in_array($category, config('battery_passport_guidance.categories'), true)
            ? $catalogue->assess($payload, $category, $uri) : [];
        $preparations = DppRegistryPreparation::query()->forCompany($company)
            ->where('dpp_registry_preparations.passport_id', $passport->getKey())
            ->join('product_passport_versions as prepared_versions', function ($join): void {
                $join->on('prepared_versions.id', '=', 'dpp_registry_preparations.published_version_id')
                    ->on('prepared_versions.company_id', '=', 'dpp_registry_preparations.company_id');
            })
            ->select(['dpp_registry_preparations.*', 'prepared_versions.uuid as prepared_version_uuid', 'prepared_versions.version_number as prepared_version_number'])
            ->orderByDesc('dpp_registry_preparations.id')->paginate(20);

        return view('passports.registry', compact('company', 'product', 'passport', 'version', 'category', 'points', 'preparations'));
    }

    public function prepare(Product $product, Request $request, CurrentCompany $currentCompany, PrepareDppRegistryRegistration $action): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $validated = $request->validate([
            'published_version_uuid' => ['required', 'uuid'],
            'environment' => ['required', 'in:acceptance,production'],
        ]);
        try {
            $action->handle($actor, $currentCompany->require(), $product, $validated['published_version_uuid'], $validated['environment']);
        } catch (ConflictHttpException $exception) {
            return redirect()->route('catalog.products.passport.registry', $product->uuid)->with('error', $exception->getMessage());
        }

        return redirect()->route('catalog.products.passport.registry', $product->uuid)->with('success', __('Local preparation recorded. No data was sent to the EU registry.'));
    }
}
