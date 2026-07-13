<?php

use Illuminate\Support\Facades\DB;

test('authorization integration suite uses its dedicated mysql database', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toBe('nordipass_testing');
});
