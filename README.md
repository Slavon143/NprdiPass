# NordiPass

NordiPass is a Laravel application. The repository currently contains the R0 Foundation Stage 1 bootstrap and Stage 2 core database: Blade authentication, development tooling, companies, memberships, and invitation persistence. Tenant context and authorization are intentionally deferred to later stages.

## Requirements

- PHP 8.4 or newer with `fileinfo`, `pdo_mysql`, and `pdo_sqlite` for the test suite
- Composer 2
- Node.js 22 or newer with npm
- MySQL 8 or a modern MariaDB release

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create the local MySQL database:

```sql
CREATE DATABASE nordipass CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Set the local MySQL username and password in `.env`. Never commit that file.

Install frontend dependencies, build the assets, and initialize the database:

```bash
npm install
npm run build
php artisan migrate --seed
```

Start the application and the Vite development server in separate terminals:

```bash
php artisan serve
npm run dev
```

The application is available at `http://localhost:8000`. Register a local user or sign in through the Laravel Breeze authentication routes. Local seed users use the password `password`.

## Quality checks

```bash
composer test
composer lint
composer analyse
composer quality
```

The individual equivalent commands are:

```bash
php artisan test
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
```

On Windows, Composer scripts are the portable way to run tools from `vendor/bin`.

## Stack

- Laravel 13 with Blade
- Laravel Breeze authentication
- Laravel Sanctum
- Tailwind CSS and Alpine.js
- Vite
- Pest
- Laravel Pint
- PHPStan with Larastan
