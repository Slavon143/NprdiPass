<?php

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

test('public models receive unique UUIDs automatically', function () {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $company = Company::factory()->create();
    $invitation = CompanyInvitation::factory()->create();

    expect(Str::isUuid($firstUser->uuid))->toBeTrue()
        ->and(Str::isUuid($company->uuid))->toBeTrue()
        ->and(Str::isUuid($invitation->uuid))->toBeTrue()
        ->and($firstUser->uuid)->not->toBe($secondUser->uuid)
        ->and($firstUser->getRouteKeyName())->toBe('uuid');
});

test('an explicitly assigned UUID is not overwritten', function () {
    $uuid = Str::uuid()->toString();
    $user = User::factory()->make();
    $user->uuid = $uuid;
    $user->save();

    expect($user->uuid)->toBe($uuid);
});

test('the database rejects duplicate UUIDs', function () {
    $uuid = Str::uuid()->toString();
    $firstUser = User::factory()->make();
    $firstUser->uuid = $uuid;
    $firstUser->save();

    expect(function () use ($uuid): void {
        $secondUser = User::factory()->make();
        $secondUser->uuid = $uuid;
        $secondUser->save();
    })->toThrow(QueryException::class);
});
