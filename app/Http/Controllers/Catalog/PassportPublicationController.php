<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Approvals\ApprovePassportPublicationApprovalAction;
use App\Actions\Approvals\RequestPassportPublicationApprovalAction;
use App\Actions\Passports\ArchiveProductPassport;
use App\Actions\Passports\PublishProductPassport;
use App\Actions\Passports\RestoreProductPassport;
use App\Actions\Passports\UnpublishProductPassport;
use App\Enums\Approvals\PassportPublicationApprovalStatus;
use App\Enums\CompanyPermission;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Passports\ArchivePassportRequest;
use App\Http\Requests\Passports\PublishPassportRequest;
use App\Http\Requests\Passports\RestorePassportRequest;
use App\Http\Requests\Passports\UnpublishPassportRequest;
use App\Models\Approvals\PassportPublicationApproval;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Services\Passports\Readiness\PassportReadinessDisplay;
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
                ->to(route('catalog.products.passport.show', ['product' => $product->uuid]).'#published-version')
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

    public function requestPublicationApproval(
        Product $product,
        PublishPassportRequest $request,
        CurrentCompany $currentCompany,
        RequestPassportPublicationApprovalAction $action,
    ): RedirectResponse {
        $company = $this->resolveCompany($currentCompany);
        $this->assertProductBelongsToCompany($company, $product);

        $passport = $this->resolvePassport($product);
        $validated = $request->validated();

        try {
            $action->handle(
                $this->actor($request),
                $company,
                $product,
                $passport,
                (int) $validated['expected_revision'],
                (bool) ($validated['acknowledge_warnings'] ?? false),
            );

            return redirect()
                ->route('catalog.products.passport.publish-confirm', ['product' => $product->uuid])
                ->with('success', 'Publication approval requested.');
        } catch (ConflictHttpException $e) {
            return redirect()
                ->route('catalog.products.passport.publish-confirm', ['product' => $product->uuid])
                ->with('error', $e->getMessage())
                ->withInput();
        }
    }

    public function approvePublicationApproval(
        Product $product,
        PassportPublicationApproval $approval,
        Request $request,
        CurrentCompany $currentCompany,
        ApprovePassportPublicationApprovalAction $action,
    ): RedirectResponse {
        $company = $this->resolveCompany($currentCompany);
        $this->assertProductBelongsToCompany($company, $product);

        $passport = $this->resolvePassport($product);

        try {
            $result = $action->handle(
                $this->actor($request),
                $company,
                $product,
                $passport,
                $approval,
            );

            return redirect()
                ->to(route('catalog.products.passport.show', ['product' => $product->uuid]).'#published-version')
                ->with('success', 'Publication approved and Passport published as Version '.$result->publishedVersion->version_number.'.');
        } catch (ConflictHttpException $e) {
            return redirect()
                ->route('catalog.products.passport.publish-confirm', ['product' => $product->uuid])
                ->with('error', $e->getMessage());
        } catch (ValidationException $e) {
            return redirect()
                ->route('catalog.products.passport.publish-confirm', ['product' => $product->uuid])
                ->withErrors($e->errors());
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
    ): View|RedirectResponse {
        $company = $this->resolveCompany($currentCompany);
        $this->assertProductBelongsToCompany($company, $product);

        if (! app(PassportReadinessDisplay::class)->canEvaluate($company, $product)) {
            return redirect()->route('catalog.products.passport.readiness', ['product' => $product->uuid]);
        }

        $passport = $this->resolvePassport($product);

        $draft = $passport->currentDraftVersion;

        if ($draft === null || $draft->status !== ProductPassportVersionStatus::Draft) {
            throw new NotFoundHttpException;
        }

        $readinessContext = $builder->build($company, $product);
        $readinessResult = $evaluator->evaluate($readinessContext);

        $canManage = $request->user()?->can(CompanyPermission::PassportsManage->value, [$company]) === true;
        $canPublish = $request->user()?->can(CompanyPermission::PassportsPublish->value, [$company]) === true;
        $pendingApproval = PassportPublicationApproval::query()
            ->where('company_id', $company->getKey())
            ->where('passport_id', $passport->getKey())
            ->where('draft_version_id', $draft->getKey())
            ->where('status', PassportPublicationApprovalStatus::Pending->value)
            ->with('requester')
            ->first();

        return view('passports.publish-confirm', [
            'company' => $company,
            'product' => $product,
            'passport' => $passport,
            'draft' => $draft,
            'readiness' => $readinessResult,
            'canManage' => $canManage,
            'canPublish' => $canPublish,
            'pendingApproval' => $pendingApproval,
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
