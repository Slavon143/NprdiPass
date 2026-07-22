# ADR-R3-002 — Readiness Profiles v2 Architecture

**Status:** ACCEPTED
**Date:** 2026-07-19
**Stage:** R3.1
**Supersedes:** None (new capability)

---

## Context

R2 uses a single hardcoded readiness profile (`nordipass-pilot` v1) with 66 rules, fixed weights (blocker=10, warning=3, recommendation=1), and a config-defined rule registry. R3 requires versioned profiles, historical reproducibility, and profile-to-category binding.

## Decision

### Profile Storage Model: Hybrid (Config-Defined + Database-Managed)

**Config-Defined (Immutable Reference):**
- Profile metadata: code, version, description, schema_version_min
- Rule set: rule class references
- Weights: per-severity weight definitions
- Score algorithm: algorithm reference

**Database-Managed (Operational):**
- Profile assignments: which profile applies to a company/category
- Profile defaults: company-level default profile
- Activation history: when a profile was activated
- Fallback chain: what to use if category has no explicit assignment

### Profile Versioning
- Profiles are versioned (e.g., `nordipass-standard` v1, v2)
- Profile version is immutable once published
- New profile version = new config entry
- Validation runs record `profile`, `profile_version`, `rule_set_version`, `score_algorithm_version`

### Score Algorithm
R3 introduces configurable score algorithms:
- Default: `score = round(earned_points / applicable_points * 100)` clamped 0-100
- Future algorithms can use different formulas
- Algorithm version recorded in validation run

### Weights Configuration
```php
// config/passport_readiness.php
'profiles' => [
    'nordipass-standard' => [
        'version' => 1,
        'weights' => [
            'blocker' => 10,
            'warning' => 3,
            'recommendation' => 1,
        ],
        'algorithm' => 'weighted_percentage',
        'rules' => [/* rule classes */],
    ],
],
```

### Migration from R2 `nordipass-pilot` v1
- Existing validation runs with `nordipass-pilot` v1 remain immutable
- New profile `nordipass-standard` v1 is the R3 default
- Existing passports are assigned `nordipass-standard` v1 on first R3 evaluation
- Historical `nordipass-pilot` v1 results are retained for audit

## Alternatives Considered

1. **Fully database-managed profiles**: Rejected — rule implementation is code, so profile definitions should be in code. Database-only would allow drift between definition and implementation.
2. **Fully config-defined assignments**: Rejected — company-specific profile overrides need operational flexibility.

## Consequences

- Profile definitions are version-controlled in config
- Profile assignments are manageable in admin UI
- Historical reproducibility guaranteed by recording all version identifiers
- Safe rollback: previous profile version stays in config, new evaluation uses it
