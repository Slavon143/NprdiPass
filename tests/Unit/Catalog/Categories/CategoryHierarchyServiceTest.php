<?php

use App\Models\Catalog\Category;
use App\Models\Company;
use App\Services\Catalog\CategoryHierarchyService;
use Illuminate\Support\Collection;

function r14HierarchyCategory(int $id, int $companyId, ?int $parentId, int $depth, string $name): Category
{
    $category = new Category;
    $category->setRawAttributes([
        'id' => $id,
        'company_id' => $companyId,
        'parent_id' => $parentId,
        'depth' => $depth,
        'name' => $name,
    ]);

    return $category;
}

test('hierarchy service calculates ancestors descendants subtree depth cycle and breadcrumb', function () {
    $company = new Company;
    $company->setRawAttributes(['id' => 10]);
    $root = r14HierarchyCategory(1, 10, null, 0, 'Root');
    $child = r14HierarchyCategory(2, 10, 1, 1, 'Child');
    $grandchild = r14HierarchyCategory(3, 10, 2, 2, 'Grandchild');
    $sibling = r14HierarchyCategory(4, 10, 1, 1, 'Sibling');
    $foreign = r14HierarchyCategory(5, 20, null, 0, 'Foreign');
    /** @var Collection<int, Category> $categories */
    $categories = collect([$root, $child, $grandchild, $sibling, $foreign]);
    $service = new CategoryHierarchyService;

    expect($service->ancestorIds($company, $grandchild, $categories))->toBe([2, 1])
        ->and($service->descendantIds($company, $root, $categories))->toEqualCanonicalizing([2, 3, 4])
        ->and($service->subtree($company, $child, $categories)->pluck('id')->all())->toBe([2, 3])
        ->and($service->maximumRelativeSubtreeDepth($company, $root, $categories))->toBe(2)
        ->and($service->wouldCreateCycle($company, $root, $grandchild, $categories))->toBeTrue()
        ->and($service->wouldCreateCycle($company, $child, null, $categories))->toBeFalse()
        ->and($service->breadcrumb($company, $grandchild, $categories))->toHaveCount(3)
        ->and(array_map(fn (Category $category): string => $category->name, $service->breadcrumb($company, $grandchild, $categories)))
        ->toBe(['Root', 'Child', 'Grandchild']);
});
