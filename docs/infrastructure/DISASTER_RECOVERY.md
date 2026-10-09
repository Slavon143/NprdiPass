# Disaster Recovery

## Recoverable state

A full NordiPass backup contains:

- the MySQL/MariaDB database, including schema, triggers, audit history, catalogue records, document metadata, and passport versions;
- `production_imports`, containing uploaded import source files required for preview, retry, and evidence;
- `product_documents`, containing every product document version;
- `catalog_media`, containing product and variant media;
- `passport_assets`, containing the copied immutable files served by published passports.

The file-store roots come from `config/filesystems.php`. The manifest records each logical disk, its configured root when the backup was created, its archive root, file count, byte count, and aggregate content checksum. Restore always targets the current environment's configured root for that logical disk.

The backup intentionally excludes logs, caches, sessions, compiled Blade views, queue runtime data outside the database, temporary files, `vendor`, `node_modules`, frontend build output, `.env`, and the generic `local` and `public` disks. Those locations have no customer-upload write path in the current application and are either runtime state or regenerable deployment output.

## Backup locations

Temporary artifacts are created under `BACKUP_TEMPORARY_PATH`, which defaults to the operating-system temporary directory under `nordipass-backups`. Final backup sets are stored on `BACKUP_DISK` under `BACKUP_PATH/<backup-id>`.

The command refuses to run if either location overlaps a persistent business-data root. This prevents the archive being generated, previous backups, or restore staging files from being included recursively.

The default `local` backup destination is `storage/app/private/nordipass/backups`. It is outside the four backed-up roots, but it remains on the application server. Production disaster recovery requires `BACKUP_DISK` to point to off-host storage.

## Create and verify a backup

```bash
php artisan nordipass:backup
php artisan nordipass:backup-verify <backup-id>
```

The version 2 manifest contains an explicit `scope` plus `database` and `files` artifacts. Backup verification checks artifact presence, exact size, SHA-256 checksum, archive structure, allowed archive paths, and every persistent store's file count, byte count, and aggregate content checksum.

Database-only and files-only backups are available for diagnostics:

```bash
php artisan nordipass:backup --database-only
php artisan nordipass:backup --files-only
```

They are not substitutes for the scheduled full backup.

## Restore procedure

1. Identify the recovery point and run `php artisan nordipass:backup-verify <backup-id>`.
2. Create and verify a pre-restore safety backup.
3. Enable maintenance mode with `php artisan down --retry=60`.
4. Stop queue workers and wait for active jobs to finish.
5. Run the full restore:

```bash
php artisan nordipass:restore <backup-id> --force --confirm-production-restore
```

6. Run `php artisan optimize:clear`.
7. Run `php artisan nordipass:restore-verify`.
8. Verify representative customer assets as described below.
9. Restart workers, verify `/up` and `/ready`, and leave maintenance mode with `php artisan up`.

Production still requires both restore flags and interactive confirmation. `--database-only` and `--files-only` are available for an explicitly limited recovery; the default restores every scope declared by the backup.

## Restore behavior

Before changing the database or file roots, restore downloads every artifact to the isolated temporary directory and validates manifest structure, size, checksum, archive paths, and per-store content metadata.

Files are extracted into staging directories beside their configured destinations. Each staged store is verified before activation. Existing roots are renamed to rollback directories, staged roots are activated, and rollback directories are removed only after every store succeeds. If activation fails, already-activated stores are rolled back.

The database restore runs before file activation. Restore prepends `CREATE DATABASE IF NOT EXISTS` and `USE` for the configured database name, so a dropped database is recreated before the dump is loaded. If the database succeeds but file activation fails, the command exits non-zero, prints `Restore failed`, rolls file roots back, and never reports a successful full restore. Keep maintenance mode enabled and rerun the restore or restore the pre-restore safety backup; MySQL restoration itself is not transactionally reversible.

Archive entries must belong to a declared `stores/<logical-disk>/` root. Absolute paths, drive-qualified paths, `.` segments, `..` segments, unknown roots, duplicate disk mappings, malformed store metadata, symlinks in source roots, and unexpected archive entries cause failure.

## Verify customer assets after restore

In addition to `nordipass:restore-verify`, verify at least one current record from each applicable class:

1. Confirm an import source path exists on `production_imports` and its SHA-256 matches `import_jobs.source_sha256`.
2. Download a product document and confirm its bytes match `product_document_versions.checksum_sha256`.
3. Load a catalogue image and confirm its bytes match `product_media.checksum_sha256`.
4. Load a public passport media/document URL and confirm its bytes match `product_passport_assets.checksum_sha256`.
5. Confirm the public passport page and QR target resolve the restored current published version.

## Failure recovery

If restore fails before the database step, application state is unchanged. If it fails after the database step, keep maintenance mode enabled. Inspect the exact failure, correct the storage or archive problem, and rerun the same restore. If that is not possible, restore the verified pre-restore safety backup before reopening the application.

Record the backup ID, recovery point, command output, duration, verified record identifiers and checksums, observed data loss, and follow-up actions in the incident record.

## Known limitations

- Persistent source disks currently must use Laravel's `local` driver because the archive is built from configured filesystem roots. The backup destination may use remote storage.
- A backup on the same server is not disaster recovery; production needs off-host backup storage.
- Backup artifacts are not encrypted by the application; protect the backup destination with provider-side encryption and access controls.
- MySQL and file activation cannot share one transaction. File roots roll back on activation failure, while a completed database import must be recovered by retrying or applying the pre-restore safety backup.
- RTO depends on database and asset volume and must be measured in staging.
