<?php

use App\Services\Dpp\BatteryPassportFieldCatalogue;

test('battery guidance keeps the category differences and duplicates without granting compliance', function () {
    $catalogue = app(BatteryPassportFieldCatalogue::class);
    $ev = $catalogue->assess([], 'ev_battery');
    $lmt = $catalogue->assess([], 'lmt_battery');
    $industrial = $catalogue->assess([], 'industrial_battery');
    expect(array_keys($ev))->toBe(range(1, 71))
        ->and($ev[61]['guidance_applicability'])->toBe('mandatory')
        ->and($lmt[61]['guidance_applicability'])->toBe('not_displayed')
        ->and($ev[62]['guidance_applicability'])->toBe('not_displayed')
        ->and($lmt[62]['guidance_applicability'])->toBe('mandatory')
        ->and($industrial[62]['guidance_applicability'])->toBe('conditional')
        ->and($industrial[31]['guidance_applicability'])->toBe('conditional')
        ->and($ev[16]['aliases'])->toBe([12, 13, 15])
        ->and($ev[25]['aliases'])->toBe([11])
        ->and($ev[51]['aliases'])->toBe([])
        ->and($ev[51]['access_domain'])->toBe('legitimate_interest_dynamic')
        ->and($ev[44]['guidance_applicability'])->toBe('deferred_in_guidance');
    foreach ($ev as $row) {
        expect($row['compliance_verified'])->toBeFalse();
    }
});

test('candidate data presence never substitutes capacity units, manufacturer address or compliance evidence', function () {
    $payload = ['languages' => ['default' => 'sv'], 'regulatory_context' => ['battery_capacity_kwh' => 50],
        'translations' => ['sv' => ['manufacturer_and_operator' => ['manufacturer_display_name' => 'Synthetic maker']]],
        'data' => ['manufacturer_and_operator' => ['responsible_operator_address' => 'Private operator address'],
            'origin_and_traceability' => ['manufacturing_countries' => ['SE']]]];
    $rows = app(BatteryPassportFieldCatalogue::class)->assess($payload, 'ev_battery', 'https://example.com/p/test');
    expect($rows[1]['candidate_present'])->toBeTrue()
        ->and($rows[3]['candidate_present'])->toBeTrue()
        ->and($rows[3]['compliance_verified'])->toBeFalse()
        ->and($rows[4]['mapping'])->toBe('unmapped')
        ->and($rows[8]['mapping'])->toBe('candidate_only')
        ->and($rows[11]['candidate_present'])->toBeFalse()
        ->and($rows[51]['candidate_present'])->toBeFalse()
        ->and(json_encode($rows))->not->toContain('Private operator address', 'Synthetic maker');
});
