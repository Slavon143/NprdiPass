<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('nordipass:prune-invitations')
    ->daily()
    ->withoutOverlapping(180)
    ->appendOutputTo(storage_path('logs/scheduler-prune-invitations.log'));

Schedule::command('nordipass:prune-audit-logs')
    ->daily()
    ->withoutOverlapping(180)
    ->appendOutputTo(storage_path('logs/scheduler-prune-audit.log'));

Schedule::command('nordipass:prune-api-tokens')
    ->daily()
    ->withoutOverlapping(180)
    ->appendOutputTo(storage_path('logs/scheduler-prune-api-tokens.log'));

Schedule::command('nordipass:scheduler-heartbeat')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('nordipass:backup')
    ->dailyAt('02:00')
    ->withoutOverlapping(180)
    ->appendOutputTo(storage_path('logs/scheduler-backup.log'));

Schedule::command('nordipass:backup-prune')
    ->dailyAt('04:00')
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/scheduler-backup-prune.log'));

Schedule::command('queue:prune-failed --hours=168')
    ->daily()
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/scheduler-prune-failed-jobs.log'));

Schedule::command('catalog:integrity-check --all-companies --severity=critical --fail-on=critical --format=table')
    ->dailyAt('06:00')
    ->withoutOverlapping(120)
    ->appendOutputTo(storage_path('logs/scheduler-catalog-integrity.log'));

Schedule::command('catalog:summary --all-companies --format=table')
    ->dailyAt('05:00')
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/scheduler-catalog-summary.log'));

Schedule::command('catalog:media-cleanup --all-companies --dry-run --older-than=168 --format=table')
    ->weekly()
    ->sundays()
    ->at('03:00')
    ->withoutOverlapping(120)
    ->appendOutputTo(storage_path('logs/scheduler-catalog-media-cleanup-dryrun.log'));
