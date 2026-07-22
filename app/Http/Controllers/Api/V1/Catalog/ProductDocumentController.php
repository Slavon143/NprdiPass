<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\Catalog\Documents\AddProductDocumentVersionAction;
use App\Actions\Catalog\Documents\ApproveProductDocumentVersionAction;
use App\Actions\Catalog\Documents\ArchiveProductDocumentAction;
use App\Actions\Catalog\Documents\CancelProductDocumentReviewAction;
use App\Actions\Catalog\Documents\CreateProductDocumentAction;
use App\Actions\Catalog\Documents\RejectProductDocumentVersionAction;
use App\Actions\Catalog\Documents\RestoreProductDocumentAction;
use App\Actions\Catalog\Documents\SubmitProductDocumentVersionForReviewAction;
use App\Enums\Documents\ProductDocumentType;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\V1\Catalog\Concerns\AuthorizesCatalogApi;
use App\Http\Controllers\Api\V1\Catalog\Concerns\ResolvesCatalogApiResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\ProductDocumentResource;
use App\Http\Resources\Catalog\ProductDocumentSummaryResource;
use App\Http\Resources\Catalog\ProductDocumentVersionResource;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Company;
use App\Models\User;
use App\Queries\Catalog\ProductDocumentQuery;
use App\Services\Catalog\Documents\DocumentFileStorage;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductDocumentController extends Controller
{
    use AuthorizesCatalogApi;
    use ResolvesCatalogApiResources;

    public function index(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ProductDocumentQuery $query,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $this->authorizeDocumentViewAny($company);

        $perPage = min((int) ($request->query('per_page', config('documents.per_page', 25))), (int) config('documents.max_per_page', 100));

        $filters = $request->only([
            'status', 'document_type', 'language', 'visibility',
            'expired', 'expiring', 'title_search', 'issuer_search',
            'sort', 'direction',
        ]);
        $filters['product_uuid'] = $product->uuid;

        $paginator = $query->build($company, null, $filters)->paginate($perPage);

        return $response->paginated(
            ProductDocumentSummaryResource::collection($paginator)->resolve($request),
            $paginator,
        );
    }

    public function show(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ApiResponse $response,
        string $product,
        string $document,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);

        $document = $this->resolveDocument($company, $document, $product->getKey());
        $document->load(['currentVersion', 'versions', 'creator']);
        $document->loadCount('versions');

        $this->authorizeDocumentView($document);

        return $response->success(
            (new ProductDocumentResource($document))->resolve($request),
        );
    }

    public function versions(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ApiResponse $response,
        string $product,
        string $document,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $document = $this->resolveDocument($company, $document, $product->getKey());

        $this->authorizeDocumentView($document);

        $versions = ProductDocumentVersion::query()
            ->forCompany($company)
            ->where('document_id', $document->getKey())
            ->orderBy('version_number', 'desc')
            ->get();

        return $response->success(
            ProductDocumentVersionResource::collection($versions)->resolve($request),
        );
    }

    public function store(
        Request $request,
        TokenCurrentCompany $currentCompany,
        CreateProductDocumentAction $action,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $this->authorizeDocumentCreate($company);

        $allowedTypes = array_map(
            fn (ProductDocumentType $t) => $t->value,
            ProductDocumentType::cases(),
        );

        $rules = [
            'document_type' => ['required', 'string', 'in:'.implode(',', $allowedTypes)],
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'language' => ['required', 'string', 'max:10', 'regex:/^[a-z]{2,3}(-[A-Z]{2,3})?$/'],
            'visibility' => ['required', 'string', 'in:internal,passport_public'],
            'issuer_name' => ['nullable', 'string', 'max:500'],
            'certificate_number' => ['nullable', 'string', 'max:120'],
            'issuing_body' => ['nullable', 'string', 'max:255'],
            'declaration_identifier' => ['nullable', 'string', 'max:120'],
            'evidence_type' => ['nullable', 'string', 'max:120'],
            'topic_code' => ['nullable', 'string', 'max:120'],
            'standard_reference' => ['nullable', 'string', 'max:255'],
            'applicable_market' => ['nullable', 'string', 'max:120'],
            'reference_url' => ['nullable', 'url', 'max:1000'],
            'issue_date' => ['nullable', 'date', 'before_or_equal:today'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'metadata' => ['nullable', 'array'],
            'file' => ['required', 'file', 'max:'.(int) config('documents.max_size_kb', 25600)],
        ];

        $validated = $request->validate($rules);

        $document = $action->execute(
            $this->actor($request),
            $company,
            $product,
            $validated,
            $request->file('file'),
        );

        return $response->created(
            (new ProductDocumentResource($document->load(['currentVersion', 'creator'])->loadCount('versions')))->resolve($request),
        );
    }

    public function addVersion(
        Request $request,
        TokenCurrentCompany $currentCompany,
        AddProductDocumentVersionAction $action,
        ApiResponse $response,
        string $product,
        string $document,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $document = $this->resolveDocument($company, $document, $product->getKey());

        $this->authorizeDocumentManage($document);

        $allowedTypes = array_map(
            fn (ProductDocumentType $t) => $t->value,
            ProductDocumentType::cases(),
        );

        $validated = $request->validate([
            'document_type' => ['required', 'string', 'in:'.implode(',', $allowedTypes)],
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'language' => ['required', 'string', 'max:10', 'regex:/^[a-z]{2,3}(-[A-Z]{2,3})?$/'],
            'visibility' => ['required', 'string', 'in:internal,passport_public'],
            'issuer_name' => ['nullable', 'string', 'max:500'],
            'certificate_number' => ['nullable', 'string', 'max:120'],
            'issuing_body' => ['nullable', 'string', 'max:255'],
            'declaration_identifier' => ['nullable', 'string', 'max:120'],
            'evidence_type' => ['nullable', 'string', 'max:120'],
            'topic_code' => ['nullable', 'string', 'max:120'],
            'standard_reference' => ['nullable', 'string', 'max:255'],
            'applicable_market' => ['nullable', 'string', 'max:120'],
            'reference_url' => ['nullable', 'url', 'max:1000'],
            'issue_date' => ['nullable', 'date', 'before_or_equal:today'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'metadata' => ['nullable', 'array'],
            'file' => ['required', 'file', 'max:'.(int) config('documents.max_size_kb', 25600)],
        ]);

        $version = $action->execute(
            $this->actor($request),
            $company,
            $document,
            $validated,
            $request->file('file'),
        );

        return $response->created(
            (new ProductDocumentVersionResource($version))->resolve($request),
        );
    }

    public function versionContent(
        Request $request,
        TokenCurrentCompany $currentCompany,
        DocumentFileStorage $storage,
        ApiResponse $response,
        string $product,
        string $document,
        string $version,
    ): StreamedResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $documentModel = $this->resolveDocument($company, $document, $product->getKey());

        $this->authorizeDocumentDownload($documentModel);

        $versionModel = ProductDocumentVersion::query()
            ->forCompany($company)
            ->where('document_id', $documentModel->getKey())
            ->where('uuid', $version)
            ->firstOrFail();

        $storage->assertReadable($versionModel->storage_key);
        $storage->verifyChecksum($versionModel->storage_key, $versionModel->checksum_sha256);

        $filename = preg_replace('/[^a-zA-Z0-9_\-\s]/u', '', $versionModel->title) ?: 'document';
        $filename = mb_substr(trim((string) $filename), 0, 200).'.pdf';

        return $storage->disk()->download(
            $versionModel->storage_key,
            $filename,
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }

    public function submitReview(
        Request $request,
        TokenCurrentCompany $currentCompany,
        SubmitProductDocumentVersionForReviewAction $action,
        ApiResponse $response,
        string $product,
        string $document,
        string $version,
    ): JsonResponse {
        [$company, $documentModel, $versionModel] = $this->resolveDocumentVersionScope($currentCompany, $product, $document, $version);
        $this->authorize('submitReview', $documentModel);

        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        $versionModel = $action->execute($this->actor($request), $company, $documentModel, $versionModel, $validated['comment'] ?? null);

        return $response->success((new ProductDocumentVersionResource($versionModel))->resolve($request));
    }

    public function cancelReview(
        Request $request,
        TokenCurrentCompany $currentCompany,
        CancelProductDocumentReviewAction $action,
        ApiResponse $response,
        string $product,
        string $document,
        string $version,
    ): JsonResponse {
        [$company, $documentModel, $versionModel] = $this->resolveDocumentVersionScope($currentCompany, $product, $document, $version);
        $this->authorize('submitReview', $documentModel);

        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        $versionModel = $action->execute($this->actor($request), $company, $documentModel, $versionModel, $validated['comment'] ?? null);

        return $response->success((new ProductDocumentVersionResource($versionModel))->resolve($request));
    }

    public function approveVersion(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ApproveProductDocumentVersionAction $action,
        ApiResponse $response,
        string $product,
        string $document,
        string $version,
    ): JsonResponse {
        [$company, $documentModel, $versionModel] = $this->resolveDocumentVersionScope($currentCompany, $product, $document, $version);
        $this->authorize('approve', $documentModel);

        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        $versionModel = $action->execute($this->actor($request), $company, $documentModel, $versionModel, $validated['comment'] ?? null);

        return $response->success((new ProductDocumentVersionResource($versionModel))->resolve($request));
    }

    public function rejectVersion(
        Request $request,
        TokenCurrentCompany $currentCompany,
        RejectProductDocumentVersionAction $action,
        ApiResponse $response,
        string $product,
        string $document,
        string $version,
    ): JsonResponse {
        [$company, $documentModel, $versionModel] = $this->resolveDocumentVersionScope($currentCompany, $product, $document, $version);
        $this->authorize('reject', $documentModel);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $versionModel = $action->execute($this->actor($request), $company, $documentModel, $versionModel, $validated['reason']);

        return $response->success((new ProductDocumentVersionResource($versionModel))->resolve($request));
    }

    public function archive(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ArchiveProductDocumentAction $action,
        ApiResponse $response,
        string $product,
        string $document,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $document = $this->resolveDocument($company, $document, $product->getKey());

        $this->authorizeDocumentManage($document);

        $document = $action->execute($this->actor($request), $company, $document);

        return $response->success(
            (new ProductDocumentResource($document->load(['currentVersion'])->loadCount('versions')))->resolve($request),
        );
    }

    public function restore(
        Request $request,
        TokenCurrentCompany $currentCompany,
        RestoreProductDocumentAction $action,
        ApiResponse $response,
        string $product,
        string $document,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $document = $this->resolveDocument($company, $document, $product->getKey());

        $this->authorizeDocumentManage($document);

        $document = $action->execute($this->actor($request), $company, $document);

        return $response->success(
            (new ProductDocumentResource($document->load(['currentVersion'])->loadCount('versions')))->resolve($request),
        );
    }

    private function resolveDocument(Company $company, string $uuid, ?int $productId = null): ProductDocument
    {
        $query = ProductDocument::query()
            ->forCompany($company)
            ->where('uuid', $uuid);

        if ($productId !== null) {
            $query->where('product_id', $productId);
        }

        $document = $query->first();

        return $document instanceof ProductDocument ? $document : throw new ModelNotFoundException;
    }

    /**
     * @return array{0: Company, 1: ProductDocument, 2: ProductDocumentVersion}
     */
    private function resolveDocumentVersionScope(TokenCurrentCompany $currentCompany, string $product, string $document, string $version): array
    {
        $company = $this->currentCompany($currentCompany);
        $productModel = $this->resolveProduct($company, $product);
        $documentModel = $this->resolveDocument($company, $document, $productModel->getKey());
        $versionModel = ProductDocumentVersion::query()
            ->forCompany($company)
            ->where('document_id', $documentModel->getKey())
            ->where('uuid', $version)
            ->firstOrFail();

        return [$company, $documentModel, $versionModel];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
