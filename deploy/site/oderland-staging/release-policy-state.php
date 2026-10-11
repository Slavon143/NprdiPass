<?php

use App\Exceptions\Fortnox\FortnoxSubscriptionBlocked;
use App\Services\Fortnox\FortnoxSubscriptionGuard;
use Illuminate\Contracts\Console\Kernel;

$base = '/home/codecraf/nordipass-staging';
$root = $argv[1] ?? '';
if (realpath($root) !== $root || dirname($root) !== $base.'/releases'
    || realpath($root.'/.env') !== $base.'/shared/.env') {
    exit(2);
}
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$guard = $app->make(FortnoxSubscriptionGuard::class);
$report = ['environment' => $app->environment(), 'oauth_enabled' => (bool) config('fortnox.nonproduction_oauth_enabled'),
    'expected_tenant' => $guard->expectedTenantId(), 'no_external_requests_performed' => true];
foreach (['1853390', '1854146', null, '9999999'] as $tenant) {
    try {
        $guard->assertTenantAllowed($tenant);
        $report['tenant_outcomes'][$tenant ?? 'unknown'] = 'accepted';
    } catch (FortnoxSubscriptionBlocked) {
        $report['tenant_outcomes'][$tenant ?? 'unknown'] = 'blocked';
    }
}
try {
    $guard->assertAuthorizationEnabled();
    $report['new_authorization'] = 'accepted';
} catch (FortnoxSubscriptionBlocked) {
    $report['new_authorization'] = 'blocked';
}
echo json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL;
