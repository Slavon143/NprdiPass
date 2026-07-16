<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\Documents\AddProductDocumentVersionAction;
use App\Actions\Catalog\Documents\ArchiveProductDocumentAction;
use App\Actions\Catalog\Documents\CreateProductDocumentAction;
use App\Actions\Catalog\Documents\RestoreProductDocumentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\Documents\AddDocumentVersionRequest;
use App\Http\Requests\Catalog\Documents\StoreDocumentRequest;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Company;
use App\Models\User;
use App\Queries\Catalog\ProductDocumentQuery;
use App\Services\Catalog\Documents\DocumentFileStorage;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductDocumentController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany, ProductDocumentQuery $query, string $product): View
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $this->authorize('view', $product);

        $perPage = min((int) ($request->query('per_page', config('documents.per_page', 25))), (int) config('documents.max_per_page', 100));

        $filters = $request->only(['status', 'document_type', 'language', 'visibility', 'expired', 'expiring', 'title_search', 'issuer_search', 'sort', 'direction']);
        $filters['product_uuid'] = $product->uuid;

        $documents = $query->build($company, null, $filters)->paginate($perPage);

        return view('catalog.documents.index', [
            'company' => $company,
            'product' => $product,
            'documents' => $documents,
            'filters' => $filters,
            'canManage' => $request->user()?->can('create', [ProductDocument::class, $company]) === true,
        ]);
    }

    public function create(Request $request, CurrentCompany $currentCompany, string $product): View
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $this->authorize('create', [ProductDocument::class, $company]);

        return view('catalog.documents.create', [
            'company' => $company,
            'product' => $product,
        ]);
    }

    public function store(StoreDocumentRequest $request, CurrentCompany $currentCompany, CreateProductDocumentAction $action, string $product): RedirectResponse
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $validated = $request->validated();

        $document = $action->execute(
            $this->actor($request),
            $company,
            $product,
            $validated,
            $request->file('file'),
        );

        return redirect()
            ->route('catalog.products.documents.show', ['product' => $product->uuid, 'document' => $document->uuid])
            ->with('success', 'Document created.');
    }

    public function show(Request $request, CurrentCompany $currentCompany, string $product, string $document): View
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);

        $document = ProductDocument::query()
            ->forCompany($company)
            ->where('uuid', $document)
            ->with(['currentVersion', 'versions', 'creator'])
            ->firstOrFail();

        $this->authorize('view', $document);

        return view('catalog.documents.show', [
            'company' => $company,
            'product' => $product,
            'document' => $document,
            'canManage' => $request->user()?->can('addVersion', $document) === true,
        ]);
    }

    public function downloadVersion(Request $request, CurrentCompany $currentCompany, DocumentFileStorage $storage, string $product, string $document, string $version): StreamedResponse
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);

        $document = ProductDocument::query()
            ->forCompany($company)
            ->where('uuid', $document)
            ->firstOrFail();

        $this->authorize('download', $document);

        $version = ProductDocumentVersion::query()
            ->forCompany($company)
            ->where('document_id', $document->getKey())
            ->where('uuid', $version)
            ->firstOrFail();

        $storage->assertReadable($version->storage_key);
        $storage->verifyChecksum($version->storage_key, $version->checksum_sha256);

        $filename = $this->sanitizeFilename($version->title).'.pdf';

        return $storage->disk()->download(
            $version->storage_key,
            $filename,
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }

    public function addVersion(AddDocumentVersionRequest $request, CurrentCompany $currentCompany, AddProductDocumentVersionAction $action, string $product, string $document): RedirectResponse
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);

        $document = ProductDocument::query()
            ->forCompany($company)
            ->where('uuid', $document)
            ->firstOrFail();

        $validated = $request->validated();

        $action->execute(
            $this->actor($request),
            $company,
            $document,
            $validated,
            $request->file('file'),
        );

        return redirect()
            ->route('catalog.products.documents.show', ['product' => $product->uuid, 'document' => $document->uuid])
            ->with('success', 'New version added.');
    }

    public function archive(Request $request, CurrentCompany $currentCompany, ArchiveProductDocumentAction $action, string $product, string $document): RedirectResponse
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);

        $document = ProductDocument::query()
            ->forCompany($company)
            ->where('uuid', $document)
            ->firstOrFail();

        $action->execute($this->actor($request), $company, $document);

        return redirect()
            ->route('catalog.products.documents.show', ['product' => $product->uuid, 'document' => $document->uuid])
            ->with('success', 'Document archived.');
    }

    public function restore(Request $request, CurrentCompany $currentCompany, RestoreProductDocumentAction $action, string $product, string $document): RedirectResponse
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);

        $document = ProductDocument::query()
            ->forCompany($company)
            ->where('uuid', $document)
            ->firstOrFail();

        $action->execute($this->actor($request), $company, $document);

        return redirect()
            ->route('catalog.products.documents.show', ['product' => $product->uuid, 'document' => $document->uuid])
            ->with('success', 'Document restored.');
    }

    private function resolveProduct(Company $company, string $uuid): Product
    {
        return Product::query()->forCompany($company)->where('uuid', $uuid)->firstOrFail();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function sanitizeFilename(string $title): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-\s]/u', '', $title);
        if ($safe === null || trim($safe) === '') {
            return 'document';
        }

        return mb_substr(trim($safe), 0, 200);
    }
}
