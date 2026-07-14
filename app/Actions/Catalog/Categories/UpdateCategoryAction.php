<?php

namespace App\Actions\Catalog\Categories;

use App\Enums\AuditEvent;
use App\Exceptions\Catalog\CategoryOperationException;
use App\Models\Catalog\Category;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class UpdateCategoryAction extends CategoryAction
{
    /** @param array<string, mixed> $data */
    public function execute(User $actor, Company $company, Category $category, array $data): Category
    {
        $company = $this->authorize($actor, $company);
        $this->assertTenant($company, $category);

        try {
            return DB::transaction(function () use ($actor, $company, $category, $data): Category {
                $company = $this->authorize($actor, $company);
                $categories = $this->lockCompanyCategories($company);
                $category = $this->freshFrom($category, $categories);
                $values = [
                    'name' => array_key_exists('name', $data)
                        ? $this->normalizedName($data)
                        : (string) $category->getAttribute('name'),
                    'slug' => array_key_exists('slug', $data)
                        ? $this->normalizedSlug($data)
                        : (string) $category->getAttribute('slug'),
                    'description' => $this->normalizedDescription($data, $category->getAttribute('description')),
                    'sort_order' => $this->normalizedSortOrder($data, (int) $category->getAttribute('sort_order')),
                ];

                if ($categories->contains(function (Category $candidate) use ($category, $values): bool {
                    return ! $candidate->is($category)
                        && $candidate->getRawOriginal('slug_normalized') === $values['slug'];
                })) {
                    throw CategoryOperationException::slugConflict();
                }

                $category->forceFill([
                    ...$values,
                    'slug_normalized' => $values['slug'],
                ]);
                $changedFields = [];

                foreach (['name', 'slug', 'description', 'sort_order'] as $field) {
                    if ($category->isDirty($field)) {
                        $changedFields[] = $field;
                    }
                }

                if ($changedFields === []) {
                    return $category;
                }

                $category->forceFill(['updated_by' => $actor->getKey()])->save();
                $this->auditLogger->logTenant(
                    $company,
                    AuditEvent::CatalogCategoryUpdated,
                    $actor,
                    $category,
                    [
                        'category_uuid' => $category->getAttribute('uuid'),
                        'changed_fields' => $changedFields,
                    ],
                );

                return $category->refresh();
            });
        } catch (QueryException $exception) {
            if ($this->isDuplicateKey($exception)) {
                throw CategoryOperationException::slugConflict($exception);
            }

            throw $exception;
        }
    }
}
