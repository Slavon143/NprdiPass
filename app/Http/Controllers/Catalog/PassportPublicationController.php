<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Passports\ArchiveProductPassport;
use App\Actions\Passports\PublishProductPassport;
use App\Actions\Passports\RestoreProductPassport;
use App\Actions\Passports\UnpublishProductPassport;
use App\Enums\CompanyPermission;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Passports\ArchivePassportRequest;
use App\Http\Requests\Passports\PublishPassportRequest;
use App\Http\Requests\Passports\RestorePassportRequest;
use App\Http\Requests\Passports\UnpublishPassportRequest;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Services\Passports\Readiness\ReadinessContextBuilder;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PassportPublicationController extends Controller
{
    public function publish(
        Product $product,
        PublishPassportRequest $request,
        CurrentCompany $currentCompany,
        PublishProductPassport $action,
    ): RedirectResponse {
        $company = $this->resolveCompany($currentCompany);
        $this->assertProductBelongsToCompany($company, $product);

        $passport = $this->resolvePassport($product);
        $validated = $request->validated();

        try {
            $result = $action->handle(
                $this->actor($request),
                $company,
                $product,
                $passport,
                (int) $validated['expected_revision'],
                (bool) ($validated['acknowledge_warnings'] ?? false),
            );

            return redirect()
                ->route('catalog.products.passport.show', ['product' => $product->uuid])
                ->with('success', 'Passport published as Version '.$result->publishedVersion->version_number.'.');
        } catch (ConflictHttpException $e) {
            return redirect()
                ->route('catalog.products.passport.publish-confirm', ['product' => $product->uuid])
                ->with('error', $e->getMessage())
                ->withInput();
        } catch (ValidationException $e) {
            return redirect()
                ->route('catalog.products.passport.publish-confirm', ['product' => $product->uuid])
                ->withErrors($e->errors())
                ->withInput();
        }
    }

    public function unpublish(
        Product $product,
        UnpublishPassportRequest $request,
        CurrentCompany $currentCompany,
        UnpublishProductPassport $action,
    ): RedirectResponse {
        $company = $this->resolveCompany($currentCompany);
        $this->assertProductBelongsToCompany($company, $product);

        $passport = $this->resolvePassport($product);

        try {
            $action->handle(
                $this->actor($request),
                $company,
                $product,
                $passport,
            );

            return redirect()
                ->route('catalog.products.passport.show', ['product' => $product->uuid])
                ->with('success', 'Passport has been unpublished.');
        } catch (ConflictHttpException $e) {
            return redirect()
                ->route('catalog.products.passport.show', ['product' => $product->uuid])
                ->with('error', $e->getMessage());
        }
    }

    public function archive(
        Product $product,
        ArchivePassportRequest $request,
        CurrentCompany $currentCompany,
        ArchiveProductPassport $action,
    ): RedirectResponse {
        $company = $this->resolveCompany($currentCompany);
        $this->assertProductBelongsToCompany($company, $product);

        $passport = $this->resolvePassport($product);

        try {
            $action->handle(
                $this->actor($request),
                $company,
                $product,
                $passport,
            );

            return redirect()
                ->route('catalog.products.passport.show', ['product' => $product->uuid])
                ->with('success', 'Passport has been archived.');
        } catch (ConflictHttpException $e) {
            return redirect()
                ->route('catalog.products.passport.show', ['product' => $product->uuid])
                ->with('error', $e->getMessage());
        }
    }

    public function restore(
        Product $product,
        RestorePassportRequest $request,
        CurrentCompany $currentCompany,
        RestoreProductPassport $action,
    ): RedirectResponse {
        $company = $this->resolveCompany($currentCompany);
        $this->assertProductBelongsToCompany($company, $product);

        $passport = $this->resolvePassport($product);

        try {
            $action->handle(
                $this->actor($request),
                $company,
                $product,
                $passport,
            );

            return redirect()
                ->route('catalog.products.passport.show', ['product' => $product->uuid])
                ->with('success', 'Passport has been restored.');
        } catch (ConflictHttpException $e) {
            return redirect()
                ->route('catalog.products.passport.show', ['product' => $product->uuid])
                ->with('error', $e->getMessage());
        }
    }

    public function publishConfirm(
        Product $product,
        Request $request,
        CurrentCompany $currentCompany,
        ReadinessContextBuilder $builder,
        PassportReadinessEvaluator $evaluator,
    ): View {
        $company = $this->resolveCompany($currentCompany);
        $this->assertProductBelongsToCompany($company, $product);

        $passport = $this->resolvePassport($product);

        $draft = $passport->currentDraftVersion;

        if ($draft === null || $draft->status !== ProductPassportVersionStatus::Draft) {
            throw new NotFoundHttpException;
        }

        $readinessContext = $builder->build($company, $product);
        $readinessResult = $evaluator->evaluate($readinessContext);

        $canPublish = $request->user()?->can(CompanyPermission::PassportsPublish->value, [$company]) === true
            || $request->user()?->can(CompanyPermission::PassportsManage->value, [$company]) === true;

        return view('passports.publish-confirm', [
            'company' => $company,
            'product' => $product,
            'passport' => $passport,
            'draft' => $draft,
            'readiness' => $readinessResult,
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

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
