<?php

namespace App\Support\Database;

use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use LogicException;

final class CheckConstraints
{
    public static function drop(string $table, string $constraint): void
    {
        $connection = DB::connection();
        if (! $connection instanceof MySqlConnection) {
            throw new LogicException('Check constraint migrations require MySQL or MariaDB.');
        }

        $grammar = $connection->getSchemaGrammar();
        $kind = $connection->isMaria() ? 'CONSTRAINT' : 'CHECK';

        $connection->statement('ALTER TABLE '.$grammar->wrapTable($table)
            .' DROP '.$kind.' '.$grammar->wrap($constraint));
    }
}
