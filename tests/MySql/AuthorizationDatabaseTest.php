<?php

use Illuminate\Support\Facades\DB;

test('authorization integration suite uses its dedicated mysql database', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(app()->environment())->toBe('testing')
        ->and(DB::connection()->getDatabaseName())->toMatch('/^nordipass(?:_[a-z0-9]+)*_testing$/');
});
