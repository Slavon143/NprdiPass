# Readiness Profiles v2

## Status

R3.2 implementation in progress. This document records the implemented contract and remaining acceptance evidence.

## Profile lifecycle

NordiPass follows the R3.1 ADR hybrid model:

- immutable profile definitions live in `config/passport_readiness.php`;
- operational assignment storage is reserved for later admin workflows;
- `nordipass-pilot` is preserved as profile version 1;
- active and deprecated profile versions must remain in source control for historical reproduction.

Profile version 1 keeps the pilot weights:

| Severity | Weight |
|---|---:|
| blocker | 10 |
| warning | 3 |
| recommendation | 1 |

The score algorithm is `weighted_ratio` version `1`.

## Fingerprint

Each resolved profile version has a SHA-256 rule-set fingerprint built from:

- profile code and version;
- rule-set version;
- score algorithm name and version;
- severity weights;
- registered PHP rule class, stable rule code, group, severity, enabled state, and sort order.

Changing a rule, severity, weight, rule order, algorithm, or profile version changes the fingerprint. Validation and publication evidence stores the fingerprint used at evaluation time.

## Evaluation

`ReadinessContextBuilder` resolves a profile for the current draft. New drafts pin the active profile fields on `product_passport_versions`; legacy drafts without those fields fall back to the configured active profile. `PassportReadinessEvaluator` evaluates one registry against one resolved profile and stores the computed `ReadinessScoreBreakdown` in the result. API and Blade consumers read that breakdown rather than recalculating with live config.

## Publication evidence

Publication re-evaluates readiness server-side inside the existing transaction and stores:

- validation run UUID;
- profile code and version;
- schema version and draft revision;
- rule-set version and fingerprint;
- score algorithm name and version;
- weights, earned/applicable points, score, status, and source checksum.

Published versions also carry the pinned profile fields. MySQL triggers protect published evidence during lifecycle transitions.

## Historical limitations

Existing historical runs created before this expansion may not have a rule-set fingerprint or full profile snapshot. The migration preserves those rows and does not invent missing evidence. Known stored values such as profile, version, score, weights, counts, and source checksum remain authoritative.
