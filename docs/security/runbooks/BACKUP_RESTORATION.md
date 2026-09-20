# Runbook: Backup Restoration

Status: R3.16 deliverable. Uses the `nordipass:backup`, `nordipass:backup-prune`, `nordipass:backup-verify` commands scheduled in `routes/console.php`.

## Preconditions

- Backups run daily (`nordipass:backup` at 02:00) to the configured backup disk.
- `nordipass:backup-verify` confirms backup integrity; schedule verification before relying on restores.

## Restore Procedure

1. **Stop writes** — put the application in maintenance mode: `php artisan down`.
2. **Snapshot the current database** (pre-restore rollback point) even if suspected corrupted.
3. **Restore the database** from the chosen backup using the deployment tooling (mysqldump-based restore).
4. **Restore storage** (catalog media, document versions) from the same backup set — database and files must come from the same point in time.
5. **Run migrations**: `php artisan migrate --force` (expand → backfill → switch contract; never a destructive migration).
6. **Verify**:
   - `php artisan migrate:status`
   - `php artisan catalog:integrity-check --all-companies --severity=critical`
   - `php artisan nordipass:backup-verify`
   - `php artisan test tests/Feature/Security` (tenant isolation intact)
7. **Bring the application back**: `php artisan up`, then confirm health endpoints (`/up`, `/ready`).

## Verification Checklist (required before declaring restore successful)

- [ ] No tenant sees another tenant's data (cross-tenant sweep green)
- [ ] Published passport snapshots unchanged (immutability preserved)
- [ ] Queue workers resume (`queue:work`) with failed jobs recoverable via the job center
- [ ] Stripe/Fortnox integrations functional after restore

## Failure Handling

- A failed restore must be rolled back to the pre-restore snapshot; never leave the database in a half-restored state.
- Record every restore attempt in `platform_incidents` with the backup timestamp used.
