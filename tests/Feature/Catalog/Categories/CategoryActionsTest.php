<?php

use App\Actions\Catalog\Categories\ArchiveCategoryAction;
use App\Actions\Catalog\Categories\CreateCategoryAction;
use App\Actions\Catalog\Categories\MoveCategoryAction;
use App\Actions\Catalog\Categories\ReorderSiblingCategoriesAction;
use App\Actions\Catalog\Categories\RestoreCategoryAction;
use App\Actions\Catalog\Categories\UpdateCategoryAction;
use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Exceptions\Catalog\CategoryOperationException;
use App\Models\AuditLog;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Catalog\CategoryHierarchyService;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @return array{User, Company} */
function r14CategoryContext(CompanyRole $role = CompanyRole::Owner): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->create([
        'user_id' => $actor,
        'company_id' => $company,
        'role' => $role,
    ]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    return [$actor, $company];
}

function r14Category(
    Company $company,
    User $actor,
    string $name,
    ?Category $parent = null,
    array $overrides = [],
): Category {
    $slug = str($name)->slug()->toString();
    $category = new Category;
    $category->forceFill(array_merge([
        'company_id' => $company->id,
        'parent_id' => $parent?->id,
        'depth' => $parent === null ? 0 : $parent->depth + 1,
        'name' => $name,
        'slug' => $slug,
        'slug_normalized' => $slug,
        'description' => null,
        'sort_order' => 0,
        'status' => CategoryStatus::Active,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ], $overrides))->save();

    return $category->refresh();
}

test('owner creates a normalized trusted root and admin creates a child with audit', function () {
    [$owner, $company] = r14CategoryContext();
    $foreign = Company::factory()->create();
    $root = app(CreateCategoryAction::class)->execute($owner, $company, [
        'company_id' => $foreign->id,
        'name' => '  Hem & Trädgård  ',
        'slug' => '',
        'description' => '  Safe description  ',
        'sort_order' => 20,
        'status' => CategoryStatus::Archived->value,
        'depth' => 99,
    ]);

    expect($root->company_id)->toBe($company->id)
        ->and($root->name)->toBe('Hem & Trädgård')
        ->and($root->slug)->toBe('hem-tradgard')
        ->and($root->depth)->toBe(0)
        ->and($root->status)->toBe(CategoryStatus::Active)
        ->and($root->created_by)->toBe($owner->id)
        ->and($root->updated_by)->toBe($owner->id);

    $admin = User::factory()->create();
    CompanyMembership::factory()->admin()->create(['user_id' => $admin, 'company_id' => $company]);
    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);
    $child = app(CreateCategoryAction::class)->execute($admin, $company, [
        'name' => 'Lighting',
        'slug' => 'Lighting',
        'sort_order' => 10,
    ], $root);

    expect($child->parent_id)->toBe($root->id)
        ->and($child->depth)->toBe(1)
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogCategoryCreated->value)->count())->toBe(2);

    $audit = AuditLog::query()->where('subject_id', $child->id)->sole();
    expect($audit->getProperty('category_uuid'))->toBe($child->uuid)
        ->and($audit->getProperty('parent_uuid'))->toBe($root->uuid)
        ->and($audit->getProperty('depth'))->toBe(1);
});

test('create rejects duplicate slug foreign or archived parent and excessive depth without audit', function () {
    [$actor, $company] = r14CategoryContext();
    $root = r14Category($company, $actor, 'Root');
    $archived = r14Category($company, $actor, 'Archived', null, ['status' => CategoryStatus::Archived]);
    $foreign = Company::factory()->create();
    $foreignParent = r14Category($foreign, $actor, 'Foreign');
    $action = app(CreateCategoryAction::class);

    expect(fn () => $action->execute($actor, $company, ['name' => 'Root copy', 'slug' => 'ROOT'], null))
        ->toThrow(CategoryOperationException::class, 'Slug already exists.')
        ->and(fn () => $action->execute($actor, $company, ['name' => 'Child'], $archived))
        ->toThrow(CategoryOperationException::class, 'Archived parent')
        ->and(fn () => $action->execute($actor, $company, ['name' => 'Foreign child'], $foreignParent))
        ->toThrow(CategoryOperationException::class);

    $parent = $root;

    for ($depth = 1; $depth <= CategoryHierarchyService::MAX_DEPTH; $depth++) {
        $parent = r14Category($company, $actor, "Depth {$depth}", $parent);
    }

    expect(fn () => $action->execute($actor, $company, ['name' => 'Too deep'], $parent))
        ->toThrow(CategoryOperationException::class, 'Maximum category depth exceeded.')
        ->and(AuditLog::query()->count())->toBe(0);
});

test('create enforces the documented company category limit', function () {
    [$actor, $company] = r14CategoryContext();
    $now = now();
    $rows = [];

    for ($index = 1; $index <= CategoryHierarchyService::MAX_CATEGORIES_PER_COMPANY; $index++) {
        $rows[] = [
            'uuid' => sprintf('00000000-0000-4000-8000-%012d', $index),
            'company_id' => $company->id,
            'parent_id' => null,
            'depth' => 0,
            'name' => "Category {$index}",
            'slug' => "category-{$index}",
            'slug_normalized' => "category-{$index}",
            'sort_order' => $index,
            'status' => CategoryStatus::Active->value,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('categories')->insert($chunk);
    }

    expect(fn () => app(CreateCategoryAction::class)->execute($actor, $company, ['name' => 'Over limit']))
        ->toThrow(CategoryOperationException::class, 'category limit')
        ->and(Category::query()->forCompany($company)->count())->toBe(CategoryHierarchyService::MAX_CATEGORIES_PER_COMPANY)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('update changes only managed fields with safe audit and no-op has no audit', function () {
    [$actor, $company] = r14CategoryContext();
    $parent = r14Category($company, $actor, 'Parent');
    $category = r14Category($company, $actor, 'Original', $parent);
    $foreign = Company::factory()->create();
    $updated = app(UpdateCategoryAction::class)->execute($actor, $company, $category, [
        'name' => ' Updated ',
        'slug' => 'Ångström Lamps',
        'description' => ' New description ',
        'sort_order' => 40,
        'company_id' => $foreign->id,
        'parent_id' => null,
        'depth' => 5,
        'status' => CategoryStatus::Archived->value,
    ]);

    expect($updated->name)->toBe('Updated')
        ->and($updated->slug)->toBe('angstrom-lamps')
        ->and($updated->parent_id)->toBe($parent->id)
        ->and($updated->depth)->toBe(1)
        ->and($updated->company_id)->toBe($company->id)
        ->and($updated->status)->toBe(CategoryStatus::Active);

    $audit = AuditLog::query()->where('event', AuditEvent::CatalogCategoryUpdated->value)->sole();
    expect($audit->getProperty('changed_fields'))->toEqualCanonicalizing(['name', 'slug', 'description', 'sort_order'])
        ->and($audit->properties->has('description'))->toBeFalse();

    app(UpdateCategoryAction::class)->execute($actor, $company, $updated, [
        'name' => $updated->name,
        'slug' => $updated->slug,
        'description' => $updated->description,
        'sort_order' => $updated->sort_order,
    ]);

    expect(AuditLog::query()->count())->toBe(1);
});

test('update rejects a duplicate slug and rolls back every change', function () {
    [$actor, $company] = r14CategoryContext();
    r14Category($company, $actor, 'Reserved');
    $category = r14Category($company, $actor, 'Editable');

    expect(fn () => app(UpdateCategoryAction::class)->execute($actor, $company, $category, [
        'name' => 'Must roll back',
        'slug' => 'RESERVED',
    ]))->toThrow(CategoryOperationException::class, 'Slug already exists.')
        ->and($category->fresh()?->name)->toBe('Editable')
        ->and($category->fresh()?->slug)->toBe('editable')
        ->and(AuditLog::query()->count())->toBe(0);
});

test('move updates the full subtree depths and records one safe audit', function () {
    [$actor, $company] = r14CategoryContext();
    $target = r14Category($company, $actor, 'Target');
    $moving = r14Category($company, $actor, 'Moving');
    $child = r14Category($company, $actor, 'Child', $moving);
    $grandchild = r14Category($company, $actor, 'Grandchild', $child);
    $moved = app(MoveCategoryAction::class)->execute($actor, $company, $moving, $target);

    expect($moved->parent_id)->toBe($target->id)
        ->and($moved->depth)->toBe(1)
        ->and($child->fresh()?->depth)->toBe(2)
        ->and($grandchild->fresh()?->depth)->toBe(3);

    $audit = AuditLog::query()->where('event', AuditEvent::CatalogCategoryMoved->value)->sole();
    expect($audit->getProperty('old_parent_uuid'))->toBeNull()
        ->and($audit->getProperty('new_parent_uuid'))->toBe($target->uuid)
        ->and($audit->getProperty('descendant_count'))->toBe(2);

    app(MoveCategoryAction::class)->execute($actor, $company, $moved, $target);
    expect(AuditLog::query()->count())->toBe(1);
});

test('move and all descendant depth writes roll back when transactional audit fails', function () {
    [$actor, $company] = r14CategoryContext();
    $target = r14Category($company, $actor, 'Target');
    $moving = r14Category($company, $actor, 'Moving');
    $child = r14Category($company, $actor, 'Child', $moving);
    $auditLogger = Mockery::mock(AuditLogger::class);
    $auditLogger->shouldReceive('logTenant')->once()->andThrow(new RuntimeException('Simulated audit failure'));
    app()->instance(AuditLogger::class, $auditLogger);

    try {
        expect(fn () => app(MoveCategoryAction::class)->execute($actor, $company, $moving, $target))
            ->toThrow(RuntimeException::class, 'Simulated audit failure');
    } finally {
        app()->forgetInstance(AuditLogger::class);
    }

    expect($moving->fresh()?->parent_id)->toBeNull()
        ->and($moving->fresh()?->depth)->toBe(0)
        ->and($child->fresh()?->parent_id)->toBe($moving->id)
        ->and($child->fresh()?->depth)->toBe(1)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('move rejects self deep cycles archived and foreign parents and excessive subtree depth', function () {
    [$actor, $company] = r14CategoryContext();
    $a = r14Category($company, $actor, 'A');
    $b = r14Category($company, $actor, 'B', $a);
    $c = r14Category($company, $actor, 'C', $b);
    $archived = r14Category($company, $actor, 'Archived', null, ['status' => CategoryStatus::Archived]);
    $foreign = Company::factory()->create();
    $foreignParent = r14Category($foreign, $actor, 'Foreign parent');
    $action = app(MoveCategoryAction::class);

    expect(fn () => $action->execute($actor, $company, $a, $a))
        ->toThrow(CategoryOperationException::class)
        ->and(fn () => $action->execute($actor, $company, $a, $c))
        ->toThrow(CategoryOperationException::class)
        ->and(fn () => $action->execute($actor, $company, $a, $archived))
        ->toThrow(CategoryOperationException::class, 'Archived parent')
        ->and(fn () => $action->execute($actor, $company, $a, $foreignParent))
        ->toThrow(CategoryOperationException::class);

    $deepParent = r14Category($company, $actor, 'Deep 0');

    for ($depth = 1; $depth < CategoryHierarchyService::MAX_DEPTH; $depth++) {
        $deepParent = r14Category($company, $actor, "Deep {$depth}", $deepParent);
    }

    expect(fn () => $action->execute($actor, $company, $b, $deepParent))
        ->toThrow(CategoryOperationException::class, 'Maximum category depth exceeded.')
        ->and($a->fresh()?->parent_id)->toBeNull()
        ->and($b->fresh()?->parent_id)->toBe($a->id)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('reorder requires the complete sibling set and assigns deterministic order', function () {
    [$actor, $company] = r14CategoryContext();
    $a = r14Category($company, $actor, 'A', null, ['sort_order' => 10]);
    $b = r14Category($company, $actor, 'B', null, ['sort_order' => 20]);
    $c = r14Category($company, $actor, 'C', null, ['sort_order' => 30]);
    $child = r14Category($company, $actor, 'Child', $a);
    $action = app(ReorderSiblingCategoriesAction::class);
    $action->execute($actor, $company, null, [$c->uuid, $a->uuid, $b->uuid]);

    expect($c->fresh()?->sort_order)->toBe(10)
        ->and($a->fresh()?->sort_order)->toBe(20)
        ->and($b->fresh()?->sort_order)->toBe(30)
        ->and($child->fresh()?->sort_order)->toBe(0)
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogCategoryReordered->value)->count())->toBe(1);

    $action->execute($actor, $company, null, [$c->uuid, $a->uuid, $b->uuid]);
    expect(AuditLog::query()->count())->toBe(1)
        ->and(fn () => $action->execute($actor, $company, null, [$a->uuid, $a->uuid, $c->uuid]))
        ->toThrow(CategoryOperationException::class)
        ->and(fn () => $action->execute($actor, $company, null, [$a->uuid, $b->uuid]))
        ->toThrow(CategoryOperationException::class)
        ->and(fn () => $action->execute($actor, $company, null, [$a->uuid, $b->uuid, $child->uuid]))
        ->toThrow(CategoryOperationException::class);
});

test('archive is idempotent and preserves hierarchy and product relations', function () {
    [$actor, $company] = r14CategoryContext();
    $leaf = r14Category($company, $actor, 'Leaf');
    $product = new Product;
    $product->forceFill([
        'company_id' => $company->id,
        'primary_category_id' => $leaf->id,
        'name' => 'Draft product',
        'slug' => 'draft-product',
        'slug_normalized' => 'draft-product',
        'status' => ProductStatus::Draft,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ])->save();
    $product->categories()->attach($leaf->id, ['company_id' => $company->id, 'created_at' => now()]);
    $action = app(ArchiveCategoryAction::class);
    $archived = $action->execute($actor, $company, $leaf);
    $action->execute($actor, $company, $archived);

    expect($archived->status)->toBe(CategoryStatus::Archived)
        ->and($archived->deleted_at)->toBeNull()
        ->and($product->fresh()?->primary_category_id)->toBe($leaf->id)
        ->and($product->categories()->whereKey($leaf->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogCategoryArchived->value)->count())->toBe(1);
});

test('archive blocks active children and active primary products without side effects', function () {
    [$actor, $company] = r14CategoryContext();
    $parent = r14Category($company, $actor, 'Parent');
    $child = r14Category($company, $actor, 'Child', $parent);

    expect(fn () => app(ArchiveCategoryAction::class)->execute($actor, $company, $parent))
        ->toThrow(CategoryOperationException::class, 'active child');

    $child->forceFill(['status' => CategoryStatus::Archived])->save();
    $product = new Product;
    $product->forceFill([
        'company_id' => $company->id,
        'primary_category_id' => $parent->id,
        'name' => 'Active product',
        'slug' => 'active-product',
        'slug_normalized' => 'active-product',
        'status' => ProductStatus::Active,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ])->save();

    expect(fn () => app(ArchiveCategoryAction::class)->execute($actor, $company, $parent))
        ->toThrow(CategoryOperationException::class, 'primary for active products')
        ->and($parent->fresh()?->status)->toBe(CategoryStatus::Active)
        ->and($child->fresh()?->parent_id)->toBe($parent->id)
        ->and($product->fresh()?->primary_category_id)->toBe($parent->id)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('restore is idempotent preserves slug and requires an active parent', function () {
    [$actor, $company] = r14CategoryContext();
    $parent = r14Category($company, $actor, 'Parent', null, ['status' => CategoryStatus::Archived]);
    $child = r14Category($company, $actor, 'Child', $parent, ['status' => CategoryStatus::Archived]);
    $action = app(RestoreCategoryAction::class);

    expect(fn () => $action->execute($actor, $company, $child))
        ->toThrow(CategoryOperationException::class, 'Restore the parent');

    $restoredParent = $action->execute($actor, $company, $parent);
    $restoredChild = $action->execute($actor, $company, $child);
    $action->execute($actor, $company, $restoredChild);

    expect($restoredParent->status)->toBe(CategoryStatus::Active)
        ->and($restoredChild->status)->toBe(CategoryStatus::Active)
        ->and($restoredChild->slug)->toBe('child')
        ->and($restoredChild->depth)->toBe(1)
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogCategoryRestored->value)->count())->toBe(2);
});

test('editor viewer wrong current company and removed membership cannot mutate categories', function (CompanyRole $role) {
    [$actor, $company] = r14CategoryContext($role);

    expect(fn () => app(CreateCategoryAction::class)->execute($actor, $company, ['name' => 'Denied']))
        ->toThrow(AuthorizationException::class);
})->with([
    'editor' => [CompanyRole::Editor],
    'viewer' => [CompanyRole::Viewer],
]);

test('category actions reject stale tenant context and removed membership', function () {
    [$actor, $company] = r14CategoryContext();
    $other = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['user_id' => $actor, 'company_id' => $other]);
    app(CurrentCompany::class)->set($other);

    expect(fn () => app(CreateCategoryAction::class)->execute($actor, $company, ['name' => 'Wrong current']))
        ->toThrow(AuthorizationException::class);

    app(CurrentCompany::class)->set($company);
    CompanyMembership::query()->where('user_id', $actor->id)->where('company_id', $company->id)->delete();

    expect(fn () => app(CreateCategoryAction::class)->execute($actor, $company, ['name' => 'Removed']))
        ->toThrow(AuthorizationException::class)
        ->and(Category::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});
