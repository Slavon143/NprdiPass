# NordiPass

NordiPass is a Laravel application. This repository currently contains the R0 Foundation Stage 1 bootstrap: Blade authentication, the frontend toolchain, and development quality checks. Product and tenant modules are intentionally not part of this stage.

## Requirements

- PHP 8.4 or newer with `fileinfo` and `pdo_sqlite`
- Composer 2
- Node.js 22 or newer with npm
- SQLite

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create the SQLite database file:

```bash
touch database/database.sqlite
```

On Windows PowerShell, use:

```powershell
New-Item database/database.sqlite -ItemType File -Force
```

Install frontend dependencies, build the assets, and initialize the database:

```bash
npm install
npm run build
php artisan migrate
```

Start the application and the Vite development server in separate terminals:

```bash
php artisan serve
npm run dev
```

The application is available at `http://localhost:8000`. Register a local user or sign in through the Laravel Breeze authentication routes.

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
