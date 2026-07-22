# R3.2 Readiness Profiles v2

## Verdict

`R3_2_ACCEPTED`

The implementation completes the core Readiness Profiles v2 foundation and closes the acceptance fixture mismatch. The `92%` Reflective Safety Vest expectation was proven stale against R2 acceptance documentation; the canonical 66-rule weighted contract remains `80%`, `336/421`, `42/3/18/1/2`. A stable `Traffic signals` deterministic acceptance fixture is now seeded and verifies at `66%`, `277/421`, `34/8/21/1/2`.

## Implemented

- `nordipass-pilot` v1 is explicitly represented as a versioned profile in `config/passport_readiness.php`.
- `ReadinessProfileRepository` resolves active/pinned profile definitions and computes deterministic SHA-256 fingerprints.
- `PassportReadinessEvaluator` evaluates rules through one resolved profile and carries the score breakdown, algorithm name/version, rule-set version, and fingerprint in the result.
- API and Blade score breakdowns use the pinned result breakdown instead of recalculating from live global weights.
- Validation runs store score algorithm, rule-set fingerprint, profile snapshot, exact weights, configured per-rule weight, source metadata, and fix-action snapshots.
- Draft and published passport versions can persist pinned readiness profile identity and fingerprint.
- Publication evidence includes algorithm name and rule-set fingerprint.
- Read-only diagnostics were added:
  - `php artisan nordipass:readiness-profile nordipass-pilot 1`
  - `php artisan nordipass:readiness-diagnose {passportUuid}`
- OpenAPI readiness response schemas now include profile/fingerprint/algorithm fields.
- `ReadinessAcceptanceFixtureSeeder` provides a shared automated/browser fixture source for canonical Reflective Safety Vest and Traffic signals readiness evidence.
- `ReadinessAcceptanceFixturesTest` asserts exact weighted arithmetic, pinned profile metadata, and stable Traffic fixture shape.

## ADR divergence

The pasted R3.2 request asks for fully database-versioned profile/rule configuration. The accepted R3.1 ADR selects a hybrid model: immutable profile definitions in config/source control and operational assignments in the database. This implementation follows the accepted ADR baseline.

## Acceptance fixture closure

| Fixture | Canonical evidence | Status |
|---|---|---|
| Reflective Safety Vest | `80%`, `336/421`, `42 passed`, `3 blockers`, `18 warnings`, `1 recommendation`, `2 N/A` | Accepted as canonical R2/R3.2 evidence; intentionally not publishable |
| Traffic signals | `66%`, `277/421`, `34 passed`, `8 blockers`, `21 warnings`, `1 recommendation`, `2 N/A` | Accepted as stable deterministic equivalent fixture; intentionally not publishable |

The stale `92%` Reflective Safety Vest expectation is rejected as acceptance truth, not forced into the fixture. The proof is recorded in `docs/release/R2_FINAL_ACCEPTANCE.md`, `docs/release/R2_TRACEABILITY_MATRIX.md`, and `docs/architecture/PASSPORT_READINESS.md`.

R3.2 is closed. R3.3 may start from this accepted baseline.
