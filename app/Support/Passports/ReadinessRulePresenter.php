<?php

namespace App\Support\Passports;

use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use App\Models\Catalog\Product;
use Illuminate\Support\Str;

class ReadinessRulePresenter
{
    /** @var array<int, bool> */
    private array $passportExistsCache = [];

    /** @var array<string, array<string, mixed>> */
    private array $readinessLinesCache = [];

    public function title(ReadinessRuleResult $rule): string
    {
        $translated = $this->translation("readiness.{$rule->code}.title");
        if ($translated !== null) {
            return $translated;
        }

        $translated = $this->translation($rule->titleKey);
        if ($translated !== null) {
            return $translated;
        }

        return $this->fallbackTitle($rule);
    }

    public function message(ReadinessRuleResult $rule): string
    {
        $statusSuffix = $rule->status->value;

        foreach ([
            "readiness.{$rule->code}.{$statusSuffix}",
            "readiness.{$rule->code}.message",
            $rule->messageKey,
        ] as $key) {
            $translated = $this->translation($key);
            if ($translated !== null) {
                return $translated;
            }
        }

        return match ($rule->status) {
            ReadinessRuleStatus::Passed => __('This check passed.'),
            ReadinessRuleStatus::NotApplicable => __('This check is not applicable for this product.'),
            ReadinessRuleStatus::Failed => $this->fallbackFailedMessage($rule),
        };
    }

    public function actionUrl(Product $product, ReadinessRuleResult $rule): ?string
    {
        if ($rule->status !== ReadinessRuleStatus::Failed) {
            return null;
        }

        if ($rule->navigationTarget !== null) {
            if ($rule->navigationTarget->routeName === 'catalog.products.passport.edit' && ! $this->hasPassport($product)) {
                return route('catalog.products.show', $product->uuid).'#passport';
            }

            return $this->withAnchor(
                route($rule->navigationTarget->routeName, $rule->navigationTarget->routeParameters),
                $rule,
            );
        }

        return $this->fallbackUrl($product, $rule);
    }

    public function actionLabel(ReadinessRuleResult $rule): string
    {
        if ($rule->code === 'certificates.declaration_present') {
            return __('Declaration of Conformity documents');
        }

        if ($rule->navigationTarget !== null) {
            return $this->cleanActionLabel(__($rule->navigationTarget->label));
        }

        if ($rule->section !== null) {
            return __('Edit :section', ['section' => $rule->section->label()]);
        }

        return $this->cleanActionLabel(match ($rule->group) {
            ReadinessRuleGroup::Catalog => __('Product'),
            ReadinessRuleGroup::Media => __('Images'),
            ReadinessRuleGroup::Documents, ReadinessRuleGroup::Certificates => __('Documents'),
            default => __('Passport Editor'),
        });
    }

    public function groupLabel(ReadinessRuleGroup|string $group): string
    {
        $value = $group instanceof ReadinessRuleGroup ? $group->value : $group;

        return match ($value) {
            'catalog' => __('Catalog data'),
            'passport' => __('Passport setup'),
            'identity' => __('Public identity'),
            'manufacturer' => __('Manufacturer & operator'),
            'safety' => __('Safety'),
            'recycling' => __('Recycling & disposal'),
            'media' => __('Images'),
            'documents' => __('Documents'),
            'certificates' => __('Certificates'),
            'environmental' => __('Environmental information'),
            'support' => __('Support & warranty'),
            'technical' => __('Technical validation'),
            default => Str::headline((string) $value),
        };
    }

    public function groupDescription(ReadinessRuleGroup|string $group): string
    {
        $value = $group instanceof ReadinessRuleGroup ? $group->value : $group;

        return match ($value) {
            'catalog' => __('Core product, category, variant, and attribute data from the catalog.'),
            'passport' => __('Passport draft, languages, enabled sections, schema, and payload checks.'),
            'identity' => __('Public-facing product name and description shown in the passport.'),
            'manufacturer' => __('Manufacturer, responsible operator, country, and contact details.'),
            'safety' => __('Safety, storage, and emergency information.'),
            'recycling' => __('Recycling instructions, disposal codes, and take-back information.'),
            'media' => __('Product and variant images used by the passport.'),
            'documents' => __('Product documents, public visibility, versions, and file metadata.'),
            'certificates' => __('Certificates, Declaration of Conformity, issuer, and expiry checks.'),
            'environmental' => __('Environmental claims and metrics.'),
            'support' => __('Support contacts, warranty, repair, and spare parts information.'),
            'technical' => __('Internal consistency checks for the generated passport payload.'),
            default => __('Readiness checks for this area.'),
        };
    }

    public function statusLabel(ReadinessRuleResult $rule): string
    {
        return match ($rule->status) {
            ReadinessRuleStatus::Passed => __('Passed'),
            ReadinessRuleStatus::NotApplicable => __('Not applicable'),
            ReadinessRuleStatus::Failed => match ($rule->severity) {
                ReadinessSeverity::Blocker => __('Blocker'),
                ReadinessSeverity::Warning => __('Warning'),
                ReadinessSeverity::Recommendation => __('Recommendation'),
            },
        };
    }

    public function resultLabel(ReadinessRuleResult $rule): string
    {
        return match ($rule->status) {
            ReadinessRuleStatus::Passed => __('Completed'),
            ReadinessRuleStatus::Failed => __('Missing'),
            ReadinessRuleStatus::NotApplicable => __('Not applicable'),
        };
    }

    public function requirementLabel(ReadinessRuleResult $rule): string
    {
        return match ($rule->severity) {
            ReadinessSeverity::Blocker => __('Required before publication'),
            ReadinessSeverity::Warning => __('Important warning'),
            ReadinessSeverity::Recommendation => __('Recommended improvement'),
        };
    }

    public function statusHelp(ReadinessRuleResult $rule): string
    {
        return match ($rule->status) {
            ReadinessRuleStatus::Passed => __('Complete right now. If the underlying product data changes, this check may change too.'),
            ReadinessRuleStatus::NotApplicable => __('Not required for this product or disabled passport section.'),
            ReadinessRuleStatus::Failed => match ($rule->severity) {
                ReadinessSeverity::Blocker => __('Must be fixed before the passport can be published.'),
                ReadinessSeverity::Warning => __('Does not block activation, but lowers readiness and should be fixed before publishing.'),
                ReadinessSeverity::Recommendation => __('Recommended improvement. It does not block publishing.'),
            },
        };
    }

    public function statusTone(ReadinessRuleResult $rule): string
    {
        return match ($rule->status) {
            ReadinessRuleStatus::Passed => 'bg-green-100 text-green-800 border-green-200',
            ReadinessRuleStatus::NotApplicable => 'bg-slate-100 text-slate-600 border-slate-200',
            ReadinessRuleStatus::Failed => match ($rule->severity) {
                ReadinessSeverity::Blocker => 'bg-red-100 text-red-800 border-red-200',
                ReadinessSeverity::Warning => 'bg-amber-100 text-amber-800 border-amber-200',
                ReadinessSeverity::Recommendation => 'bg-blue-100 text-blue-800 border-blue-200',
            },
        };
    }

    public function cardTone(ReadinessRuleResult $rule): string
    {
        return match ($rule->status) {
            ReadinessRuleStatus::Passed => 'border-green-200 bg-green-50/60',
            ReadinessRuleStatus::NotApplicable => 'border-slate-200 bg-slate-50',
            ReadinessRuleStatus::Failed => match ($rule->severity) {
                ReadinessSeverity::Blocker => 'border-red-200 bg-red-50/80',
                ReadinessSeverity::Warning => 'border-amber-200 bg-amber-50/80',
                ReadinessSeverity::Recommendation => 'border-blue-200 bg-blue-50/80',
            },
        };
    }

    private function cleanActionLabel(string $label): string
    {
        return preg_replace('/^Open\s+/i', '', $label) ?? $label;
    }

    private function fallbackUrl(Product $product, ReadinessRuleResult $rule): string
    {
        return match (true) {
            $rule->code === 'passport.exists' => route('catalog.products.show', $product->uuid).'#passport',
            $rule->code === 'passport.optional_sections.none' => $this->passportEditorUrl($product).'#sectionsContainer',
            $rule->code === 'catalog.product.name.present' => route('catalog.products.edit', $product->uuid).'#name',
            $rule->code === 'catalog.product.brand.present' => route('catalog.products.edit', $product->uuid).'#brand',
            $rule->code === 'catalog.product.manufacturer.present' => route('catalog.products.edit', $product->uuid).'#manufacturer',
            $rule->code === 'catalog.product.category.present' => route('catalog.products.edit', $product->uuid).'#primary_category_uuid',
            $rule->code === 'catalog.product.default_variant.present' => route('catalog.products.variants.index', $product->uuid),
            $rule->code === 'catalog.product.identifier.present' && $product->defaultVariant !== null => route('catalog.products.variants.edit', [$product->uuid, $product->defaultVariant->uuid]).'#sku',
            $rule->code === 'catalog.product.identifier.present' => route('catalog.products.variants.index', $product->uuid),
            $rule->code === 'catalog.product.attributes.present' => route('catalog.products.attributes.edit', $product->uuid).'#required-attributes',
            $rule->group === ReadinessRuleGroup::Catalog => route('catalog.products.show', $product->uuid),
            $rule->group === ReadinessRuleGroup::Media => route('catalog.products.media.index', $product->uuid).'#product-image-management',
            $rule->group === ReadinessRuleGroup::Documents,
            $rule->group === ReadinessRuleGroup::Certificates => route('catalog.products.documents.index', $product->uuid).'#product-documents',
            $rule->section !== null => $this->passportEditorUrl($product, $rule),
            str_starts_with($rule->code, 'passport.') => $this->passportEditorUrl($product),
            default => $this->passportEditorUrl($product),
        };
    }

    private function passportEditorUrl(Product $product, ?ReadinessRuleResult $rule = null): string
    {
        if (! $this->hasPassport($product)) {
            return route('catalog.products.show', $product->uuid).'#passport';
        }

        return route('catalog.products.passport.edit', $product->uuid).($rule === null ? '' : $this->anchor($rule));
    }

    private function hasPassport(Product $product): bool
    {
        $key = (int) $product->getKey();

        if (! array_key_exists($key, $this->passportExistsCache)) {
            $this->passportExistsCache[$key] = $product->relationLoaded('passport')
                ? $product->passport !== null
                : $product->passport()->exists();
        }

        return $this->passportExistsCache[$key];
    }

    private function withAnchor(string $url, ReadinessRuleResult $rule): string
    {
        if ($rule->navigationTarget?->routeName === 'catalog.products.media.index') {
            return $url.'#product-image-management';
        }

        if ($rule->navigationTarget?->routeName === 'catalog.products.documents.index') {
            return $url.'#product-documents';
        }

        if ($rule->navigationTarget?->routeName === 'catalog.products.variants.index') {
            return $url.'#product-variants';
        }

        if ($rule->navigationTarget?->routeName === 'catalog.products.variants.media.index') {
            return $url.'#variant-image-management';
        }

        if ($rule->navigationTarget?->routeName === 'catalog.products.attributes.edit') {
            return $url.'#required-attributes';
        }

        if ($rule->navigationTarget?->routeName === 'catalog.products.edit') {
            return match ($rule->code) {
                'catalog.product.brand.present' => $url.'#brand',
                'catalog.product.category.present' => $url.'#primary_category_uuid',
                'catalog.product.manufacturer.present' => $url.'#manufacturer',
                default => $url,
            };
        }

        if ($rule->navigationTarget?->routeName !== 'catalog.products.passport.edit') {
            return $url;
        }

        return $url.$this->anchor($rule);
    }

    private function anchor(ReadinessRuleResult $rule): string
    {
        if ($rule->section === null) {
            return '';
        }

        if ($rule->field !== null) {
            return '#field-'.$rule->section->value.'-'.$rule->field;
        }

        return '#section-'.$rule->section->value;
    }

    private function translation(string $key): ?string
    {
        $translated = $this->readinessTranslation($key) ?? __($key);

        return $translated === $key ? null : $translated;
    }

    private function readinessTranslation(string $key): ?string
    {
        if (! str_starts_with($key, 'readiness.')) {
            return null;
        }

        $segments = explode('.', Str::after($key, 'readiness.'));

        if (count($segments) < 2) {
            return null;
        }

        $leaf = array_pop($segments);
        $ruleKey = implode('.', $segments);

        foreach ([$this->locale(), $this->fallbackLocale()] as $locale) {
            if ($locale === null) {
                continue;
            }

            $lines = $this->readinessLines($locale);
            $translated = $lines[$ruleKey][$leaf] ?? null;

            if (is_string($translated) && $translated !== '') {
                return $translated;
            }
        }

        return null;
    }

    /**
     * Laravel's dot notation cannot read literal keys like
     * "media.variant_coverage" from lang/en/readiness.php, so readiness rule
     * translations are read directly by the literal rule code.
     *
     * @return array<string, mixed>
     */
    private function readinessLines(string $locale): array
    {
        if (! array_key_exists($locale, $this->readinessLinesCache)) {
            $loaded = trans()->getLoader()->load($locale, 'readiness', '*');
            $this->readinessLinesCache[$locale] = $loaded;
        }

        return $this->readinessLinesCache[$locale];
    }

    private function locale(): ?string
    {
        $locale = app()->getLocale();

        return $locale !== '' ? $locale : null;
    }

    private function fallbackLocale(): ?string
    {
        $locale = config('app.fallback_locale');

        return is_string($locale) && $locale !== '' ? $locale : null;
    }

    private function fallbackTitle(ReadinessRuleResult $rule): string
    {
        $specific = [
            'catalog.product.active' => __('Product status'),
            'catalog.product.exists' => __('Product record'),
            'catalog.product.name.present' => __('Product name'),
            'catalog.product.identifier.present' => __('Product identifier'),
            'catalog.product.brand.present' => __('Product brand'),
            'catalog.product.manufacturer.present' => __('Product manufacturer'),
            'catalog.product.category.present' => __('Product category'),
            'catalog.product.default_variant.present' => __('Default variant'),
            'catalog.product.attributes.present' => __('Product attributes'),
            'passport.exists' => __('Product Passport'),
            'passport.status.editable' => __('Passport status'),
            'passport.current_draft.exists' => __('Current draft'),
            'passport.current_draft.belongs_to_passport' => __('Current draft ownership'),
            'passport.current_draft.status' => __('Draft status'),
            'passport.schema.supported' => __('Passport schema'),
            'passport.payload.valid' => __('Passport data validation'),
            'passport.payload.size' => __('Passport data size'),
            'passport.core_sections.enabled' => __('Core passport sections'),
            'passport.optional_sections.none' => __('Optional passport sections'),
            'passport.default_language.enabled' => __('Default language'),
            'passport.default_language.supported' => __('Default language support'),
            'passport.languages.enabled_unsupported' => __('Enabled languages'),
            'passport.languages.translation_completeness' => __('Required translations'),
            'passport.revision.valid' => __('Draft revision'),
            'certificates.declaration_present' => __('Declaration of Conformity'),
            'certificates.metadata.complete' => __('Certificate metadata'),
            'certificates.not_expired' => __('Certificate expiry'),
            'certificates.expiring_soon' => __('Certificates expiring soon'),
            'certificates.no_expiration' => __('Certificate expiry dates'),
        ];

        if (isset($specific[$rule->code])) {
            return $specific[$rule->code];
        }

        $code = preg_replace('/^(catalog|dpp|passport|documents|media|certificates)\./', '', $rule->code) ?? $rule->code;
        $code = preg_replace('/\.(present|valid|exists|enabled|supported|complete|available)$/', '', $code) ?? $code;
        $code = str_replace(['.', '_'], ' ', $code);

        return Str::headline($code);
    }

    private function fallbackFailedMessage(ReadinessRuleResult $rule): string
    {
        if ($rule->code === 'passport.optional_sections.none') {
            return __('No optional DPP sections are enabled. Enable one if this product needs extra information beyond the required core sections.');
        }

        if ($rule->code === 'certificates.declaration_present') {
            return __('Link a Declaration of Conformity document to the passport before publishing.');
        }

        if ($rule->section !== null && $rule->field !== null) {
            return __('Fill this field in the passport editor.');
        }

        if ($rule->section !== null) {
            return __('Complete this passport section before publishing.');
        }

        return match ($rule->group) {
            ReadinessRuleGroup::Catalog => __('Update the catalog product data before publishing.'),
            ReadinessRuleGroup::Media => __('Add or fix product images before publishing.'),
            ReadinessRuleGroup::Documents, ReadinessRuleGroup::Certificates => __('Add or fix product documents before publishing.'),
            default => __('Complete this item before publishing.'),
        };
    }
}
