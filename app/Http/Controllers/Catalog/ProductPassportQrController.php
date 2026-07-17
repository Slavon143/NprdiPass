<?php

namespace App\Http\Controllers\Catalog;

use App\Data\Passports\Qr\PassportQrViewModel;
use App\Enums\CompanyPermission;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Services\Passports\Qr\PassportQrPayloadFactory;
use App\Services\Passports\Qr\PassportQrRenderer;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProductPassportQrController extends Controller
{
    public function show(
        Product $product,
        Request $request,
        CurrentCompany $currentCompany,
        PassportQrPayloadFactory $payloadFactory,
    ): View {
        $company = $this->resolveCompany($currentCompany);
        $this->assertProductBelongsToCompany($company, $product);

        $passport = $this->resolvePassport($product);

        if (! $request->user()?->can(CompanyPermission::PassportsView->value, [$company])) {
            throw new NotFoundHttpException;
        }

        $publicUrl = $payloadFactory->create($passport->public_id);

        $viewModel = new PassportQrViewModel(
            publicId: $passport->public_id,
            publicUrl: $publicUrl,
            isPublished: $passport->isPublished(),
            hasBeenPublished: $passport->hasPublishedVersion(),
            versionNumber: $passport->currentPublishedVersion?->version_number,
            passportStatus: $passport->status->value,
            productName: $product->name,
            productUuid: $product->uuid,
        );

        $canManage = $request->user()?->can(CompanyPermission::PassportsManage->value, [$company]) === true;

        return view()->make('passports.qr.show', [
            'company' => $company,
            'product' => $product,
            'passport' => $passport,
            'viewModel' => $viewModel,
            'canManage' => $canManage,
        ]);
    }

    public function svg(
        Product $product,
        Request $request,
        CurrentCompany $currentCompany,
        PassportQrRenderer $renderer,
    ): Response {
        $company = $this->resolveCompany($currentCompany);
        $this->assertProductBelongsToCompany($company, $product);

        $passport = $this->resolvePassport($product);

        if (! $request->user()?->can(CompanyPermission::PassportsView->value, [$company])) {
            throw new NotFoundHttpException;
        }

        $svg = $renderer->renderSvg($passport->public_id);
        $etag = $renderer->eTag($passport->public_id, 'svg');
        $safeName = $this->safeFileName($product->name);

        if ($this->isNotModified($request, $etag)) {
            return response('', 304)
                ->header('ETag', $etag);
        }

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => "attachment; filename=\"nordipass-{$safeName}-qr.svg\"",
            'X-Content-Type-Options' => 'nosniff',
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function png(
        Product $product,
        Request $request,
        CurrentCompany $currentCompany,
        PassportQrRenderer $renderer,
    ): Response {
        $company = $this->resolveCompany($currentCompany);
        $this->assertProductBelongsToCompany($company, $product);

        $passport = $this->resolvePassport($product);

        if (! $request->user()?->can(CompanyPermission::PassportsView->value, [$company])) {
            throw new NotFoundHttpException;
        }

        $png = $renderer->renderPng($passport->public_id);
        $etag = $renderer->eTag($passport->public_id, 'png');
        $safeName = $this->safeFileName($product->name);

        if ($this->isNotModified($request, $etag)) {
            return response('', 304)
                ->header('ETag', $etag);
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => "attachment; filename=\"nordipass-{$safeName}-qr.png\"",
            'X-Content-Type-Options' => 'nosniff',
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age=86400',
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

    private function safeFileName(string $name): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9\-_ ]/', '', $name);
        $safe = preg_replace('/\s+/', '-', trim($safe));
        $safe = mb_substr($safe, 0, 100);

        return $safe !== '' ? $safe : 'passport';
    }

    private function isNotModified(Request $request, string $etag): bool
    {
        $ifNoneMatch = $request->header('If-None-Match');

        return $ifNoneMatch !== null && $ifNoneMatch === $etag;
    }
}
