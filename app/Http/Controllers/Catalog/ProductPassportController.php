<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\ResetProductPassportSectionAction;
use App\Actions\Passports\SyncProductPassportDocumentsAction;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Actions\Passports\UpdateProductPassportSettingsAction;
use App\Enums\CompanyPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Passports\CreatePassportRequest;
use App\Http\Requests\Passports\ResetPassportSectionRequest;
use App\Http\Requests\Passports\SyncPassportDocumentsRequest;
use App\Http\Requests\Passports\UpdatePassportSectionRequest;
use App\Http\Requests\Passports\UpdatePassportSettingsRequest;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Queries\Passports\ProductPassportEditorQuery;
use App\Services\Passports\DppCatalogContextProvider;
use App\Services\Passports\DppSchemaRegistry;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Services\Passports\Readiness\ReadinessContextBuilder;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProductPassportController extends Controller
{
    public function show(
        Product $product,
        CurrentCompany $currentCompany,
        ProductPassportEditorQuery $editorQuery,
        DppCatalogContextProvider $contextProvider,
        DppSchemaRegistry $schemaRegistry,
        Request $request,
    ): View {
        $company = $this->resolveCompany($currentCompany);
        $this->assertProductBelongsToCompany($company, $product);

        $passport = $this->resolvePassport($product);
        $editorData = $editorQuery->editorData($passport);
        $catalogContext = $contextProvider->context($product, $company);
        $sections = $schemaRegistry->sections();
        $sectionKeys = $schemaRegistry->sectionKeysInOrder();

        return view()->make('passports.show', [
            'company' => $company,
            'product' => $product,
            'passport' => $passport,
            'editorData' => $editorData,
            'catalogContext' => $catalogContext,
            'sections' => $sections,
            'sectionKeys' => $sectionKeys,
            'canManage' => $request->user()?->can(CompanyPermission::PassportsManage->value, [$company]) === true,
        ]);
    }

    public function store(
        Product $product,
        CreatePassportRequest $request,
        CurrentCompany $currentCompany,
        CreateProductPassportDraftAction $action,
    ): RedirectResponse {
        $company = $this->resolveCompany($currentCompany);
        $this->assertProductBelongsToCompany($company, $product);

        $passport = $action->handle(
            $this->actor($request),
            $company,
            $product,
        );

        return redirect()
            ->route('catalog.products.passport.edit', ['product' => $product->uuid])
            ->with('success', 'Passport draft created.');
    }

    public function edit(
        Product $product,
        CurrentCompany $currentCompany,
        ProductPassportEditorQuery $editorQuery,
        DppCatalogContextProvider $contextProvider,
        DppSchemaRegistry $schemaRegistry,
        Request $request,
    ): View {
        $company = $this->resolveCompany($currentCompany);
        $this->assertProductBelongsToCompany($company, $product);

        $passport = $this->resolvePassport($product);
        $editorData = $editorQuery->editorData($passport);
        $catalogContext = $contextProvider->context($product, $company);
        $sections = $schemaRegistry->sections();
        $sectionKeys = $schemaRegistry->sectionKeysInOrder();

        return view()->make('passports.editor', [
            'company' => $company,
            'product' => $product,
            'passport' => $passport,
            'editorData' => $editorData,
            'catalogContext' => $catalogContext,
            'sections' => $sections,
            'sectionKeys' => $sectionKeys,
            'canManage' => $request->user()?->can(CompanyPermission::PassportsManage->value, [$company]) === true,
        ]);
    }

    public function updateSection(
        Product $product,
        string $section,
        UpdatePassportSectionRequest $request,
        CurrentCompany $currentCompany,
        UpdateProductPassportSectionAction $action,
        PassportReadinessEvaluator $readinessEvaluator,
        ReadinessContextBuilder $readinessContextBuilder,
    ): JsonResponse {
        $company = $this->resolveCompany($currentCompany);
        $this->assertProductBelongsToCompany($company, $product);

        $passport = $this->resolvePassport($product);

        try {
            $validated = $request->validated();

            $updated = $action->handle(
                $this->actor($request),
                $company,
                $product,
                $passport,
                $section,
                $validated['section_payload'],
                (int) $validated['expected_revision'],
            );

            $draft = $updated->currentDraftVersion;
            $readinessContext = $readinessContextBuilder->build($company, $product);
            $readinessResult = $readinessEvaluator->evaluate($readinessContext);

            return response()->json([
                'data' => [
                    'section' => $section,
                    'passport_uuid' => $updated->getAttribute('uuid'),
                    'draft_version_uuid' => $draft?->getAttribute('uuid'),
                    'draft_revision' => $draft?->getAttribute('draft_revision'),
                    'saved_at' => now()->toISOString(),
                    'payload' => $draft?->getAttribute('payload'),
                    'readiness' => [
                        'score' => $readinessResult->score,
                        'status' => $readinessResult->status->value,
                        'blockers' => $readinessResult->counts->blockers,
                        'warnings' => $readinessResult->counts->warnings,
                        'recommendations' => $readinessResult->counts->recommendations,
                    ],
                ],
            ]);
        } catch (ConflictHttpException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function updateSettings(
        Product $product,
        UpdatePassportSettingsRequest $request,
        CurrentCompany $currentCompany,
        UpdateProductPassportSettingsAction $action,
    ): JsonResponse {
        $company = $this->resolveCompany($currentCompany);
        $this->assertProductBelongsToCompany($company, $product);

        $passport = $this->resolvePassport($product);

        try {
            $validated = $request->validated();

            $updated = $action->handle(
                $this->actor($request),
                $company,
                $product,
                $passport,
                $validated['settings'],
                (int) $validated['expected_revision'],
            );

            $draft = $updated->currentDraftVersion;

            return response()->json([
                'passport_uuid' => $updated->getAttribute('uuid'),
                'draft_version_uuid' => $draft?->getAttribute('uuid'),
                'draft_revision' => $draft?->getAttribute('draft_revision'),
                'payload' => $draft?->getAttribute('payload'),
            ]);
        } catch (ConflictHttpException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function syncDocuments(
        Product $product,
        SyncPassportDocumentsRequest $request,
        CurrentCompany $currentCompany,
        SyncProductPassportDocumentsAction $action,
    ): JsonResponse {
        $company = $this->resolveCompany($currentCompany);
        $this->assertProductBelongsToCompany($company, $product);

        $passport = $this->resolvePassport($product);

        try {
            $validated = $request->validated();

            $updated = $action->handle(
                $this->actor($request),
                $company,
                $product,
                $passport,
                $validated['document_references'],
                (int) $validated['expected_revision'],
            );

            $draft = $updated->currentDraftVersion;

            return response()->json([
                'passport_uuid' => $updated->getAttribute('uuid'),
                'draft_version_uuid' => $draft?->getAttribute('uuid'),
                'draft_revision' => $draft?->getAttribute('draft_revision'),
                'payload' => $draft?->getAttribute('payload'),
            ]);
        } catch (ConflictHttpException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function resetSection(
        Product $product,
        string $section,
        ResetPassportSectionRequest $request,
        CurrentCompany $currentCompany,
        ResetProductPassportSectionAction $action,
        PassportReadinessEvaluator $readinessEvaluator,
        ReadinessContextBuilder $readinessContextBuilder,
    ): JsonResponse {
        $company = $this->resolveCompany($currentCompany);
        $this->assertProductBelongsToCompany($company, $product);

        $passport = $this->resolvePassport($product);

        try {
            $validated = $request->validated();

            $updated = $action->handle(
                $this->actor($request),
                $company,
                $product,
                $passport,
                $section,
                (int) $validated['expected_revision'],
            );

            $draft = $updated->currentDraftVersion;
            $readinessContext = $readinessContextBuilder->build($company, $product);
            $readinessResult = $readinessEvaluator->evaluate($readinessContext);

            return response()->json([
                'data' => [
                    'section' => $section,
                    'passport_uuid' => $updated->getAttribute('uuid'),
                    'draft_version_uuid' => $draft?->getAttribute('uuid'),
                    'draft_revision' => $draft?->getAttribute('draft_revision'),
                    'saved_at' => now()->toISOString(),
                    'payload' => $draft?->getAttribute('payload'),
                    'readiness' => [
                        'score' => $readinessResult->score,
                        'status' => $readinessResult->status->value,
                        'blockers' => $readinessResult->counts->blockers,
                        'warnings' => $readinessResult->counts->warnings,
                        'recommendations' => $readinessResult->counts->recommendations,
                    ],
                ],
            ]);
        } catch (ConflictHttpException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }
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
