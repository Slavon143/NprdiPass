<?php

namespace App\Actions\Catalog\Taxonomy;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\Catalog\CategoryTemplateAttributeRequirement;
use App\Enums\Catalog\CategoryTemplateStatus;
use App\Enums\CompanyPermission;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\Category;
use App\Models\Catalog\CategoryTemplate;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\Taxonomy\CategoryTemplateService;
use App\Services\Catalog\Taxonomy\ProductTaxonomyDiagnosticsService;
use App\Services\Catalog\Taxonomy\TaxonomyLifecycleService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaxonomyGovernanceAction
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly AuditLogger $audit,
        private readonly CategoryTemplateService $templates,
        private readonly TaxonomyLifecycleService $lifecycle,
        private readonly ProductTaxonomyDiagnosticsService $diagnostics,
    ) {}

    /** @param array{name: string, readiness_profile?: string|null, readiness_profile_version?: int|null} $data */
    public function createTemplate(User $actor, Company $company, Category $category, array $data): CategoryTemplate
    {
        $this->authorizeCategory($actor, $company, $category);
        $template = $this->templates->createDraft(
            $category,
            $actor,
            $data['name'],
            $data['readiness_profile'] ?? null,
            $data['readiness_profile_version'] ?? null,
        );
        $this->audit->logTenant($company, AuditEvent::CatalogCategoryTemplateCreated, $actor, $template, [
            'category_uuid' => $category->uuid,
            'template_uuid' => $template->uuid,
            'template_version' => $template->version,
            'readiness_profile' => $template->readiness_profile,
            'readiness_profile_version' => $template->readiness_profile_version,
        ]);

        return $template;
    }

    /**
     * @param  list<array{attribute_uuid: string, requirement: string, sort_order?: int}>  $attributes
     * @param  array<string, array<string, mixed>>  $dppDefaults
     */
    public function configureTemplate(
        User $actor,
        Company $company,
        Category $category,
        CategoryTemplate $template,
        string $name,
        array $attributes,
        array $dppDefaults,
    ): CategoryTemplate {
        $this->authorizeTemplate($actor, $company, $category, $template);

        if ($template->status !== CategoryTemplateStatus::Draft) {
            throw ValidationException::withMessages(['template' => 'Only draft category templates can be configured.']);
        }

        return DB::transaction(function () use ($actor, $company, $category, $template, $name, $attributes, $dppDefaults): CategoryTemplate {
            $template = CategoryTemplate::query()->whereKey($template->getKey())->lockForUpdate()->firstOrFail();
            $template->forceFill(['name' => trim($name)])->save();
            $template->attributes()->delete();
            $template->dppDefaults()->delete();

            $seen = [];
            foreach ($attributes as $index => $item) {
                if (isset($seen[$item['attribute_uuid']])) {
                    throw ValidationException::withMessages(['attributes' => 'Each attribute can be assigned only once.']);
                }

                $seen[$item['attribute_uuid']] = true;
                $definition = AttributeDefinition::query()
                    ->forCompany($company)
                    ->where('uuid', $item['attribute_uuid'])
                    ->first();
                $requirement = CategoryTemplateAttributeRequirement::tryFrom($item['requirement']);

                if (! $definition instanceof AttributeDefinition || $requirement === null) {
                    throw ValidationException::withMessages(['attributes' => 'One or more attribute assignments are invalid.']);
                }

                $this->templates->assignAttribute(
                    $template,
                    $definition,
                    $requirement,
                    $item['sort_order'] ?? (($index + 1) * 10),
                );
            }

            foreach ($dppDefaults as $sectionKey => $defaultData) {
                if ($defaultData !== []) {
                    $this->templates->setDppDefault($template, $sectionKey, $defaultData);
                }
            }

            $this->audit->logTenant($company, AuditEvent::CatalogCategoryTemplateUpdated, $actor, $template, [
                'category_uuid' => $category->uuid,
                'template_uuid' => $template->uuid,
                'attribute_count' => count($attributes),
                'dpp_default_section_count' => count(array_filter($dppDefaults)),
            ]);

            return $template->refresh()->load(['attributes.definition', 'dppDefaults']);
        });
    }

    public function activateTemplate(
        User $actor,
        Company $company,
        Category $category,
        CategoryTemplate $template,
    ): CategoryTemplate {
        $this->authorizeTemplate($actor, $company, $category, $template);
        $template = $this->templates->activate($template, $actor);
        $this->audit->logTenant($company, AuditEvent::CatalogCategoryTemplateActivated, $actor, $template, [
            'category_uuid' => $category->uuid,
            'template_uuid' => $template->uuid,
            'template_version' => $template->version,
        ]);

        return $template;
    }

    public function acknowledgeProduct(User $actor, Company $company, Product $product): Product
    {
        $this->assertTenant($company, $product);
        $this->authorizer->authorize($actor, $company, CompanyPermission::CatalogUpdate);

        if ($this->diagnostics->diagnose($product)['missing_required'] !== []) {
            throw ValidationException::withMessages([
                'taxonomy' => 'Complete all required category attributes before acknowledging the taxonomy review.',
            ]);
        }

        $product = $this->templates->acknowledgeProduct($product);
        $this->audit->logTenant($company, AuditEvent::CatalogProductTaxonomyAcknowledged, $actor, $product, [
            'product_uuid' => $product->uuid,
            'taxonomy_template_id' => $product->taxonomy_template_id,
        ]);

        return $product;
    }

    public function setProductProfileOverride(
        User $actor,
        Company $company,
        Product $product,
        ?string $code,
        ?int $version,
    ): Product {
        $this->assertTenant($company, $product);
        $this->authorizer->authorize($actor, $company, CompanyPermission::CatalogManageCategories);
        $product = $this->templates->setProductProfileOverride($product, $code, $version);
        $this->audit->logTenant($company, AuditEvent::CatalogProductReadinessProfileOverridden, $actor, $product, [
            'product_uuid' => $product->uuid,
            'readiness_profile' => $product->readiness_profile_override,
            'readiness_profile_version' => $product->readiness_profile_version_override,
        ]);

        return $product;
    }

    public function deprecateDefinition(
        User $actor,
        Company $company,
        AttributeDefinition $definition,
        AttributeDefinition $replacement,
    ): AttributeDefinition {
        $this->assertTenant($company, $definition);
        $this->assertTenant($company, $replacement);
        $this->authorizer->authorize($actor, $company, CompanyPermission::CatalogManageAttributes);
        $definition = $this->lifecycle->deprecateDefinition($definition, $replacement);
        $this->audit->logTenant($company, AuditEvent::CatalogAttributeDeprecated, $actor, $definition, [
            'attribute_uuid' => $definition->uuid,
            'replacement_uuid' => $replacement->uuid,
        ]);

        return $definition;
    }

    public function deprecateOption(
        User $actor,
        Company $company,
        AttributeDefinition $definition,
        AttributeOption $option,
        AttributeOption $replacement,
    ): AttributeOption {
        $this->assertTenant($company, $definition);
        $this->assertTenant($company, $option);
        $this->authorizer->authorize($actor, $company, CompanyPermission::CatalogManageAttributes);
        $option = $this->lifecycle->deprecateOption($option, $replacement);
        $this->audit->logTenant($company, AuditEvent::CatalogAttributeOptionDeprecated, $actor, $definition, [
            'attribute_uuid' => $definition->uuid,
            'option_id' => $option->getKey(),
            'replacement_option_id' => $replacement->getKey(),
        ]);

        return $option;
    }

    public function approveOption(
        User $actor,
        Company $company,
        AttributeDefinition $definition,
        AttributeOption $option,
    ): AttributeOption {
        $this->assertTenant($company, $definition);
        $this->assertTenant($company, $option);
        $this->authorizer->authorize($actor, $company, CompanyPermission::CatalogManageAttributes);
        $option = $this->lifecycle->approveOption($option, $actor);
        $this->audit->logTenant($company, AuditEvent::CatalogAttributeOptionApproved, $actor, $definition, [
            'attribute_uuid' => $definition->uuid,
            'option_id' => $option->getKey(),
        ]);

        return $option;
    }

    private function authorizeCategory(User $actor, Company $company, Category $category): void
    {
        $this->assertTenant($company, $category);
        $this->authorizer->authorize($actor, $company, CompanyPermission::CatalogManageCategories);
    }

    private function authorizeTemplate(
        User $actor,
        Company $company,
        Category $category,
        CategoryTemplate $template,
    ): void {
        $this->authorizeCategory($actor, $company, $category);

        if ((int) $template->company_id !== (int) $company->getKey()
            || (int) $template->category_id !== (int) $category->getKey()) {
            throw ValidationException::withMessages(['template' => 'The category template is unavailable.']);
        }
    }

    private function assertTenant(Company $company, Model $model): void
    {
        $companyId = $model->getAttribute('company_id');

        if (! is_numeric($companyId) || (int) $companyId !== (int) $company->getKey()) {
            throw ValidationException::withMessages(['resource' => 'The resource is unavailable.']);
        }
    }
}
