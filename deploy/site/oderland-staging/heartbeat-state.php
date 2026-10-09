<?php

// Parse only a serialized ISO timestamp. Never instantiate cached objects or
// retrieve/delete expired cache entries during drain inspection.
return static function (?object $row, int $maxAge, int $now): array {
    $lastRun = null;
    $age = null;
    if ($row !== null && preg_match('/^s:([0-9]+):"([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-][0-9]{2}:[0-9]{2}))";$/D', $row->value, $match) === 1
        && (int) $match[1] === strlen($match[2])) {
        try {
            $timestamp = new DateTimeImmutable($match[2]);
            $lastRun = $match[2];
            $age = abs($now - $timestamp->getTimestamp());
        } catch (Throwable) {
            // Malformed heartbeat is unavailable, never considered fresh.
        }
    }

    return ['last_run' => $lastRun, 'age_seconds' => $age, 'expires_at' => $row->expiration ?? null,
        'fresh' => $age !== null && $age <= $maxAge && (int) $row->expiration > $now];
};
