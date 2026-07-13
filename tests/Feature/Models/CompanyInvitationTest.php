<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

test('invitation belongs to a company and inviter and casts its role', function () {
    $company = Company::factory()->create();
    $inviter = User::factory()->create();
    $invitation = CompanyInvitation::factory()->create([
        'company_id' => $company->id,
        'invited_by' => $inviter->id,
        'role' => CompanyRole::Editor,
    ]);

    expect($invitation->company->is($company))->toBeTrue()
        ->and($invitation->inviter->is($inviter))->toBeTrue()
        ->and($invitation->role)->toBe(CompanyRole::Editor);
});

test('invitation state methods distinguish pending expired and accepted invitations', function () {
    $pending = CompanyInvitation::factory()->pending()->create();
    $expired = CompanyInvitation::factory()->expired()->create();
    $accepted = CompanyInvitation::factory()->accepted()->create();

    expect($pending->isPending())->toBeTrue()
        ->and($pending->isExpired())->toBeFalse()
        ->and($pending->isAccepted())->toBeFalse()
        ->and($expired->isExpired())->toBeTrue()
        ->and($expired->isPending())->toBeFalse()
        ->and($accepted->isAccepted())->toBeTrue()
        ->and($accepted->isPending())->toBeFalse();
});

test('invitation serialization hides the token hash and has no raw token field', function () {
    $invitation = CompanyInvitation::factory()->create();

    expect($invitation->toArray())->not->toHaveKey('token_hash')
        ->and($invitation->getAttributes())->not->toHaveKey('token')
        ->and(Schema::hasColumn('company_invitations', 'token'))->toBeFalse();
});
