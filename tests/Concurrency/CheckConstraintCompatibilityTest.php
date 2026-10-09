<?php

use App\Support\Database\CheckConstraints;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

test('replacing a check constraint preserves unrelated validation and supports reversal', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $table = 'constraint_compatibility_probe_'.strtolower(Str::random(12));

    try {
        Schema::create($table, function (Blueprint $table): void {
            $table->string('kind');
            $table->integer('size_bytes');
        });
        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT kind_check CHECK (kind IN ('product_media', 'variant_media'))");
        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT size_check CHECK (size_bytes > 0)");
        DB::table($table)->insert(['kind' => 'product_media', 'size_bytes' => 1]);
        expect(fn () => DB::table($table)->insert(['kind' => 'document', 'size_bytes' => 1]))->toThrow(QueryException::class);

        CheckConstraints::drop($table, 'kind_check');
        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT kind_check CHECK (kind IN ('product_media', 'variant_media', 'document'))");
        DB::table($table)->insert(['kind' => 'document', 'size_bytes' => 1]);
        expect(fn () => DB::table($table)->insert(['kind' => 'invalid', 'size_bytes' => 1]))->toThrow(QueryException::class);
        expect(fn () => DB::table($table)->insert(['kind' => 'document', 'size_bytes' => 0]))->toThrow(QueryException::class);
        expect(DB::table($table)->count())->toBe(2);

        DB::table($table)->where('kind', 'document')->delete();
        CheckConstraints::drop($table, 'kind_check');
        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT kind_check CHECK (kind IN ('product_media', 'variant_media'))");
        expect(fn () => DB::table($table)->insert(['kind' => 'document', 'size_bytes' => 1]))->toThrow(QueryException::class);
        expect(fn () => DB::table($table)->insert(['kind' => 'product_media', 'size_bytes' => 0]))->toThrow(QueryException::class);
        expect(DB::table($table)->count())->toBe(1);
    } finally {
        Schema::dropIfExists($table);
    }
});
