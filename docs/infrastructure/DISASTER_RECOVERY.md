# Disaster Recovery

## Incident classification

Before restoring from backup, confirm that the issue requires it:

| Severity | Example | Action |
|----------|---------|--------|
| Data corruption | Accidental DELETE or UPDATE | Restore from latest verified backup |
| Data loss | Table dropped | Restore from latest verified backup |
| Configuration error | Wrong migration run | Restore or rollback migration |
| Hardware failure | Server disk failure | Provision new server, restore backup |
| Security incident | Unauthorised data access | Incident response first, restore if needed |

## Decision to restore

1. Confirm the scope of data loss / corruption
2. Determine the latest acceptable point-in-time
3. Locate the corresponding verified backup
4. Confirm that the backup contains the required data
5. Decide: restore entire backup or selective data (if possible)

## Locating the latest verified backup

```bash
# List backup directories
ls -la storage/app/private/nordipass/backups/

# View manifest for each
cat storage/app/private/nordipass/backups/<backup-id>/manifest.json

# Verify
php artisan nordipass:backup-verify <backup-id>
```

## Pre-restore checklist

- [ ] Latest verified backup identified
- [ ] Checksums match
- [ ] Pre-restore safety backup created
- [ ] Maintenance mode ready
- [ ] Queue workers can be stopped
- [ ] Operator has typed confirmation available

## Restore procedure

### 1. Create a pre-restore safety backup

```bash
php artisan nordipass:backup
```

If this fails, the operator receives a warning and must explicitly confirm to continue.

### 2. Enable maintenance mode

```bash
php artisan down --retry=60
```

### 3. Stop queue workers

```bash
php artisan queue:restart
# Wait for workers to finish their current job
# Or: Supervisor: supervisorctl stop nordipass-worker:*
```

### 4. Restore database

```bash
php artisan nordipass:restore <backup-id> --force --confirm-production-restore
```

### 5. Restore files (if needed)

Files are restored as part of the full backup. For a database-only restore, files are not touched.

### 6. Clear caches

```bash
php artisan optimize:clear
```

### 7. Verify restore

```bash
php artisan nordipass:restore-verify
```

### 8. Restart workers

```bash
php artisan queue:restart
# Supervisor: supervisorctl start nordipass-worker:*
```

### 9. Disable maintenance mode

```bash
php artisan up
```

### 10. Verify application

```bash
curl -f http://localhost/up
curl -f http://localhost/ready
```

## Rollback

If the restore introduces new issues:

1. Identify the problem (wrong backup, driver mismatch, etc.)
2. Restore the pre-restore safety backup created in step 1
3. Verify: `php artisan nordipass:restore-verify`

## Incident documentation

After the incident:

- What happened and when
- What data was affected
- Which backup was used
- Restore duration
- Any data loss
- Root cause analysis
- Preventive measures
