<?php

use App\Models\CompanyInvitation;

test('prune dry run counts only old terminal invitation records without deleting', function () {
    config()->set('invitations.retention_days', 180);
    $oldAccepted = CompanyInvitation::factory()->accepted()->create([
        'accepted_at' => now()->subDays(181),
    ]);
    $oldCancelled = CompanyInvitation::factory()->cancelled()->create([
        'cancelled_at' => now()->subDays(181),
    ]);
    $oldExpired = CompanyInvitation::factory()->expired()->create([
        'expires_at' => now()->subDays(181),
    ]);
    $validPending = CompanyInvitation::factory()->pending()->create();
    $recentAccepted = CompanyInvitation::factory()->accepted()->create([
        'accepted_at' => now()->subDays(30),
    ]);
    $recentCancelled = CompanyInvitation::factory()->cancelled()->create([
        'cancelled_at' => now()->subDays(30),
    ]);
    $recentExpired = CompanyInvitation::factory()->expired()->create([
        'expires_at' => now()->subDays(30),
    ]);

    $this->artisan('nordipass:prune-invitations', ['--dry-run' => true])
        ->expectsOutput('3 invitation record(s) would be pruned.')
        ->assertSuccessful();

    expect(CompanyInvitation::query()->count())->toBe(7)
        ->and($oldAccepted->fresh())->not->toBeNull()
        ->and($oldCancelled->fresh())->not->toBeNull()
        ->and($oldExpired->fresh())->not->toBeNull()
        ->and($validPending->fresh())->not->toBeNull()
        ->and($recentAccepted->fresh())->not->toBeNull()
        ->and($recentCancelled->fresh())->not->toBeNull()
        ->and($recentExpired->fresh())->not->toBeNull();
});

test('prune command deletes old accepted cancelled and expired history only', function () {
    config()->set('invitations.retention_days', 180);
    $oldAccepted = CompanyInvitation::factory()->accepted()->create([
        'accepted_at' => now()->subDays(181),
    ]);
    $oldCancelled = CompanyInvitation::factory()->cancelled()->create([
        'cancelled_at' => now()->subDays(181),
    ]);
    $oldExpired = CompanyInvitation::factory()->expired()->create([
        'expires_at' => now()->subDays(181),
    ]);
    $validPending = CompanyInvitation::factory()->pending()->create();
    $recentHistory = CompanyInvitation::factory()->accepted()->create([
        'accepted_at' => now()->subDays(179),
    ]);

    $this->artisan('nordipass:prune-invitations')
        ->expectsOutput('3 invitation record(s) pruned.')
        ->assertSuccessful();

    expect(CompanyInvitation::query()->whereKey($oldAccepted)->exists())->toBeFalse()
        ->and(CompanyInvitation::query()->whereKey($oldCancelled)->exists())->toBeFalse()
        ->and(CompanyInvitation::query()->whereKey($oldExpired)->exists())->toBeFalse()
        ->and($validPending->fresh())->not->toBeNull()
        ->and($recentHistory->fresh())->not->toBeNull();
});
