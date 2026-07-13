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
