<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\Passports\ArchiveProductPassport;
use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\PublishProductPassport;
use App\Actions\Passports\ResetProductPassportSectionAction;
use App\Actions\Passports\RestoreProductPassport;
use App\Actions\Passports\SyncProductPassportDocumentsAction;
use App\Actions\Passports\UnpublishProductPassport;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Actions\Passports\UpdateProductPassportSettingsAction;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\V1\Catalog\Concerns\ResolvesCatalogApiResources;
use App\Http\Controllers\Controller;
use App\Http\Requests\Passports\ArchivePassportRequest;
use App\Http\Requests\Passports\CreatePassportRequest;
use App\Http\Requests\Passports\PublishPassportRequest;
use App\Http\Requests\Passports\ResetPassportSectionRequest;
use App\Http\Requests\Passports\RestorePassportRequest;
use App\Http\Requests\Passports\SyncPassportDocumentsRequest;
use App\Http\Requests\Passports\UnpublishPassportRequest;
use App\Http\Requests\Passports\UpdatePassportSectionRequest;
use App\Http\Requests\Passports\UpdatePassportSettingsRequest;
use App\Http\Resources\Passports\DppSchemaResource;
use App\Http\Resources\Passports\ProductPassportResource;
use App\Http\Resources\Passports\ProductPassportVersionResource;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Queries\Passports\ProductPassportEditorQuery;
use App\Services\Passports\DppCatalogContextProvider;
use App\Services\Passports\DppSchemaRegistry;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProductPassportController extends Controller
{
    use ResolvesCatalogApiResources;

    public function show(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ProductPassportEditorQuery $editorQuery,
        DppCatalogContextProvider $contextProvider,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $resolvedProduct = $this->resolveProduct($company, $product);

        $passport = $resolvedProduct->passport;

        if (! $passport instanceof ProductPassport) {
            throw new NotFoundHttpException;
        }

        $editorQuery->editorData($passport);
        $context = $contextProvider->context($resolvedProduct, $company);

        return $response->success(
            (new ProductPassportResource($passport))->additional(['catalog_context' => $context])
        );
    }

    public function store(
        CreatePassportRequest $request,
        TokenCurrentCompany $currentCompany,
        CreateProductPassportDraftAction $action,
        ProductPassportEditorQuery $editorQuery,
        DppCatalogContextProvider $contextProvider,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $resolvedProduct = $this->resolveProduct($company, $product);

        $passport = $action->handle($this->actor($request), $company, $resolvedProduct);

        $editorQuery->editorData($passport);
        $context = $contextProvider->context($resolvedProduct, $company);

        return $response->created(
            (new ProductPassportResource($passport))->additional(['catalog_context' => $context])
        );
    }

    public function schema(
        Request $request,
        TokenCurrentCompany $currentCompany,
        DppSchemaRegistry $schemaRegistry,
        ApiResponse $response,
    ): JsonResponse {
        $this->currentCompany($currentCompany);

        return $response->success(
            new DppSchemaResource($schemaRegistry->sections())
        );
    }

    public function updateSection(
        UpdatePassportSectionRequest $request,
        TokenCurrentCompany $currentCompany,
        UpdateProductPassportSectionAction $action,
        ProductPassportEditorQuery $editorQuery,
        DppCatalogContextProvider $contextProvider,
        ApiResponse $response,
        string $product,
        string $section,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $resolvedProduct = $this->resolveProduct($company, $product);

        $passport = $resolvedProduct->passport;

        if (! $passport instanceof ProductPassport) {
            throw new NotFoundHttpException;
        }

        $validated = $request->validated();

        $passport = $action->handle(
            $this->actor($request),
            $company,
            $resolvedProduct,
            $passport,
            $section,
            $validated['section_payload'],
            $validated['expected_revision'],
        );

        $editorQuery->editorData($passport);
        $context = $contextProvider->context($resolvedProduct, $company);

        return $response->success(
            (new ProductPassportResource($passport))->additional(['catalog_context' => $context])
        );
    }

    public function updateSettings(
        UpdatePassportSettingsRequest $request,
        TokenCurrentCompany $currentCompany,
        UpdateProductPassportSettingsAction $action,
        ProductPassportEditorQuery $editorQuery,
        DppCatalogContextProvider $contextProvider,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $resolvedProduct = $this->resolveProduct($company, $product);

        $passport = $resolvedProduct->passport;

        if (! $passport instanceof ProductPassport) {
            throw new NotFoundHttpException;
        }

        $validated = $request->validated();

        $passport = $action->handle(
            $this->actor($request),
            $company,
            $resolvedProduct,
            $passport,
            $validated['settings'],
            $validated['expected_revision'],
        );

        $editorQuery->editorData($passport);
        $context = $contextProvider->context($resolvedProduct, $company);

        return $response->success(
            (new ProductPassportResource($passport))->additional(['catalog_context' => $context])
        );
    }

    public function syncDocuments(
        SyncPassportDocumentsRequest $request,
        TokenCurrentCompany $currentCompany,
        SyncProductPassportDocumentsAction $action,
        ProductPassportEditorQuery $editorQuery,
        DppCatalogContextProvider $contextProvider,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $resolvedProduct = $this->resolveProduct($company, $product);

        $passport = $resolvedProduct->passport;

        if (! $passport instanceof ProductPassport) {
            throw new NotFoundHttpException;
        }

        $validated = $request->validated();

        $passport = $action->handle(
            $this->actor($request),
            $company,
            $resolvedProduct,
            $passport,
            $validated['document_references'],
            $validated['expected_revision'],
        );

        $editorQuery->editorData($passport);
        $context = $contextProvider->context($resolvedProduct, $company);

        return $response->success(
            (new ProductPassportResource($passport))->additional(['catalog_context' => $context])
        );
    }

    public function resetSection(
        ResetPassportSectionRequest $request,
        TokenCurrentCompany $currentCompany,
        ResetProductPassportSectionAction $action,
        ProductPassportEditorQuery $editorQuery,
        DppCatalogContextProvider $contextProvider,
        ApiResponse $response,
        string $product,
        string $section,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $resolvedProduct = $this->resolveProduct($company, $product);

        $passport = $resolvedProduct->passport;

        if (! $passport instanceof ProductPassport) {
            throw new NotFoundHttpException;
        }

        $validated = $request->validated();

        $passport = $action->handle(
            $this->actor($request),
            $company,
            $resolvedProduct,
            $passport,
            $section,
            $validated['expected_revision'],
        );

        $editorQuery->editorData($passport);
        $context = $contextProvider->context($resolvedProduct, $company);

        return $response->success(
            (new ProductPassportResource($passport))->additional(['catalog_context' => $context])
        );
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    public function publish(
        PublishPassportRequest $request,
        TokenCurrentCompany $currentCompany,
        PublishProductPassport $action,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $resolvedProduct = $this->resolveProduct($company, $product);

        $passport = $resolvedProduct->passport;

        if (! $passport instanceof ProductPassport) {
            throw new NotFoundHttpException;
        }

        $validated = $request->validated();

        try {
            $result = $action->handle(
                $this->actor($request),
                $company,
                $resolvedProduct,
                $passport,
                (int) $validated['expected_revision'],
                (bool) ($validated['acknowledge_warnings'] ?? false),
            );

            return $response->success(
                (new ProductPassportResource($result->passport))->additional([
                    'published_version' => $result->publishedVersion,
                ])
            );
        } catch (ConflictHttpException $e) {
            return $response->error('conflict', $e->getMessage(), 409);
        }
    }

    public function unpublish(
        UnpublishPassportRequest $request,
        TokenCurrentCompany $currentCompany,
        UnpublishProductPassport $action,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $resolvedProduct = $this->resolveProduct($company, $product);

        $passport = $resolvedProduct->passport;

        if (! $passport instanceof ProductPassport) {
            throw new NotFoundHttpException;
        }

        try {
            $passport = $action->handle(
                $this->actor($request),
                $company,
                $resolvedProduct,
                $passport,
            );

            return $response->success(new ProductPassportResource($passport));
        } catch (ConflictHttpException $e) {
            return $response->error('conflict', $e->getMessage(), 409);
        }
    }

    public function archive(
        ArchivePassportRequest $request,
        TokenCurrentCompany $currentCompany,
        ArchiveProductPassport $action,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $resolvedProduct = $this->resolveProduct($company, $product);

        $passport = $resolvedProduct->passport;

        if (! $passport instanceof ProductPassport) {
            throw new NotFoundHttpException;
        }

        try {
            $passport = $action->handle(
                $this->actor($request),
                $company,
                $resolvedProduct,
                $passport,
            );

            return $response->success(new ProductPassportResource($passport));
        } catch (ConflictHttpException $e) {
            return $response->error('conflict', $e->getMessage(), 409);
        }
    }

    public function restore(
        RestorePassportRequest $request,
        TokenCurrentCompany $currentCompany,
        RestoreProductPassport $action,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $resolvedProduct = $this->resolveProduct($company, $product);

        $passport = $resolvedProduct->passport;

        if (! $passport instanceof ProductPassport) {
            throw new NotFoundHttpException;
        }

        try {
            $passport = $action->handle(
                $this->actor($request),
                $company,
                $resolvedProduct,
                $passport,
            );

            return $response->success(new ProductPassportResource($passport));
        } catch (ConflictHttpException $e) {
            return $response->error('conflict', $e->getMessage(), 409);
        }
    }

    public function versions(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $resolvedProduct = $this->resolveProduct($company, $product);

        $passport = $resolvedProduct->passport;

        if (! $passport instanceof ProductPassport) {
            throw new NotFoundHttpException;
        }

        $versions = $passport->versions()
            ->where('status', '!=', ProductPassportVersionStatus::Draft->value)
            ->orderByDesc('version_number')
            ->get();

        return $response->success(
            ProductPassportVersionResource::collection($versions)
        );
    }

    public function versionDetail(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ApiResponse $response,
        string $product,
        string $version,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $resolvedProduct = $this->resolveProduct($company, $product);

        $passport = $resolvedProduct->passport;

        if (! $passport instanceof ProductPassport) {
            throw new NotFoundHttpException;
        }

        $versionModel = ProductPassportVersion::query()
            ->where('uuid', $version)
            ->where('passport_id', $passport->getKey())
            ->first();

        if (! $versionModel instanceof ProductPassportVersion) {
            throw new NotFoundHttpException;
        }

        return $response->success(new ProductPassportVersionResource($versionModel));
    }
}
