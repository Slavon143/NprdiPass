<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function (Model $model): void {
            $uuid = $model->getAttribute('uuid');

            if ($uuid === null || $uuid === '') {
                $model->setAttribute('uuid', Str::uuid()->toString());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
