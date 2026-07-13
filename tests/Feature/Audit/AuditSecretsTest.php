<?php

use App\Actions\Companies\AcceptCompanyInvitation;
use App\Actions\Companies\InviteCompanyMember;
use App\Actions\Companies\UpdateCompany;
use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;

test('audit storage stays free of known secrets across security relevant flows', function () {
    $knownPassword = 'KnownPassword!123';
    $newPassword = 'NewKnownPassword!456';
    $owner = User::factory()->create(['password' => $knownPassword]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'company_id' => $company,
        'user_id' => $owner,
    ]);

    $this->post('/login', [
        'email' => $owner->email,
        'password' => $knownPassword,
    ])->assertRedirect();
    app(CurrentCompany::class)->set($company);

    app(UpdateCompany::class)->execute($owner, $company, ['name' => 'Security Test Company']);
    $invitee = User::factory()->create(['email' => 'security-invitee@example.com']);
    $pending = app(InviteCompanyMember::class)->execute(
        $owner,
        $company,
        $invitee->email,
        CompanyRole::Viewer,
    );
    $knownToken = $pending->plainTextToken();
    app(AcceptCompanyInvitation::class)->execute($pending->invitation(), $invitee, $knownToken);

    $this->put(route('password.update'), [
        'current_password' => $knownPassword,
        'password' => $newPassword,
        'password_confirmation' => $newPassword,
    ])->assertSessionHasNoErrors();

    $serialized = AuditLog::query()->get([
        'description',
        'properties',
        'ip_address',
        'user_agent',
        'request_id',
    ])->toJson();
    $lower = strtolower($serialized);

    expect($serialized)->not->toContain(
        $knownPassword,
        $newPassword,
        $knownToken,
        hash('sha256', $knownToken),
    );

    foreach (['password', 'token_hash', 'authorization', 'cookie', 'session', 'client_secret'] as $forbidden) {
        expect($lower)->not->toContain('"'.$forbidden.'"');
    }
});
