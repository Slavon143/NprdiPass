<?php

namespace App\Http\Controllers\Catalog;

use App\Enums\CompanyPermission;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Services\Passports\DppSchemaRegistry;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PassportVersionController extends Controller
{
    public function index(
        Product $product,
        Request $request,
        CurrentCompany $currentCompany,
    ): View {
        $company = $this->resolveCompany($currentCompany);
        $this->assertProductBelongsToCompany($company, $product);
        $passport = $this->resolvePassport($product);

        $versions = $passport->versions()
            ->where('status', '!=', ProductPassportVersionStatus::Draft->value)
            ->orderByDesc('version_number')
            ->get();

        $canPublish = $request->user()?->can(CompanyPermission::PassportsPublish->value, [$company]) === true
            || $request->user()?->can(CompanyPermission::PassportsManage->value, [$company]) === true;

        return view('passports.versions.index', [
            'company' => $company,
            'product' => $product,
            'passport' => $passport,
            'versions' => $versions,
            'canPublish' => $canPublish,
        ]);
    }

    public function show(
        Product $product,
        ProductPassportVersion $version,
        Request $request,
        CurrentCompany $currentCompany,
        DppSchemaRegistry $schemaRegistry,
    ): View {
        $company = $this->resolveCompany($currentCompany);
        $this->assertProductBelongsToCompany($company, $product);
        $passport = $this->resolvePassport($product);

        if ((int) $version->getAttribute('passport_id') !== (int) $passport->getKey()) {
            throw new NotFoundHttpException;
        }

        $canPublish = $request->user()?->can(CompanyPermission::PassportsPublish->value, [$company]) === true
            || $request->user()?->can(CompanyPermission::PassportsManage->value, [$company]) === true;

        return view('passports.versions.show', [
            'company' => $company,
            'product' => $product,
            'passport' => $passport,
            'version' => $version,
            'sections' => $schemaRegistry->sections(),
            'sectionKeys' => $schemaRegistry->sectionKeysInOrder(),
            'fields' => $schemaRegistry->flatFields(),
            'canPublish' => $canPublish,
        ]);
    }

    private function resolveCompany(CurrentCompany $currentCompany): Company
    {
        return $currentCompany->require();
    }

    private function resolvePassport(Product $product): ProductPassport
    {
        $passport = $product->passport;

        if (! $passport instanceof ProductPassport) {
            throw new NotFoundHttpException;
        }

        return $passport;
    }

    private function assertProductBelongsToCompany(Company $company, Product $product): void
    {
        if ((int) $product->getAttribute('company_id') !== (int) $company->getKey()) {
            throw new NotFoundHttpException;
        }
    }
}
