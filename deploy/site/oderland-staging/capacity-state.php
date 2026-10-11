<?php

// A backup must preserve the application's valid lazy NULL counters. This
// inspection must not invoke reconciliation, which can append audit rows even
// without --repair. SQL supplies tenant-scoped actual cardinalities.
return static function (iterable $rows): array {
    $result = ['valid' => true, 'companies' => 0, 'uninitialized_managed' => 0,
        'uninitialized_total' => 0, 'missing_rows' => 0, 'drift_rows' => 0];
    $unsigned = static fn (mixed $value): bool => (is_int($value) || is_string($value))
        && preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value) === 1;
    foreach ($rows as $row) {
        $result['companies']++;
        if (! isset($row->capacity_company_id)) {
            $result['missing_rows']++;
            $result['valid'] = false;

            continue;
        }
        $drift = false;
        foreach (['managed', 'total'] as $kind) {
            $stored = 'stored_'.$kind;
            $actual = 'actual_'.$kind;
            if (! property_exists($row, $stored) || ! property_exists($row, $actual) || ! $unsigned($row->$actual)) {
                $drift = true;
            } elseif ($row->$stored === null) {
                $result['uninitialized_'.$kind]++;
            } elseif (! $unsigned($row->$stored) || (string) $row->$stored !== (string) $row->$actual) {
                $drift = true;
            }
        }
        if ($drift) {
            $result['drift_rows']++;
            $result['valid'] = false;
        }
    }

    return $result;
};
