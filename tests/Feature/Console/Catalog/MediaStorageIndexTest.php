<?php

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('media path ownership lookup uses the full path index and passes release schema verification', function () {
    $company = Company::factory()->create();
    $uuid = (string) Str::uuid();
    $product = DB::table('products')->insertGetId([
        'uuid' => $uuid, 'company_id' => $company->id, 'name' => 'Index fixture',
        'slug' => $uuid, 'slug_normalized' => $uuid, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $rows = [];
    for ($i = 0; $i < 1000; $i++) {
        $rows[] = [
            'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'product_id' => $product,
            'storage_path' => $company->uuid.'/image-'.$i.'.jpg', 'original_filename' => 'fixture.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 1, 'checksum_sha256' => str_repeat('a', 64),
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ];
    }
    DB::table('product_media')->insert($rows);
    $plan = DB::selectOne('EXPLAIN SELECT id FROM product_media WHERE storage_path = ?', [$company->uuid.'/image-999.jpg']);
    expect($plan->key)->toBe('media_storage_path_index');
    $this->artisan('release:verify-schema')->assertSuccessful();
});
