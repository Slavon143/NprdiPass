<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Schedule;

test('invitation prune command is scheduled daily', function () {
    $events = Schedule::events();
    $found = false;

    foreach ($events as $event) {
        if ($event->command && str_contains($event->command, 'nordipass:prune-invitations')) {
            $found = true;
            expect($event->expression)->toMatch('/^0\s+\d+\s+\*+/');
            expect($event->withoutOverlapping)->toBeGreaterThan(0);

            break;
        }
    }

    expect($found)->toBeTrue();
});

test('audit prune command is scheduled daily', function () {
    $events = Schedule::events();
    $found = false;

    foreach ($events as $event) {
        if ($event->command && str_contains($event->command, 'nordipass:prune-audit-logs')) {
            $found = true;
            expect($event->expression)->toMatch('/^0\s+\d+\s+\*+/');
            expect($event->withoutOverlapping)->toBeGreaterThan(0);

            break;
        }
    }

    expect($found)->toBeTrue();
});

test('api token prune command is scheduled daily', function () {
    $events = Schedule::events();
    $found = false;

    foreach ($events as $event) {
        if ($event->command && str_contains($event->command, 'nordipass:prune-api-tokens')) {
            $found = true;
            expect($event->expression)->toMatch('/^0\s+\d+\s+\*+/');
            expect($event->withoutOverlapping)->toBeGreaterThan(0);

            break;
        }
    }

    expect($found)->toBeTrue();
});

test('failed jobs prune is scheduled daily', function () {
    $events = Schedule::events();
    $found = false;

    foreach ($events as $event) {
        if ($event->command && str_contains($event->command, 'queue:prune-failed')) {
            $found = true;
            expect($event->expression)->toMatch('/^0\s+\d+\s+\*+/');
            expect($event->withoutOverlapping)->toBeGreaterThan(0);

            break;
        }
    }

    expect($found)->toBeTrue();
});
