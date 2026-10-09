<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Passports\RecordPassportValidationRun;
use App\Actions\Passports\UpdateProductPassportSettingsAction;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyPermission;
use App\Exceptions\Billing\CommercialReadOnlyException;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Services\Billing\CommercialAccessPolicy;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Services\Passports\Readiness\ReadinessContextBuilder;
use App\Services\Passports\Readiness\StoredPassportReadiness;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PassportReadinessController extends Controller
{
    public function updateContext(Product $product, Request $request, CurrentCompany $currentCompany, UpdateProductPassportSettingsAction $action): RedirectResponse
    {
        $company = $currentCompany->require();
        if ((int) $product->company_id !== (int) $company->getKey()) {
            throw new NotFoundHttpException;
        }
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $validated = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'regulatory_context' => ['sometimes', 'array'],
            'refresh_profile' => ['sometimes', 'boolean'],
        ]);
        $passport = ProductPassport::query()->forCompany($company)->where('product_id', $product->getKey())->firstOrFail();
        $settings = array_intersect_key($validated, array_flip(['regulatory_context', 'refresh_profile']));
        if (isset($settings['refresh_profile'])) {
            $settings['refresh_profile'] = (bool) $settings['refresh_profile'];
        }
        try {
            $action->handle($actor, $company, $product, $passport, $settings, (int) $validated['expected_revision']);
        } catch (ConflictHttpException $exception) {
            return redirect()->route('catalog.products.passport.readiness', $product->uuid)->with('error', $exception->getMessage());
        }

        return redirect()->route('catalog.products.passport.readiness', $product->uuid)->with('success', __('dpp.saved'));
    }

    public function show(
        Product $product,
        Request $request,
        CurrentCompany $currentCompany,
        ReadinessContextBuilder $builder,
        PassportReadinessEvaluator $evaluator,
        RecordPassportValidationRun $recordValidationRun,
    ): View {
        $company = $currentCompany->require();

        if ((int) $product->getAttribute('company_id') !== (int) $company->getKey()) {
            throw new NotFoundHttpException;
        }

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $this->authorize(CompanyPermission::PassportsView->value, $company);

        if (app(CommercialAccessPolicy::class)->isReadOnly($company)
            || ! $product->managed || $product->status === ProductStatus::Archived) {
            $stored = app(StoredPassportReadiness::class)->latest($company, $product);
            if ($stored === null) {
                return view('passports.readiness-unavailable', ['company' => $company, 'product' => $product]);
            }

            return view('passports.readiness', [
                'readiness' => $stored, 'product' => $product, 'company' => $company,
                'passport' => ProductPassport::query()->forCompany($company)->where('product_id', $product->getKey())->first(),
                'readinessHistorical' => true,
            ]);
        }

        $context = $builder->build($company, $product);
        $result = $evaluator->evaluate($context);

        if ($context->passport !== null && $context->currentDraft !== null
            && ! app(CommercialAccessPolicy::class)->isReadOnly($company)) {
            try {
                $recordValidationRun->handle($company, $context->passport, $context->currentDraft, $result, $actor);
            } catch (CommercialReadOnlyException) {
                // Expiration may cross the read/write boundary while rendering this view.
            }
        }

        return view('passports.readiness', [
            'readiness' => $result,
            'product' => $product,
            'company' => $company,
            'passport' => $context->passport,
        ]);
    }
}
