<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('release schema verification rejects pending migrations legacy storage and damaged triggers without repair', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $this->artisan('migrate:fresh')->assertSuccessful();
    $this->artisan('release:verify-schema')->assertSuccessful();
    $migration = DB::table('migrations')->latest('id')->first();
    DB::table('migrations')->where('id', $migration->id)->delete();
    $this->artisan('release:verify-schema')->expectsOutputToContain('migration set')->assertFailed();
    expect(DB::table('migrations')->where('id', $migration->id)->exists())->toBeFalse();
    DB::table('migrations')->insert((array) $migration);
    Schema::table('companies', fn ($table) => $table->unsignedBigInteger('catalog_products_count')->nullable());
    $this->artisan('release:verify-schema')->expectsOutputToContain('capacity storage')->assertFailed();
    expect(Schema::hasColumn('companies', 'catalog_products_count'))->toBeTrue();
    Schema::table('companies', fn ($table) => $table->dropColumn('catalog_products_count'));
    foreach (['products_capacity_insert', 'companies_catalog_capacity_insert'] as $name) {
        $definition = (array) DB::selectOne('SHOW CREATE TRIGGER '.$name);
        try {
            DB::unprepared('DROP TRIGGER '.$name);
            $this->artisan('release:verify-schema')->expectsOutputToContain('capacity trigger')->assertFailed();
            expect(DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TRIGGER_NAME', $name)->exists())->toBeFalse();
        } finally {
            DB::unprepared($definition['SQL Original Statement']);
        }
    }
    $productDefinition = (array) DB::selectOne('SHOW CREATE TRIGGER products_capacity_insert');
    DB::unprepared('DROP TRIGGER products_capacity_insert');
    try {
        DB::unprepared('CREATE TRIGGER products_capacity_insert AFTER INSERT ON products FOR EACH ROW SET @invalid_capacity_trigger = 1');
        $this->artisan('release:verify-schema')->expectsOutputToContain('product capacity trigger')->assertFailed();
    } finally {
        DB::unprepared('DROP TRIGGER products_capacity_insert');
        DB::unprepared($productDefinition['SQL Original Statement']);
    }
    $this->artisan('release:verify-schema')->assertSuccessful();
    expect(Artisan::output())->not->toContain('DB_PASSWORD')->not->toContain('APP_KEY');
});

test('release schema rejects slot-only triggers which would lose the retained pool count', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $this->artisan('migrate:fresh')->assertSuccessful();
    $definition = (array) DB::selectOne('SHOW CREATE TRIGGER products_capacity_insert');
    DB::unprepared('DROP TRIGGER products_capacity_insert');
    try {
        DB::unprepared("CREATE TRIGGER products_capacity_insert AFTER INSERT ON products FOR EACH ROW BEGIN
            IF NEW.managed = 1 THEN
                UPDATE catalog_product_capacities SET products_count = products_count + 1 WHERE company_id = NEW.company_id;
                IF ROW_COUNT() = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Missing capacity'; END IF;
            END IF;
        END");
        $this->artisan('release:verify-schema')->expectsOutputToContain('managed catalogue capacity trigger')->assertFailed();
    } finally {
        DB::unprepared('DROP TRIGGER products_capacity_insert');
        DB::unprepared($definition['SQL Original Statement']);
    }
    $this->artisan('release:verify-schema')->assertSuccessful();
});
