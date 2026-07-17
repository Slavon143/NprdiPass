<?php

namespace Tests\Feature\Passports\Concurrency;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class DppConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['status' => CompanyStatus::Active]);
        $this->actor = User::factory()->create(['email_verified_at' => now()]);

        CompanyMembership::factory()->create([
            'company_id' => $this->company->getKey(),
            'user_id' => $this->actor->getKey(),
            'role' => CompanyRole::Owner,
        ]);

        $this->actingAs($this->actor);
        app(CurrentCompany::class)->set($this->company);

        $this->product = new Product;
        $this->product->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Concurrency Product '.fake()->unique()->word(),
            'slug' => 'concurrency-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'concurrency-product-'.fake()->unique()->slug(1),
            'status' => ProductStatus::Active,
            'created_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();
        $this->product->refresh();
    }

    public function test_two_concurrent_passport_creates_only_one_passport(): void
    {
        $action = app(CreateProductPassportDraftAction::class);

        $competitorConfig = config('database.connections.mysql');
        config()->set('database.connections.mysql_concurrency_competitor', $competitorConfig);
        DB::purge('mysql_concurrency_competitor');
        $primary = DB::connection('mysql');
        $competitor = DB::connection('mysql_concurrency_competitor');
        $competitor->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $primary->beginTransaction();
        try {
            ProductPassport::query()
                ->forCompany($this->company)
                ->where('product_id', $this->product->getKey())
                ->lockForUpdate()
                ->doesntExist();

            $competitor->beginTransaction();

            $threw = false;
            try {
                $competitor->select(
                    'SELECT id FROM product_passports WHERE company_id = ? AND product_id = ? FOR UPDATE',
                    [$this->company->getKey(), $this->product->getKey()],
                );
            } catch (QueryException) {
                $threw = true;
            }

            if (! $threw) {
                $competitor->rollBack();
            }
        } finally {
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
        }

        $passportCount = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->count();
        $this->assertSame(0, $passportCount);

        $first = $action->handle($this->actor, $this->company, $this->product);
        $second = $action->handle($this->actor, $this->company, $this->product);

        $this->assertEquals($first->getKey(), $second->getKey());

        $count = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->count();
        $this->assertSame(1, $count);

        DB::disconnect('mysql_concurrency_competitor');
    }

    public function test_two_concurrent_section_updates_same_revision_one_succeeds_one_409(): void
    {
        $action = app(CreateProductPassportDraftAction::class);
        $passport = $action->handle($this->actor, $this->company, $this->product);

        $updateAction = app(UpdateProductPassportSectionAction::class);

        $result = $updateAction->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            DppSectionKey::UsageAndCare->value,
            ['usage_instructions' => 'First concurrent.'],
            1,
        );

        $this->assertSame(2, $result->currentDraftVersion->draft_revision);

        $this->expectException(ConflictHttpException::class);

        $updateAction->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            DppSectionKey::UsageAndCare->value,
            ['usage_instructions' => 'Stale concurrent.'],
            1,
        );
    }

    public function test_sequential_updates_different_revisions_both_succeed(): void
    {
        $passport = app(CreateProductPassportDraftAction::class)
            ->handle($this->actor, $this->company, $this->product);

        $updateAction = app(UpdateProductPassportSectionAction::class);

        $first = $updateAction->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            DppSectionKey::UsageAndCare->value,
            ['usage_instructions' => 'First.'],
            1,
        );

        $this->assertSame(2, $first->currentDraftVersion->draft_revision);

        $second = $updateAction->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport->fresh(),
            DppSectionKey::RecyclingAndDisposal->value,
            ['recycling_instructions' => 'Second.'],
            2,
        );

        $this->assertSame(3, $second->currentDraftVersion->draft_revision);
    }

    public function test_failed_validation_does_not_increment_revision(): void
    {
        $passport = app(CreateProductPassportDraftAction::class)
            ->handle($this->actor, $this->company, $this->product);

        $originalRevision = $passport->currentDraftVersion->draft_revision;
        $this->assertSame(1, $originalRevision);

        try {
            app(UpdateProductPassportSectionAction::class)->handle(
                $this->actor,
                $this->company,
                $this->product,
                $passport,
                'nonexistent',
                ['field' => 'value'],
                $originalRevision,
            );
        } catch (ValidationException) {
        }

        $passport->refresh();
        $this->assertSame(
            $originalRevision,
            $passport->currentDraftVersion->draft_revision,
        );
    }

    public function test_published_version_cannot_be_edited(): void
    {
        $passport = app(CreateProductPassportDraftAction::class)
            ->handle($this->actor, $this->company, $this->product);

        $draft = $passport->currentDraftVersion;
        $draft->setAttribute('status', ProductPassportVersionStatus::Published);
        $draft->setAttribute('version_number', 1);
        $draft->setAttribute('published_at', now());
        $draft->setAttribute('published_by', $this->actor->getKey());
        $draft->save();

        $passport->setAttribute('current_draft_version_id', null);
        $passport->setAttribute('current_published_version_id', $draft->getKey());
        $passport->setAttribute('status', ProductPassportStatus::Published);
        $passport->save();

        $this->expectException(ConflictHttpException::class);

        app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport->fresh(),
            DppSectionKey::UsageAndCare->value,
            ['usage_instructions' => 'Should fail.'],
            1,
        );
    }
}
