<?php

namespace App\Services\Catalog\Taxonomy;

use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\CategoryTemplateAttributeRequirement;
use App\Enums\Catalog\CategoryTemplateStatus;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Category;
use App\Models\Catalog\CategoryTemplate;
use App\Models\Catalog\CategoryTemplateAttribute;
use App\Models\Catalog\CategoryTemplateDppDefault;
use App\Models\Catalog\Product;
use App\Models\User;
use App\Services\Passports\DppSchemaRegistry;
use App\Services\Passports\Readiness\ReadinessProfileRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CategoryTemplateService
{
    public function __construct(
        private readonly ReadinessProfileRepository $profiles,
        private readonly DppSchemaRegistry $dppSchema,
    ) {}

    public function createDraft(
        Category $category,
        User $actor,
        string $name,
        ?string $profileCode = null,
        ?int $profileVersion = null,
    ): CategoryTemplate {
        return DB::transaction(function () use ($category, $actor, $name, $profileCode, $profileVersion): CategoryTemplate {
            $category = Category::query()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();
            $profile = $profileCode === null
                ? $this->profiles->active()
                : $this->profiles->for($profileCode, $profileVersion ?? 0);
            $version = (int) CategoryTemplate::query()
                ->forCompany((string) $category->company_id)
                ->where('category_id', $category->getKey())
                ->lockForUpdate()
                ->max('version') + 1;

            $template = new CategoryTemplate;
            $template->forceFill([
                'company_id' => $category->company_id,
                'category_id' => $category->getKey(),
                'version' => $version,
                'name' => trim($name),
                'status' => CategoryTemplateStatus::Draft,
                'active_slot' => null,
                'readiness_profile' => $profile->code,
                'readiness_profile_version' => $profile->version,
                'readiness_rule_set_fingerprint' => $profile->fingerprint,
                'created_by' => $actor->getKey(),
            ])->save();

            return $template->refresh();
        });
    }

    public function assignAttribute(
        CategoryTemplate $template,
        AttributeDefinition $definition,
        CategoryTemplateAttributeRequirement $requirement,
        int $sortOrder = 0,
    ): CategoryTemplateAttribute {
        $this->assertDraft($template);
        $this->assertSameCompany($template, $definition);

        if ($definition->status !== AttributeDefinitionStatus::Active) {
            throw ValidationException::withMessages([
                'attribute_definition_id' => 'Only active attributes can be assigned to a new category template.',
            ]);
        }

        $assignment = CategoryTemplateAttribute::query()
            ->where('company_id', $template->company_id)
            ->where('category_template_id', $template->getKey())
            ->where('attribute_definition_id', $definition->getKey())
            ->first() ?? new CategoryTemplateAttribute;
        $assignment->forceFill([
            'company_id' => $template->company_id,
            'category_template_id' => $template->getKey(),
            'attribute_definition_id' => $definition->getKey(),
            'requirement' => $requirement,
            'sort_order' => max(0, $sortOrder),
        ])->save();

        return $assignment->refresh();
    }

    /** @param array<string, mixed> $defaultData */
    public function setDppDefault(CategoryTemplate $template, string $sectionKey, array $defaultData): CategoryTemplateDppDefault
    {
        $this->assertDraft($template);
        $section = $this->dppSchema->sections()[$sectionKey] ?? null;

        if ($section === null) {
            throw ValidationException::withMessages(['section_key' => 'Unknown DPP section.']);
        }

        $allowedFields = array_map(static fn ($field): string => $field->key, $section->fields);
        $unknownFields = array_values(array_diff(array_keys($defaultData), $allowedFields));

        if ($unknownFields !== []) {
            throw ValidationException::withMessages([
                'default_data' => 'Unknown DPP fields: '.implode(', ', $unknownFields).'.',
            ]);
        }

        $default = CategoryTemplateDppDefault::query()
            ->where('company_id', $template->company_id)
            ->where('category_template_id', $template->getKey())
            ->where('section_key', $sectionKey)
            ->first() ?? new CategoryTemplateDppDefault;
        $default->forceFill([
            'company_id' => $template->company_id,
            'category_template_id' => $template->getKey(),
            'section_key' => $sectionKey,
            'default_data' => $defaultData,
        ])->save();

        return $default->refresh();
    }

    public function activate(CategoryTemplate $template, User $actor): CategoryTemplate
    {
        return DB::transaction(function () use ($template, $actor): CategoryTemplate {
            $template = CategoryTemplate::query()->whereKey($template->getKey())->lockForUpdate()->firstOrFail();
            $this->assertDraft($template);
            $category = Category::query()->whereKey($template->category_id)->lockForUpdate()->firstOrFail();
            $profile = $this->profiles->for($template->readiness_profile, $template->readiness_profile_version);

            if (! hash_equals($profile->fingerprint, $template->readiness_rule_set_fingerprint)) {
                throw ValidationException::withMessages([
                    'readiness_profile' => 'The assigned readiness profile fingerprint no longer matches its registered version.',
                ]);
            }

            $invalidAttribute = $template->attributes()
                ->whereHas('definition', fn ($query) => $query->where('status', '!=', AttributeDefinitionStatus::Active->value))
                ->exists();

            if ($invalidAttribute) {
                throw ValidationException::withMessages([
                    'attributes' => 'Deprecated or archived attributes cannot be activated in a new category template.',
                ]);
            }

            CategoryTemplate::query()
                ->forCompany((string) $template->company_id)
                ->where('category_id', $template->category_id)
                ->where('status', CategoryTemplateStatus::Active->value)
                ->lockForUpdate()
                ->get()
                ->each(function (CategoryTemplate $active): void {
                    $active->forceFill([
                        'status' => CategoryTemplateStatus::Retired,
                        'active_slot' => null,
                    ])->save();
                });

            $template->forceFill([
                'status' => CategoryTemplateStatus::Active,
                'active_slot' => true,
                'activated_by' => $actor->getKey(),
                'activated_at' => now(),
            ])->save();

            $category->forceFill(['active_template_id' => $template->getKey()])->save();

            Product::query()
                ->forCompany((string) $template->company_id)
                ->where('primary_category_id', $category->getKey())
                ->update([
                    'taxonomy_needs_review' => true,
                    'taxonomy_reviewed_at' => null,
                ]);

            return $template->refresh()->load(['attributes.definition', 'dppDefaults']);
        });
    }

    public function acknowledgeProduct(Product $product): Product
    {
        return DB::transaction(function () use ($product): Product {
            $product = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $category = Category::query()
                ->whereKey($product->primary_category_id)
                ->lockForUpdate()
                ->first();

            if ($category?->active_template_id === null) {
                throw ValidationException::withMessages(['category' => 'The primary category has no active template.']);
            }

            $product->forceFill([
                'taxonomy_template_id' => $category->active_template_id,
                'taxonomy_needs_review' => false,
                'taxonomy_reviewed_at' => now(),
            ])->save();

            return $product->refresh();
        });
    }

    public function setProductProfileOverride(Product $product, ?string $code, ?int $version): Product
    {
        if ($code === null && $version === null) {
            $product->forceFill([
                'readiness_profile_override' => null,
                'readiness_profile_version_override' => null,
                'readiness_rule_set_fingerprint_override' => null,
            ])->save();

            return $product->refresh();
        }

        if ($code === null || $version === null) {
            throw ValidationException::withMessages([
                'readiness_profile' => 'Both readiness profile code and version are required for an override.',
            ]);
        }

        $profile = $this->profiles->for($code, $version);
        $product->forceFill([
            'readiness_profile_override' => $profile->code,
            'readiness_profile_version_override' => $profile->version,
            'readiness_rule_set_fingerprint_override' => $profile->fingerprint,
        ])->save();

        return $product->refresh();
    }

    private function assertDraft(CategoryTemplate $template): void
    {
        if ($template->status !== CategoryTemplateStatus::Draft) {
            throw ValidationException::withMessages(['template' => 'Only draft category templates can be changed.']);
        }
    }

    private function assertSameCompany(CategoryTemplate $template, AttributeDefinition $definition): void
    {
        if ((int) $template->company_id !== (int) $definition->company_id) {
            throw ValidationException::withMessages(['attribute_definition_id' => 'The attribute belongs to another company.']);
        }
    }
}
