<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
    || ! str_ends_with(DB::connection()->getDatabaseName(), '_testing')) {
    throw new RuntimeException('Capacity writer requires an isolated MySQL testing schema.');
}
Http::preventStrayRequests();
$companyId = (int) ($argv[1] ?? 0);
$productId = (int) ($argv[2] ?? 0);
$change = $argv[3] ?? '';
if (! in_array($change, ['archive', 'soft-delete'], true)) {
    throw new RuntimeException('Unknown capacity writer mode.');
}
echo json_encode(['ready_connection' => DB::selectOne('SELECT CONNECTION_ID() AS id')->id], JSON_THROW_ON_ERROR)."\n";
flush();
$changed = DB::table('products')->where('company_id', $companyId)->where('id', $productId)
    ->update($change === 'archive' ? ['status' => 'archived'] : ['deleted_at' => now()]);
if ($changed !== 1) {
    throw new RuntimeException('Capacity writer did not change the tenant product.');
}
echo "DONE\n";
