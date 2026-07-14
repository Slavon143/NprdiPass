# Environments

## Overview

NordiPass supports four environments: local, testing, staging, and production. Each has its own configuration requirements.

## Environment table

| Setting | Local | Testing | Staging | Production |
|---|---|---|---|---|
| `APP_ENV` | `local` | `testing` | `staging` | `production` |
| `APP_DEBUG` | `true` | `true` | `false` | `false` |
| `APP_URL` | `http://localhost:8000` | — | `https://staging.example.com` | `https://your-domain.example` |
| `APP_TIMEZONE` | `UTC` | `UTC` | `UTC` | `UTC` |
| `DB_CONNECTION` | `mysql` | `mysql` | `mysql` | `mysql` |
| `CACHE_STORE` | `database` | `array` | `redis` | `redis` |
| `QUEUE_CONNECTION` | `database` | `sync` | `redis` | `redis` |
| `SESSION_DRIVER` | `database` | `array` | `redis` | `redis` |
| `MAIL_MAILER` | `log` | `array` | `smtp` | `smtp` |
| `FILESYSTEM_DISK` | `local` | `local` | `local` | `local` |
| `SESSION_SECURE_COOKIE` | `false` | `false` | `true` | `true` |
| `LOG_LEVEL` | `debug` | `debug` | `warning` | `warning` |

## Local

The local environment uses the database queue driver and database cache driver. No external services (Redis, SMTP) are required.

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Mail can use the `log` mailer for development. For Mailpit, set:

```env
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
```

## Testing

The default test suite (`phpunit.xml`) uses the dedicated MySQL database `nordipass_testing` with array cache, sync queue, and array session. The test bootstrap rejects any non-MySQL connection or database name without the `_testing` suffix.

```sql
CREATE DATABASE nordipass_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
php artisan test
```

## Staging

Staging mirrors production configuration but may use relaxed rate limits and non-production URLs.

## Production

Production must use Redis for cache, queue, and sessions. The filesystem default is `local`. An S3-compatible disk can be added later if document storage requires it (Laravel Filesystem abstraction).

### APP_KEY lifecycle

`APP_KEY` is generated **once** per environment. It must never be regenerated on each deployment because doing so invalidates:

- sessions stored server-side (database, Redis);
- encrypted cookies;
- any encrypted application data.

```bash
php artisan key:generate  # run once during initial setup only
```

### Secrets

The `.env` file must never be committed to version control. On Linux production:

- `.env` must not be accessible as a public file (document root is `public/`);
- `.env` must have restricted filesystem permissions (`chmod 600` or `640`);
- Web server must be configured with `public/` as the document root.

Never use `chmod -R 777`.

### Demo seeders

The `DatabaseSeeder` runs `LocalDevelopmentSeeder` only when `APP_ENV` is `local` or `testing`. In production, only `RolePermissionSeeder` is executed. Demo users (`owner@nordipass.local` etc.) are never created in production.

### Redis

Redis is a production recommendation but not a hard runtime dependency. The application continues to work with the database fallback driver for local development.

### Mail

For production invitation emails, configure a trusted SMTP provider:

```env
MAIL_MAILER=smtp
INVITATION_MAILER=smtp
```

Do not use the `log` mailer for invitation emails in production, as it would expose secret acceptance URLs in application logs.
