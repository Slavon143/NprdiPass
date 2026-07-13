<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeployCheckCommand extends Command
{
    protected $signature = 'nordipass:deploy-check';

    protected $description = 'Pre-flight check for production deployment readiness';

    public function handle(): int
    {
        $checks = [];
        $isProduction = config('app.env') === 'production';

        $checks[] = $this->check('APP_ENV is production', function () use ($isProduction) {
            return $isProduction;
        }, ! $isProduction);

        $checks[] = $this->check('APP_DEBUG is false', function () use ($isProduction) {
            return ! $isProduction || config('app.debug') === false;
        });

        $checks[] = $this->check('APP_KEY is configured', function () {
            $key = config('app.key');

            return $key !== null && $key !== '';
        });

        $checks[] = $this->check('APP_URL is configured', function () use ($isProduction) {
            if (! $isProduction) {
                return true;
            }
            $url = config('app.url');

            return $url !== null && $url !== '' && $url !== 'http://localhost';
        });

        $checks[] = $this->check('Database connection works', function () {
            try {
                DB::select('SELECT 1');

                return true;
            } catch (\Throwable) {
                return false;
            }
        });

        $checks[] = $this->check('Migrations table exists', function () {
            try {
                DB::select('SELECT 1 FROM migrations LIMIT 1');

                return true;
            } catch (\Throwable) {
                return false;
            }
        });

        $checks[] = $this->check('Storage directory is writable', function () {
            $path = storage_path();

            return is_dir($path) && is_writable($path);
        });

        $checks[] = $this->check('Bootstrap cache is writable', function () {
            $path = base_path('bootstrap/cache');

            return is_dir($path) && is_writable($path);
        });

        $checks[] = $this->check('Session secure cookie is enabled', function () use ($isProduction) {
            return ! $isProduction || config('session.secure', false) === true;
        });

        $checks[] = $this->check('Queue connection is not sync when async required', function () {
            if (! config('health.require_async_queue', false)) {
                return true;
            }

            return config('queue.default') !== 'sync';
        });

        $checks[] = $this->check('Trusted hosts are configured', function () use ($isProduction) {
            if (! $isProduction) {
                return true;
            }
            $hosts = config('security.trusted_hosts', '');

            if ($hosts === '' || $hosts === 'localhost,127.0.0.1') {
                return false;
            }

            return true;
        });

        $checks[] = $this->check('Trusted proxies are configured', function () use ($isProduction) {
            if (! $isProduction) {
                return true;
            }
            $proxies = config('security.trusted_proxies', '');

            return $proxies !== '' && $proxies !== '*';
        });

        $checks[] = $this->check('Health is enabled', function () {
            return config('health.enabled', true) === true;
        });

        $checks[] = $this->check('Scheduler heartbeat is required in production', function () use ($isProduction) {
            return ! $isProduction || config('health.require_scheduler', false) === true;
        });

        $checks[] = $this->check('Backup freshness', function () {
            $enabled = config('backup.enabled', true);
            $disk = config('backup.disk', 'local');
            $path = config('backup.path', 'nordipass/backups');

            if (! $enabled) {
                return true;
            }

            try {
                $files = Storage::disk($disk)->files($path);

                if (empty($files)) {
                    return true;
                }

                $manifests = array_filter($files, fn ($f) => str_ends_with($f, 'manifest.json'));
                if (empty($manifests)) {
                    return true;
                }

                rsort($manifests);
                $latestManifest = $manifests[0];
                $content = Storage::disk($disk)->get($latestManifest);
                $data = json_decode($content, true);

                if (! is_array($data) || ! isset($data['created_at'])) {
                    return true;
                }

                $createdAt = strtotime($data['created_at']);
                $maxAge = config('backup.max_age_hours', 24) * 3600;
                $age = time() - $createdAt;

                return $age <= $maxAge;
            } catch (\Throwable) {
                return true;
            }
        });

        $this->line('');

        return $this->summarize($checks);
    }

    private function check(string $label, callable $callback, bool $skipped = false): array
    {
        $start = microtime(true);
        $passed = $skipped ? true : $callback();
        $duration = (int) ((microtime(true) - $start) * 1000);

        $marker = $skipped ? '-' : ($passed ? '✓' : '✗');
        $suffix = $skipped ? ' (skipped)' : '';
        $this->line("  {$marker} {$label}{$suffix} ({$duration}ms)");

        return ['label' => $label, 'passed' => $passed];
    }

    private function summarize(array $checks): int
    {
        $passed = count(array_filter($checks, fn ($c) => $c['passed']));
        $failed = count($checks) - $passed;

        $this->newLine();
        $this->line("Checked: {$passed} passed, {$failed} failed.");

        return $failed === 0 ? 0 : 1;
    }
}
