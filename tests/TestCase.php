<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            return;
        }

        $database = (string) config('database.connections.mysql.database');

        if (! str_ends_with($database, '_testing')) {
            throw new RuntimeException(
                'MySQL tests must use a dedicated database whose name ends with _testing.',
            );
        }
    }
}
