<?php

namespace App\Services\Passports\Public;

use App\Data\Passports\Public\PublicPassportDocument;
use App\Data\Passports\Public\PublicPassportMedia;
use App\Data\Passports\Public\PublicPassportViewModel;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\ProductPassportStatus;
use App\Models\Passports\ProductPassport;
use App\Services\Observability\RequestTelemetry;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PublicPassportResolver
{
    public function resolve(string $publicId, ?string $requestedLocale = null): PublicPassportViewModel
    {
        $started = hrtime(true);
        try {
            return $this->resolvePublished($publicId, $requestedLocale);
        } finally {
            app(RequestTelemetry::class)->recordPublicResolver((hrtime(true) - $started) / 1_000_000);
        }
    }

    private function resolvePublished(string $publicId, ?string $requestedLocale): PublicPassportViewModel
    {
        $passport = ProductPassport::query()
            ->with('currentPublishedVersion')
            ->where('public_id', $publicId)
            ->first();

        if ($passport === null) {
            throw new NotFoundHttpException('Passport not found.');
        }

        if ($passport->status !== ProductPassportStatus::Published) {
            throw new NotFoundHttpException('Passport is not published.');
        }

        $version = $passport->currentPublishedVersion;

        if ($version === null) {
            throw new NotFoundHttpException('No published version found.');
        }

        return $this->resolvePayload(
            passport: $passport,
            payload: $version->payload,
            requestedLocale: $requestedLocale,
            versionNumber: $version->version_number ?? 0,
            publishedAt: $version->published_at?->toIso8601ZuluString() ?? '',
            snapshotChecksum: $version->content_checksum ?? '',
        );
    }

    /**
     * Build the public representation from the current mutable draft without
     * making it publicly addressable or creating publication assets.
     *
     * @param  array<string, mixed>  $payload
     */
    public function resolvePreview(
        ProductPassport $passport,
        array $payload,
        ?string $requestedLocale = null,
    ): PublicPassportViewModel {
        $passport->loadMissing('product');

        return $this->resolvePayload(
            passport: $passport,
            payload: $payload,
            requestedLocale: $requestedLocale,
            versionNumber: 0,
            publishedAt: '',
            snapshotChecksum: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            preview: true,
        );
    }

    /** @param  array<string, mixed>  $payload */
    private function resolvePayload(
        ProductPassport $passport,
        array $payload,
        ?string $requestedLocale,
        int $versionNumber,
        string $publishedAt,
        string $snapshotChecksum,
        bool $preview = false,
    ): PublicPassportViewModel {

        $catalogContext = $payload['_catalog_context'] ?? [];
        $product = $catalogContext['product'] ?? [];
        $defaultVariant = $catalogContext['default_variant'] ?? null;
        $enabledSections = $payload['enabled_sections'] ?? [];
        $data = $payload['data'] ?? [];
        $translations = $payload['translations'] ?? [];

        // Language configuration is part of the immutable snapshot. Only
        // legacy snapshots published before language snapshotting fall back
        // to the passport row.
        $languages = $payload['languages'] ?? null;
        $defaultLanguage = is_array($languages) && is_string($languages['default'] ?? null)
            ? $languages['default']
            : ($passport->default_language ?? (string) config('passports.default_language', 'sv'));
        $enabledLocales = is_array($languages) && is_array($languages['enabled'] ?? null)
            ? array_values(array_filter($languages['enabled'], fn (mixed $locale): bool => is_string($locale) && $locale !== ''))
            : array_values($passport->enabled_languages ?? [$defaultLanguage]);

        if ($enabledLocales === []) {
            $enabledLocales = [$defaultLanguage];
        }

        $effectiveLocale = $this->resolveEffectiveLocale($requestedLocale, $defaultLanguage, $enabledLocales);
        $isFallback = $requestedLocale !== null && $effectiveLocale !== $requestedLocale;

        $sectionData = $this->buildSectionData($enabledSections, $data, $translations, $defaultLanguage, $effectiveLocale);
        $sectionLabels = $this->buildSectionLabels($enabledSections);
        $media = $this->buildMedia($catalogContext['media'] ?? []);
        $documents = $this->buildDocuments($catalogContext['documents'] ?? []);

        // The published snapshot has two historical representations of the
        // manufacturer. The DPP section is authoritative when present; the
        // catalog snapshot is the deterministic fallback for legacy versions.
        $manufacturerData = $sectionData[DppSectionKey::ManufacturerAndOperator->value] ?? [];
        $manufacturerDisplayName = $this->firstNonBlank(
            $manufacturerData['manufacturer_display_name'] ?? null,
            $product['manufacturer'] ?? null,
        );

        if (in_array(DppSectionKey::ManufacturerAndOperator->value, $enabledSections, true)
            && $manufacturerDisplayName !== null) {
            $sectionData[DppSectionKey::ManufacturerAndOperator->value]['manufacturer_display_name'] = $manufacturerDisplayName;
        }
        $product['manufacturer'] = $manufacturerDisplayName;

        $productName = $product['name'] ?? '';
        $pageTitle = $productName !== ''
            ? "{$productName} — Product Passport"
            : 'Product Passport';
        $metaDescription = $this->buildMetaDescription($sectionData, $product);

        $canonicalUrl = $preview
            ? route('catalog.products.passport.preview', ['product' => $passport->product->uuid])
            : url("p/{$passport->public_id}");
        $localizedUrl = $canonicalUrl.($effectiveLocale !== $defaultLanguage ? "?lang={$effectiveLocale}" : '');
        $ogImageUrl = null;

        if ($media !== [] && ! $preview) {
            $ogImageUrl = url("p/{$passport->public_id}/media/{$media[0]->mediaUuid}");
        }

        $jsonLd = $this->buildJsonLd(
            $productName,
            $product,
            $defaultVariant,
            $localizedUrl,
            $ogImageUrl,
        );

        $countryOfOrigin = null;
        if (in_array(DppSectionKey::OriginAndTraceability->value, $enabledSections, true)) {
            $countryOfOrigin = $data[DppSectionKey::OriginAndTraceability->value]['country_of_origin'] ?? null;
        }

        return new PublicPassportViewModel(
            passportPublicId: $passport->public_id,
            versionNumber: $versionNumber,
            publishedAt: $publishedAt,
            defaultLanguage: $defaultLanguage,
            snapshotChecksum: $snapshotChecksum,
            productName: $productName,
            productBrand: $product['brand'] ?? null,
            productManufacturer: $product['manufacturer'] ?? null,
            productCategory: $product['primary_category_name'] ?? null,
            defaultVariantSku: $defaultVariant['sku'] ?? null,
            defaultVariantGtin: $defaultVariant['gtin'] ?? null,
            defaultVariantMpn: $defaultVariant['mpn'] ?? null,
            enabledSections: $enabledSections,
            sectionData: $sectionData,
            sectionLabels: $sectionLabels,
            media: $media,
            documents: $documents,
            pageTitle: $pageTitle,
            metaDescription: $metaDescription,
            canonicalUrl: $localizedUrl,
            ogImageUrl: $ogImageUrl,
            jsonLd: $jsonLd,
            countryOfOrigin: $countryOfOrigin,
            manufacturerDisplayName: $manufacturerDisplayName,
            requestedLocale: $effectiveLocale,
            enabledLocales: $enabledLocales,
            isFallback: $isFallback,
        );
    }

    private function firstNonBlank(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** @param  string[]  $enabledLocales */
    private function resolveEffectiveLocale(?string $requested, string $default, array $enabledLocales): string
    {
        if ($requested !== null && in_array($requested, $enabledLocales, true)) {
            return $requested;
        }

        return $default;
    }

    /**
     * @param  string[]  $enabledSections
     * @param  array<string, mixed>  $data
     * @param  array<string, array<string, mixed>>  $translations
     * @return array<string, array<string, mixed>>
     */
    private function buildSectionData(
        array $enabledSections,
        array $data,
        array $translations,
        string $defaultLanguage,
        string $requestedLocale,
    ): array {
        $sectionData = [];
        $defaultTranslations = $translations[$defaultLanguage] ?? [];
        $requestedTranslations = $translations[$requestedLocale] ?? [];

        foreach ($enabledSections as $sectionKey) {
            $shared = $data[$sectionKey] ?? [];
            $defaultSection = $defaultTranslations[$sectionKey] ?? [];
            $requestedSection = $requestedTranslations[$sectionKey] ?? [];

            // Start with shared (non-translatable) data
            $merged = $shared;

            // Layer default locale translations
            foreach ($defaultSection as $key => $value) {
                $merged[$key] = $value;
            }

            // Layer requested locale translations (overrides default)
            foreach ($requestedSection as $key => $value) {
                $merged[$key] = $value;
            }

            $merged = $this->sanitizePublicSection($merged);
            $merged = $this->filterPublicSectionFields($sectionKey, $merged);

            $sectionData[$sectionKey] = $merged;
        }

        return $sectionData;
    }

    /**
     * Public passports must be an explicit contract. Unknown editor fields
     * are intentionally omitted instead of being rendered by a generic
     * key/value fallback.
     *
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function filterPublicSectionFields(string $sectionKey, array $section): array
    {
        $allowed = [
            'identity' => ['public_name', 'public_description'],
            'manufacturer_and_operator' => [
                'manufacturer_display_name', 'manufacturer_country', 'manufacturer_email',
                'manufacturer_website', 'responsible_operator_display_name',
                'responsible_operator_country', 'responsible_operator_email',
                'responsible_operator_website', 'responsible_operator_role',
                'responsible_operator_address', 'responsible_operator_phone',
                'responsible_operator_registration_id', 'responsible_operator_source',
                'contact_notes',
            ],
            'origin_and_traceability' => [
                'country_of_origin', 'manufacturing_countries', 'production_date',
                'batch_identification_instructions', 'traceability_notes',
            ],
            'materials_and_composition' => ['materials', 'composition_notes'],
            'safety' => [
                'warnings', 'hazards', 'personal_protective_equipment',
                'storage_instructions', 'emergency_instructions', 'age_restrictions',
            ],
            'usage_and_care' => [
                'usage_instructions', 'usage_steps', 'usage_warnings',
                'care_instructions', 'care_steps', 'care_warnings',
                'maintenance_instructions', 'storage_recommendations',
            ],
            'repair_and_spare_parts' => [
                'repairable', 'repairability_declaration', 'repair_skill_level',
                'required_tools', 'estimated_repair_time_minutes',
                'spare_parts_available', 'spare_parts_url', 'spare_parts',
                'repair_instructions', 'disassembly_instructions', 'spare_parts_notes',
                'service_information',
            ],
            'recycling_and_disposal' => [
                'recycling_instructions', 'disposal_instructions', 'take_back_program',
                'take_back_program_available', 'take_back_program_url',
                'take_back_program_scope', 'disassembly_guidance', 'sorting_guidance',
                'hazard_notes', 'waste_material_codes', 'recycling_codes',
            ],
            'environmental_information' => [
                'carbon_footprint_kg_co2e', 'recycled_content_percentage',
                'recycled_content_calculation_method', 'recycled_content_source',
                'expected_lifetime_years', 'energy_consumption_kwh',
                'environmental_metrics', 'environmental_claims',
                'environmental_claim_records', 'environmental_notes',
            ],
            'certifications_and_documents' => [
                'certification_notes', 'compliance_summary', 'compliance_metadata',
            ],
            'support_and_contact' => [
                'support_email', 'support_phone', 'support_url', 'support_channels',
                'warranty_available', 'warranty_duration', 'warranty_duration_unit',
                'warranty_url', 'warranty_summary', 'warranty_conditions',
                'warranty_exclusions', 'warranty_claim_instructions', 'support_notes',
            ],
        ];

        if (! array_key_exists($sectionKey, $allowed)) {
            return [];
        }

        return array_intersect_key($section, array_flip($allowed[$sectionKey]));
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function sanitizePublicSection(array $section): array
    {
        foreach ($section as $key => $value) {
            if (is_array($value)) {
                $section[$key] = $this->sanitizePublicArray($value);

                continue;
            }

            if ((str_ends_with($key, '_url') || str_ends_with($key, '_website'))
                && is_string($value)
                && $this->isInternalUrl($value)) {
                $section[$key] = null;
            }
        }

        return $section;
    }

    /**
     * @param  array<mixed>  $items
     * @return array<mixed>
     */
    private function sanitizePublicArray(array $items): array
    {
        foreach ($items as $key => $value) {
            if (is_array($value)) {
                $items[$key] = $this->sanitizePublicArray($value);

                continue;
            }

            if (is_string($key) && str_ends_with($key, '_url') && is_string($value) && $this->isInternalUrl($value)) {
                $items[$key] = null;
            }

            if (is_string($key) && str_ends_with($key, '_website') && is_string($value) && $this->isInternalUrl($value)) {
                $items[$key] = null;
            }
        }

        return $items;
    }

    private function isInternalUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);

        if (! is_string($host) || ! is_string($path) || ! is_string($appHost)) {
            return false;
        }

        return strcasecmp($host, $appHost) === 0
            && (str_starts_with($path, '/catalog/') || str_starts_with($path, '/settings/'));
    }

    /**
     * @param  string[]  $enabledSections
     * @return array<string, string>
     */
    private function buildSectionLabels(array $enabledSections): array
    {
        $labels = [];

        foreach ($enabledSections as $sectionKey) {
            $enum = DppSectionKey::tryFrom($sectionKey);
            $labels[$sectionKey] = $enum?->label() ?? $sectionKey;
        }

        return $labels;
    }

    /**
     * @param  array<int, array<string, mixed>>  $mediaItems
     * @return PublicPassportMedia[]
     */
    private function buildMedia(array $mediaItems): array
    {
        $media = [];

        foreach ($mediaItems as $item) {
            $media[] = new PublicPassportMedia(
                mediaUuid: $item['asset_uuid'] ?? $item['uuid'],
                originalFilename: $item['original_filename'] ?? '',
                mimeType: $item['mime_type'] ?? 'application/octet-stream',
                altText: $item['alt_text'] ?? null,
                caption: $item['caption'] ?? null,
                width: $item['width'] ?? null,
                height: $item['height'] ?? null,
                sortOrder: $item['sort_order'] ?? 0,
            );
        }

        usort($media, fn (PublicPassportMedia $a, PublicPassportMedia $b) => $a->sortOrder <=> $b->sortOrder);

        return $media;
    }

    /**
     * @param  array<int, array<string, mixed>>  $documentItems
     * @return PublicPassportDocument[]
     */
    private function buildDocuments(array $documentItems): array
    {
        $documents = [];

        foreach ($documentItems as $item) {
            $visibility = $item['visibility'] ?? '';

            if ($visibility !== ProductDocumentVisibility::PassportPublic->value) {
                continue;
            }

            $sizeBytes = (int) ($item['size_bytes'] ?? 0);

            $documents[] = new PublicPassportDocument(
                assetUuid: $item['asset_uuid'] ?? $item['version_uuid'] ?? '',
                documentUuid: $item['document_uuid'] ?? '',
                title: $item['title'] ?? '',
                documentType: $item['document_type'] ?? '',
                language: $item['language'] ?? '',
                issuerName: $item['issuer_name'] ?? null,
                issueDate: $item['issue_date'] ?? null,
                expiresAt: $item['expires_at'] ?? null,
                fileExtension: $item['file_extension'] ?? '',
                mimeType: $item['mime_type'] ?? 'application/octet-stream',
                sizeBytes: $sizeBytes,
                formattedSize: $this->formatBytes($sizeBytes),
                displayOrder: (int) ($item['display_order'] ?? 0),
            );
        }

        usort($documents, fn (PublicPassportDocument $a, PublicPassportDocument $b) => $a->displayOrder <=> $b->displayOrder);

        return $documents;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / (1024 ** $i), 1).' '.$units[$i];
    }

    /**
     * @param  array<string, array<string, mixed>>  $sectionData
     * @param  array<string, mixed>  $product
     */
    private function buildMetaDescription(array $sectionData, array $product): string
    {
        $description = $sectionData['identity']['public_description']
            ?? $product['name']
            ?? '';

        if (is_string($description) && $description !== '') {
            return mb_substr(strip_tags((string) $description), 0, 160);
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  array<string, mixed>|null  $defaultVariant
     */
    private function buildJsonLd(
        string $productName,
        array $product,
        ?array $defaultVariant,
        string $canonicalUrl,
        ?string $ogImageUrl,
    ): string {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $productName,
            'url' => $canonicalUrl,
        ];

        if (! empty($product['brand'])) {
            $data['brand'] = ['@type' => 'Brand', 'name' => $product['brand']];
        }

        if (! empty($product['manufacturer'])) {
            $data['manufacturer'] = ['@type' => 'Organization', 'name' => $product['manufacturer']];
        }

        if ($defaultVariant !== null) {
            if (! empty($defaultVariant['sku'])) {
                $data['sku'] = $defaultVariant['sku'];
            }

            if (! empty($defaultVariant['gtin'])) {
                $data['gtin13'] = $defaultVariant['gtin'];
            }

            if (! empty($defaultVariant['mpn'])) {
                $data['mpn'] = $defaultVariant['mpn'];
            }
        }

        if ($ogImageUrl !== null) {
            $data['image'] = $ogImageUrl;
        }

        if (! empty($product['primary_category_name'])) {
            $data['category'] = $product['primary_category_name'];
        }

        return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
