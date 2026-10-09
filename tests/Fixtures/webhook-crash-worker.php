<?php

use App\Enums\Webhooks\WebhookDeliveryStatus;
use App\Models\Webhooks\WebhookDelivery;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (! $app->environment('testing') || ! str_ends_with(DB::connection()->getDatabaseName(), '_testing')) {
    throw new RuntimeException('Crash drill requires an isolated testing schema.');
}
$marker = $argv[1];
$mode = $argv[2];
Http::preventStrayRequests();
Http::fake(function ($request) use ($marker, $mode) {
    $key = $request->header('Idempotency-Key')[0];
    $state = json_decode(file_get_contents($marker), true);
    $state['received'][] = $key;
    $state['applied'] = array_values(array_unique([...$state['applied'], $key]));
    file_put_contents($marker, json_encode($state, JSON_THROW_ON_ERROR));
    if ($mode === 'after-receiver') {
        exit(91); // Receiver applied it; NordiPass has not persisted the response.
    }

    return Http::response('', 204);
});
if ($mode === 'after-success') {
    WebhookDelivery::saved(function ($delivery): void {
        if ($delivery->status === WebhookDeliveryStatus::Delivered) {
            exit(92); // DB success persisted; queue acknowledgement has not run.
        }
    });
}
$kernel->call('queue:work', ['--once' => true, '--queue' => 'webhooks', '--tries' => 5, '--timeout' => 30]);
throw new RuntimeException('The crash injection point was not reached.');
