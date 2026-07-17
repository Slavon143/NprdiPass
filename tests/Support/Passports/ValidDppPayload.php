<?php

namespace Tests\Support\Passports;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Enums\Passports\DppSectionKey;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\User;

/**
 * Builds schema-valid DPP payloads using actual R2.4 field keys and section rules.
 * Uses the actual UpdateProductPassportSectionAction to ensure validation compatibility.
 */
class ValidDppPayload
{
    /**
     * Build a ready passport: name, description, manufacturer, safety, recycling basics.
     * Only passes translatable fields to translatable sections (the action rejects non-translatable fields).
     */
    public static function buildReadyPassport(
        User $actor,
        Company $company,
        Product $product,
    ): ProductPassport {
        $action = app(CreateProductPassportDraftAction::class);
        $passport = $action->handle($actor, $company, $product);

        $sectionAction = app(UpdateProductPassportSectionAction::class);
        $lang = $passport->default_language;

        // Identity — translatable section (2 fields)
        $rev = 1;
        $passport = $sectionAction->handle($actor, $company, $product, $passport,
            DppSectionKey::Identity->value,
            ['public_name' => 'Test Product Name', 'public_description' => 'Test product description.'],
            $rev++
        );

        // Manufacturer — translatable section (ONLY translatable fields)
        $passport = $sectionAction->handle($actor, $company, $product, $passport,
            DppSectionKey::ManufacturerAndOperator->value,
            ['manufacturer_display_name' => 'Test Manufacturer Inc.', 'responsible_operator_display_name' => 'Test Operator'],
            $rev++
        );

        // Safety — translatable section (all 6 fields are translatable)
        $passport = $sectionAction->handle($actor, $company, $product, $passport,
            DppSectionKey::Safety->value,
            ['warnings' => ['Warning text'], 'storage_instructions' => 'Store safely.'],
            $rev++
        );

        // Recycling — translatable section (add recycling instructions)
        $passport = $sectionAction->handle($actor, $company, $product, $passport,
            DppSectionKey::RecyclingAndDisposal->value,
            ['recycling_instructions' => 'Recycle properly.', 'disposal_instructions' => 'Dispose safely.'],
            $rev++
        );

        return $passport->fresh(['currentDraftVersion']);
    }

    /**
     * Build a minimal passport — just identity name (translatable).
     */
    public static function buildMinimalPassport(
        User $actor,
        Company $company,
        Product $product,
    ): ProductPassport {
        $action = app(CreateProductPassportDraftAction::class);
        $passport = $action->handle($actor, $company, $product);

        $sectionAction = app(UpdateProductPassportSectionAction::class);

        $passport = $sectionAction->handle($actor, $company, $product, $passport,
            DppSectionKey::Identity->value,
            ['public_name' => 'Minimal Product'],
            1
        );

        return $passport->fresh(['currentDraftVersion']);
    }

    /**
     * Update a section with translatable fields only (safe for all sections).
     *
     * @param  array<string, mixed>  $fields  — translatable fields keyed by field name
     */
    public static function updateTranslatableFields(
        ProductPassport $passport,
        User $actor,
        Company $company,
        Product $product,
        DppSectionKey $sectionKey,
        array $fields,
        int $expectedRevision,
    ): ProductPassport {
        return app(UpdateProductPassportSectionAction::class)->handle(
            $actor, $company, $product, $passport,
            $sectionKey->value,
            $fields,
            $expectedRevision,
        );
    }
}
