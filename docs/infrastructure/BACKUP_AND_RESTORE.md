# Backup and Restore

NordiPass uses the `nordipass:backup`, `nordipass:backup-verify`, `nordipass:restore`, `nordipass:restore-verify`, and `nordipass:backup-prune` Artisan commands.

## Scope

Full backups contain the MySQL/MariaDB database and these configured local disks:

| Logical disk | Contents |
|---|---|
| `production_imports` | Uploaded import source files |
| `product_documents` | All product document versions |
| `catalog_media` | Product and variant media |
| `passport_assets` | Immutable published passport media and documents |

Disk roots are read from `config/filesystems.php`; they are not duplicated in backup configuration. Logs, caches, sessions, temporary files, build output, dependencies, `.env`, and generic `local`/`public` storage are excluded.

`BACKUP_TEMPORARY_PATH` defaults to the operating-system temporary directory. `BACKUP_DISK` and `BACKUP_PATH` select the final destination. Backup refuses to run when temporary or final local output overlaps a persistent source root.

## Commands

```bash
php artisan nordipass:backup
php artisan nordipass:backup --database-only
php artisan nordipass:backup --files-only
php artisan nordipass:backup --dry-run
php artisan nordipass:backup-verify <backup-id>

php artisan nordipass:restore <backup-id> --force --confirm-production-restore
php artisan nordipass:restore <backup-id> --database-only
php artisan nordipass:restore <backup-id> --files-only
php artisan nordipass:restore-verify

php artisan nordipass:backup-prune --dry-run
php artisan nordipass:backup-prune
```

Production restore retains the existing `--force`, `--confirm-production-restore`, and interactive confirmation guards.

## Backup set and manifest

Each full backup set contains `database.sql.gz`, `files.tar.gz`, and `manifest.json`. Manifest version 2 declares database/file scope and maps every file store by logical disk name, original root, archive root, file count, byte count, and aggregate SHA-256. Artifacts also carry exact size and SHA-256 values.

`backup-verify` rejects an incomplete or unsupported manifest, missing artifacts, size/checksum differences, malformed store mappings, unsafe archive paths, unexpected archive roots, and per-store content differences.

## Database restore

The MySQL/MariaDB artifact is decompressed and piped into the `mysql` client for the target connection. Restore prepends `CREATE DATABASE IF NOT EXISTS` and `USE` statements for the configured database name, so a dropped database is recreated from the dump. Use `--target-connection` to restore into a different configured connection.

## File restore

Restore validates all requested artifacts before importing MySQL. File entries are extracted only beneath declared `stores/<disk>/` roots into staging directories beside the current configured destinations. Staged file counts, sizes, and aggregate checksums must match the manifest. Existing roots are retained as rollback directories until all four restored roots are active and verified.

If database import succeeds and file activation then fails, restore returns non-zero and does not print a successful full-restore message. File roots are rolled back; the operator must remain in maintenance mode and retry or apply the pre-restore safety backup.

Maintenance mode is operator-controlled: run `php artisan down` before a restore and `php artisan up` after verification.

## Retention and off-host storage

Retention defaults remain seven daily, four weekly, and three monthly backup sets. The default local destination is useful for local recovery but is not disaster recovery. Production must configure a remote `BACKUP_DISK` and provider-side encryption/access controls.

See [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md) for the operational restore sequence and asset verification checklist.
