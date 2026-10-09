<?php

namespace Tests\Concurrency;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\PublishProductPassport;
use App\Actions\Passports\UnpublishProductPassport;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Data\Passports\PublicationResult;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Enums\Webhooks\WebhookDeliveryStatus;
use App\Enums\Webhooks\WebhookEventType;
use App\Events\Passports\ProductPassportPublished;
use App\Events\Passports\ProductPassportUnpublished;
use App\Exceptions\Passports\PublicationPostCommitException;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportAsset;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Models\Webhooks\WebhookEndpoint;
use App\Services\Passports\DppPayloadNormalizer;
use App\Services\Webhooks\WebhookDeliveryService;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class PassportPublicationTransactionBoundaryTest extends TestCase
{
    private static ?string $database = null;

    private static ?\PDO $server = null;

    private static bool $migrated = false;

    private Company $company;

    private User $actor;

    private Product $product;

    private int $revision = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useDisposableDatabase();

        $this->company = Company::factory()->create(['status' => CompanyStatus::Active]);
        $this->actor = User::factory()->create(['email_verified_at' => now()]);

        CompanyMembership::factory()->create([
            'company_id' => $this->company->getKey(),
            'user_id' => $this->actor->getKey(),
            'role' => CompanyRole::Owner,
        ]);

        $this->actingAs($this->actor);
        app(CurrentCompany::class)->set($this->company);

        View::share('currentCompany', $this->company);
        View::share('availableCompanies', new Collection([$this->company]));
        View::share('currentMembership', $this->actor->memberships()
            ->where('company_id', $this->company->getKey())
            ->first());
        View::share('slot', '');

        Storage::fake('catalog_media');
        Storage::disk('catalog_media')->put('test/storage-failure.jpg', 'fake content');

        $category = new Category;
        $category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Storage Failure Category',
            'slug' => 'storage-fail-cat-'.fake()->unique()->slug(1),
            'slug_normalized' => 'storage-fail-cat-'.fake()->unique()->slug(1),
            'depth' => 0,
            'sort_order' => 0,
            'status' => CategoryStatus::Active,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->product = new Product;
        $this->product->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Storage Failure Product '.fake()->unique()->word(),
            'slug' => 'storage-fail-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'storage-fail-product-'.fake()->unique()->slug(1),
            'brand' => 'Storage Failure Brand',
            'manufacturer' => 'Storage Failure Manufacturer',
            'status' => ProductStatus::Active,
            'primary_category_id' => $category->getKey(),
            'created_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $variant = new ProductVariant;
        $variant->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $this->product->getKey(),
            'name' => 'Default Variant',
            'sku' => 'SKU-SF-001',
            'status' => ProductVariantStatus::Active,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $media = new ProductMedia;
        $media->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $this->product->getKey(),
            'original_filename' => 'storage-failure.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/storage-failure.jpg',
            'checksum_sha256' => str_repeat('a', 64),
            'sort_order' => 0,
            'uploaded_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->product->forceFill([
            'default_variant_id' => $variant->getKey(),
            'primary_media_id' => $media->getKey(),
        ])->save();

        $this->product->categories()->attach($category->getKey(), [
            'company_id' => $this->company->getKey(),
            'created_at' => now(),
        ]);

        $this->product->refresh();
    }

    private function fillAllSections(): void
    {
        $passport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );

        $this->revision = $passport->currentDraftVersion->draft_revision;

        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Storage Failure Product Name',
            'public_description' => 'Storage failure test description.',
        ]);

        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Storage Failure Mfg Inc.',
            'responsible_operator_display_name' => 'Storage Failure Operator',
            'contact_notes' => 'Storage failure contact.',
        ]);

        $this->injectManufacturerContact();

        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Storage failure warning'],
            'storage_instructions' => 'Storage failure storage.',
        ]);

        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Storage failure recycling.',
        ]);

        $this->fillSection(DppSectionKey::MaterialsAndComposition, [
            'materials' => [
                ['name' => 'Plastic', 'percentage' => 100.0, 'recycled_content_percentage' => 30.0, 'hazardous' => false],
            ],
        ]);

        $this->fillSection(DppSectionKey::EnvironmentalInformation, [
            'carbon_footprint_kg_co2e' => 10.0,
        ]);

        $this->fillSection(DppSectionKey::UsageAndCare, [
            'usage_instructions' => 'Usage.',
        ]);

        $this->fillSection(DppSectionKey::RepairAndSpareParts, [
            'repair_instructions' => 'Repair.',
        ]);

        $this->fillSection(DppSectionKey::SupportAndContact, [
            'warranty_summary' => 'Warranty.',
        ]);
    }

    private function fillSection(DppSectionKey $section, array $payload): void
    {
        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first();

        $result = app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $section->value,
            $payload,
            $this->revision,
        );

        $this->revision = $result->currentDraftVersion->draft_revision;
    }

    private function injectManufacturerContact(): void
    {
        $draft = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->currentDraftVersion;

        $payload = $draft->payload;
        $payload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@storage-failure.example';

        $normalized = app(DppPayloadNormalizer::class)->normalize($payload);

        $newRevision = $draft->draft_revision + 1;
        $draft->setAttribute('payload', $normalized);
        $draft->setAttribute('draft_revision', $newRevision);
        $draft->setAttribute('updated_by', $this->actor->getKey());
        $draft->save();

        $this->revision = $newRevision;
    }

    private function freshPassport(): ProductPassport
    {
        return ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->fresh(['currentDraftVersion', 'currentPublishedVersion']);
    }

    /** No RefreshDatabase: these tests must reach a real PDO commit. */
    private function useDisposableDatabase(): void
    {
        $connection = DB::connection();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertStringEndsWith('_testing', $connection->getDatabaseName());
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
        $this->assertSame(DatabaseTransactionsManager::class, get_class(app('db.transactions')));

        $config = $connection->getConfig();
        if (self::$database === null) {
            self::$database = 'nordipass_np_p0_01_'.bin2hex(random_bytes(8)).'_testing';
            self::$server = new \PDO(
                'mysql:host='.$config['host'].';port='.$config['port'].';charset=utf8mb4',
                $config['username'], $config['password'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
            self::$server->exec('CREATE DATABASE `'.self::$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
        config()->set('database.connections.mysql', [...$config, 'database' => self::$database, 'url' => null]);
        DB::purge('mysql');
        if (! self::$migrated) {
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
            self::$migrated = true;
        }
        $this->assertNotEmpty(DB::select(
            'SELECT TRIGGER_NAME FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?',
            [self::$database, 'product_passport_versions'],
        ));
        config()->set('billing.provider', 'fake');
        config()->set('fortnox.provider', 'fake');
        config()->set('cache.default', 'array');
        Http::preventStrayRequests();
    }

    public static function tearDownAfterClass(): void
    {
        try {
            if (self::$server !== null && self::$database !== null
                && preg_match('/^nordipass_np_p0_01_[a-f0-9]{16}_testing$/', self::$database)) {
                self::$server->exec('DROP DATABASE `'.self::$database.'`');
            }
        } finally {
            self::$database = null;
            self::$server = null;
            self::$migrated = false;
            parent::tearDownAfterClass();
        }
    }

    protected function tearDown(): void
    {
        try {
            $connection = DB::connection();
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack(0);
            }
        } finally {
            parent::tearDown();
        }
    }

    private function publish(?string $key = null): PublicationResult
    {
        $this->product->refresh();
        $passport = $this->freshPassport();

        return app(PublishProductPassport::class)->handle(
            $this->actor, $this->company, $this->product, $passport,
            $passport->currentDraftVersion->draft_revision, true, $key,
        );
    }

    public function test_webhook_outbox_waits_for_the_outer_commit_and_rollback_sends_nothing(): void
    {
        config(['queue.default' => 'sync']);
        Http::preventStrayRequests();
        Http::fake(['https://93.184.216.34/events' => Http::response('accepted', 202)]);
        WebhookEndpoint::create([
            'company_id' => $this->company->id, 'url' => 'https://93.184.216.34/events', 'secret' => 'fixture-secret',
            'events' => ['import.completed'], 'status' => 'active',
        ]);
        $service = app(WebhookDeliveryService::class);
        DB::beginTransaction();
        $service->enqueue($this->company, WebhookEventType::ImportCompleted, ['processed_rows' => 1]);
        Http::assertNothingSent();
        DB::rollBack();
        $this->assertDatabaseCount('webhook_deliveries', 0);
        Http::assertNothingSent();

        DB::beginTransaction();
        $deliveries = $service->enqueue($this->company, WebhookEventType::ImportCompleted, ['processed_rows' => 2]);
        Http::assertNothingSent();
        DB::commit();
        Http::assertSentCount(1);
        $this->assertSame(WebhookDeliveryStatus::Delivered, $deliveries->sole()->fresh()->status);
    }

    public function test_unpublication_defers_events_to_outer_commit_and_rollback_preserves_publication(): void
    {
        $this->fillAllSections();
        $this->publish();
        $events = 0;
        Event::listen(ProductPassportUnpublished::class, function () use (&$events): void {
            $this->assertSame(0, DB::transactionLevel());
            $events++;
        });
        $unpublish = fn () => app(UnpublishProductPassport::class)->handle(
            $this->actor, $this->company, $this->product, $this->freshPassport());
        DB::beginTransaction();
        $unpublish();
        $this->assertSame(0, $events);
        DB::rollBack();
        $this->assertNotNull($this->freshPassport()->current_published_version_id);
        $this->assertSame(0, $events);
        DB::beginTransaction();
        $unpublish();
        $this->assertSame(0, $events);
        DB::commit();
        $this->assertSame(1, $events);
        $this->assertNull($this->freshPassport()->current_published_version_id);
    }

    private function assertCommittedAssets(): array
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        // A separate physical connection proves visibility outside the committing session.
        $config = DB::connection()->getConfig();
        $observer = new \PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.self::$database,
            $config['username'], $config['password']);
        $query = $observer->prepare('SELECT current_published_version_id FROM product_passports WHERE id = ?');
        $query->execute([$this->freshPassport()->getKey()]);
        $versionId = $query->fetchColumn();
        $this->assertNotFalse($versionId);
        $this->assertGreaterThan(0, (int) $versionId);
        $version = ProductPassportVersion::query()->findOrFail($versionId);
        $this->assertSame(ProductPassportVersionStatus::Published, $version->status);
        $assets = ProductPassportAsset::query()->where('version_id', $versionId)->get();
        $this->assertNotEmpty($assets);
        $saved = [];
        foreach ($assets as $asset) {
            $this->assertSame($asset->uuid, $version->payload['_catalog_context']['media'][0]['asset_uuid']);
            $bytes = Storage::disk('passport_assets')->get($asset->storage_key);
            $this->assertSame('fake content', $bytes);
            $this->assertSame($asset->checksum_sha256, hash('sha256', $bytes));
            $saved[$asset->storage_key] = $bytes;
        }

        return $saved;
    }

    public function test_listener_failure_after_real_commit_preserves_published_assets(): void
    {
        $this->fillAllSections();
        $faultReached = false;
        Event::listen(ProductPassportPublished::class,
            function () use (&$faultReached): void {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertFalse(DB::connection()->getPdo()->inTransaction());
                $faultReached = true;
                throw new \RuntimeException('Injected postcommit listener failure');
            });
        $caught = null;
        try {
            $this->publish();
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }
        $this->assertTrue($faultReached, 'The fault must occur after an actual commit.');
        $this->assertNotNull($caught);
        $this->assertInstanceOf(PublicationPostCommitException::class, $caught);
        $this->assertSame('event', $caught->step);
        $this->assertSame('Injected postcommit listener failure', $caught->getPrevious()->getMessage());
        $this->assertCommittedAssets();
    }

    public function test_successful_standalone_publication_has_durable_rows_references_and_bytes(): void
    {
        $this->fillAllSections();
        $result = $this->publish('standalone-success');
        $this->assertCommittedAssets();
        $this->assertSame($result->publishedVersion->uuid,
            Cache::get('passport_publish:'.$this->company->getKey().':standalone-success')['published_version_uuid']);
    }

    public static function postcommitSteps(): array
    {
        return [['relations'], ['event'], ['cache']];
    }

    #[DataProvider('postcommitSteps')]
    public function test_postcommit_failures_preserve_previous_and_new_assets(string $step): void
    {
        $this->fillAllSections();
        $this->publish();
        $previous = $this->assertCommittedAssets();
        $faultReached = false;
        $fail = function () use (&$faultReached): void {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertCommittedAssets();
            $faultReached = true;
            throw new \RuntimeException('Injected '.$this->name().' failure');
        };
        $key = null;
        if ($step === 'relations') {
            $committing = false;
            Event::listen(TransactionCommitting::class,
                function () use (&$committing): void {
                    $committing = true;
                });
            DB::listen(function ($query) use (&$committing, $fail): void {
                if ($committing && DB::transactionLevel() === 0
                    && str_contains($query->sql, 'product_passport_versions')) {
                    $committing = false;
                    $fail();
                }
            });
        } elseif ($step === 'event') {
            Event::listen(ProductPassportPublished::class, $fail);
        } else {
            $key = 'postcommit-cache-failure';
            Cache::shouldReceive('get')->with('passport_publish:'.$this->company->getKey().':'.$key)->once()->andReturnNull();
            Cache::shouldReceive('put')
                ->withArgs(fn ($cacheKey, ...$args): bool => $cacheKey === 'passport_publish:'.$this->company->getKey().':'.$key)
                ->once()->andReturnUsing($fail);
        }

        $caught = null;
        try {
            $this->publish($key);
        } catch (PublicationPostCommitException $exception) {
            $caught = $exception;
        }
        $this->assertTrue($faultReached);
        $this->assertNotNull($caught);
        $this->assertSame($step, $caught->step);
        $this->assertSame($this->freshPassport()->current_published_version_id, $caught->result->publishedVersion->getKey());
        $this->assertCommittedAssets();
        $this->assertSavedFiles($previous);
    }

    private function assertSavedFiles(array $files): void
    {
        foreach ($files as $path => $bytes) {
            $this->assertSame($bytes, Storage::disk('passport_assets')->get($path));
        }
    }

    public function test_failure_before_promotion_does_not_publish(): void
    {
        $this->fillAllSections();
        $passport = $this->freshPassport();
        $caught = null;
        try {
            app(PublishProductPassport::class)->handle($this->actor, $this->company, $this->product,
                $passport, $passport->currentDraftVersion->draft_revision + 1, true);
        } catch (ConflictHttpException $exception) {
            $caught = $exception;
        }
        $this->assertNotNull($caught);
        $this->assertNull($this->freshPassport()->current_published_version_id);
        $this->assertSame([], Storage::disk('passport_assets')->allFiles());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_failure_after_promotion_before_commit_cleans_only_this_attempt(): void
    {
        $this->fillAllSections();
        $this->publish();
        $previous = $this->assertCommittedAssets();
        $versionId = $this->freshPassport()->current_published_version_id;
        $faultReached = false;
        ProductPassportAsset::created(function () use (&$faultReached, $previous): void {
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $this->assertCount(2, Storage::disk('passport_assets')->allFiles());
            $this->assertSavedFiles($previous);
            $faultReached = true;
            throw new \RuntimeException('Injected failure after promotion');
        });
        $caught = null;
        try {
            $this->publish();
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }
        $this->assertTrue($faultReached);
        $this->assertSame('Injected failure after promotion', $caught?->getMessage());
        $this->assertSame($versionId, $this->freshPassport()->current_published_version_id);
        $this->assertEqualsCanonicalizing(array_keys($previous), Storage::disk('passport_assets')->allFiles());
        $this->assertCommittedAssets();
    }

    public function test_exception_at_physical_commit_rolls_back_and_cleans_promoted_files(): void
    {
        $this->fillAllSections();
        $faultReached = false;
        Event::listen(TransactionCommitting::class,
            function () use (&$faultReached): void {
                $this->assertCount(1, Storage::disk('passport_assets')->allFiles());
                $this->assertSame(1, DB::transactionLevel());
                $faultReached = true;
                throw new \RuntimeException('Injected precommit failure');
            });
        $caught = null;
        try {
            $this->publish();
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }
        $this->assertTrue($faultReached);
        $this->assertSame('Injected precommit failure', $caught?->getMessage());
        $this->assertNull($this->freshPassport()->current_published_version_id);
        $this->assertSame([], Storage::disk('passport_assets')->allFiles());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_outer_commit_defers_events_and_cache_until_publication_is_durable(): void
    {
        $this->fillAllSections();
        $events = 0;
        Event::listen(ProductPassportPublished::class,
            function () use (&$events): void {
                $this->assertCommittedAssets();
                $events++;
            });
        DB::beginTransaction();
        $result = $this->publish('outer-commit');
        $this->assertSame(1, DB::transactionLevel());
        $this->assertTrue(DB::connection()->getPdo()->inTransaction());
        $this->assertSame(0, $events);
        $this->assertNull(Cache::get('passport_publish:'.$this->company->getKey().':outer-commit'));
        $config = DB::connection()->getConfig();
        $observer = new \PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.self::$database,
            $config['username'], $config['password']);
        $query = $observer->prepare('SELECT current_published_version_id FROM product_passports WHERE id = ?');
        $query->execute([$result->passport->getKey()]);
        $this->assertNull($query->fetchColumn());
        DB::commit();
        $this->assertSame(1, $events);
        $this->assertCommittedAssets();
        $this->assertNotNull(Cache::get('passport_publish:'.$this->company->getKey().':outer-commit'));
    }

    public static function rollbackDepths(): array
    {
        return [[1, false], [2, false], [2, true]];
    }

    #[DataProvider('rollbackDepths')]
    public function test_outer_rollback_cleans_assets_even_after_intermediate_savepoint_release(int $depth, bool $release): void
    {
        $this->fillAllSections();
        $this->publish();
        $previous = $this->assertCommittedAssets();
        $versionId = $this->freshPassport()->current_published_version_id;
        $events = 0;
        Event::listen(ProductPassportPublished::class,
            function () use (&$events): void {
                $events++;
            });
        for ($level = 0; $level < $depth; $level++) {
            DB::beginTransaction();
        }
        $this->publish('outer-rollback');
        $this->assertSame($depth, DB::transactionLevel());
        $this->assertCount(2, Storage::disk('passport_assets')->allFiles());
        if ($release) {
            DB::commit();
        }
        DB::rollBack(0);
        $this->assertSame(0, $events);
        $this->assertNull(Cache::get('passport_publish:'.$this->company->getKey().':outer-rollback'));
        $this->assertSame($versionId, $this->freshPassport()->current_published_version_id);
        $this->assertEqualsCanonicalizing(array_keys($previous), Storage::disk('passport_assets')->allFiles());
        $this->assertCommittedAssets();
    }

    public function test_listener_failure_at_outer_commit_preserves_committed_publication(): void
    {
        $this->fillAllSections();
        Event::listen(ProductPassportPublished::class,
            function (): void {
                throw new \RuntimeException('Injected outer postcommit failure');
            });
        DB::beginTransaction();
        $result = $this->publish();
        $caught = null;
        try {
            DB::commit();
        } catch (PublicationPostCommitException $exception) {
            $caught = $exception;
        }
        $this->assertNotNull($caught);
        $this->assertSame($result, $caught->result);
        $this->assertCommittedAssets();
    }

    public function test_savepoint_rollback_keeps_callers_transaction_and_previous_assets(): void
    {
        $this->fillAllSections();
        $this->publish();
        $previous = $this->assertCommittedAssets();
        DB::beginTransaction();
        DB::beginTransaction();
        $this->publish();
        DB::rollBack();
        $this->assertSame(1, DB::transactionLevel());
        $this->assertEqualsCanonicalizing(array_keys($previous), Storage::disk('passport_assets')->allFiles());
        DB::commit();
        $this->assertCommittedAssets();
    }

    public function test_outer_rollback_of_first_publication_leaves_no_published_rows_or_files(): void
    {
        $this->fillAllSections();
        $passportId = $this->freshPassport()->getKey();
        DB::beginTransaction();
        $this->publish();
        $this->assertCount(1, Storage::disk('passport_assets')->allFiles());
        DB::rollBack();
        $this->assertNull($this->freshPassport()->current_published_version_id);
        $this->assertSame(0, ProductPassportVersion::query()->where('passport_id', $passportId)
            ->where('status', ProductPassportVersionStatus::Published)->count());
        $this->assertSame(0, ProductPassportAsset::query()->where('passport_id', $passportId)->count());
        $this->assertSame([], Storage::disk('passport_assets')->allFiles());
    }

    public function test_failed_publication_rolls_back_only_its_savepoint(): void
    {
        $this->fillAllSections();
        DB::beginTransaction();
        $this->company->forceFill(['name' => 'Caller transaction survives'])->save();
        ProductPassportAsset::created(function (): void {
            $this->assertSame(2, DB::transactionLevel());
            $this->assertCount(1, Storage::disk('passport_assets')->allFiles());
            throw new \RuntimeException('Injected savepoint failure');
        });
        $caught = null;
        try {
            $this->publish();
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }
        $this->assertSame('Injected savepoint failure', $caught?->getMessage());
        $this->assertSame(1, DB::transactionLevel());
        $this->assertTrue(DB::connection()->getPdo()->inTransaction());
        $this->assertNull($this->freshPassport()->current_published_version_id);
        $this->assertSame([], Storage::disk('passport_assets')->allFiles());
        DB::commit();
        $this->assertSame('Caller transaction survives', $this->company->fresh()->name);
    }

    public function test_earlier_aftercommit_callback_failure_preserves_published_assets(): void
    {
        $this->fillAllSections();
        DB::beginTransaction();
        DB::beginTransaction();
        DB::afterCommit(function (): void {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            throw new \RuntimeException('Injected earlier commit callback failure');
        });
        DB::commit();
        $result = $this->publish();
        $caught = null;
        try {
            DB::commit();
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }
        $this->assertSame('Injected earlier commit callback failure', $caught?->getMessage());
        $this->assertSame($result->publishedVersion->getKey(), $this->freshPassport()->current_published_version_id);
        $this->assertCommittedAssets();
    }
}
