<?php

use App\Actions\Catalog\Categories\MoveCategoryAction;
use App\Enums\Catalog\CategoryStatus;
use App\Exceptions\Catalog\CategoryOperationException;
use App\Models\Catalog\Category;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('mysql serializes category structural mutations per company and cycle validation sees fresh rows', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');

    $this->artisan('migrate:fresh')->assertSuccessful();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company, 'user_id' => $actor]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $a = categoryConcurrencyRow($company, $actor, 'A');
    $b = categoryConcurrencyRow($company, $actor, 'B', $a);
    $c = categoryConcurrencyRow($company, $actor, 'C', $b);

    $competitorConfig = config('database.connections.mysql');
    config()->set('database.connections.mysql_category_competitor', $competitorConfig);
    DB::purge('mysql_category_competitor');
    $primary = DB::connection('mysql');
    $competitor = DB::connection('mysql_category_competitor');
    $competitor->statement('SET SESSION innodb_lock_wait_timeout = 1');

    $primary->beginTransaction();
    try {
        Category::query()->forCompany($company)->orderBy('id')->lockForUpdate()->get();
        $competitor->beginTransaction();

        expect(fn () => $competitor->select(
            'select id from categories where company_id = ? order by id for update',
            [$company->id],
        ))->toThrow(QueryException::class);
    } finally {
        if ($competitor->transactionLevel() > 0) {
            $competitor->rollBack();
        }
        $primary->rollBack();
    }

    expect(fn () => app(MoveCategoryAction::class)->execute($actor, $company, $a, $c))
        ->toThrow(CategoryOperationException::class)
        ->and($a->fresh()?->parent_id)->toBeNull()
        ->and($b->fresh()?->parent_id)->toBe($a->id)
        ->and($c->fresh()?->parent_id)->toBe($b->id)
        ->and($a->fresh()?->depth)->toBe(0)
        ->and($b->fresh()?->depth)->toBe(1)
        ->and($c->fresh()?->depth)->toBe(2);

    DB::disconnect('mysql_category_competitor');
});

function categoryConcurrencyRow(
    Company $company,
    User $actor,
    string $name,
    ?Category $parent = null,
): Category {
    $slug = str($name)->slug()->toString();
    $category = new Category;
    $category->forceFill([
        'company_id' => $company->id,
        'parent_id' => $parent?->id,
        'depth' => $parent === null ? 0 : $parent->depth + 1,
        'name' => $name,
        'slug' => $slug,
        'slug_normalized' => $slug,
        'sort_order' => 10,
        'status' => CategoryStatus::Active,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ])->save();

    return $category->refresh();
}
