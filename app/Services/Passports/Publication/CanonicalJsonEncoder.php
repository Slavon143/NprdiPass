<?php

namespace App\Services\Passports\Publication;

class CanonicalJsonEncoder
{
    public function encode(array $data): string
    {
        $canonical = $this->sortKeysRecursive($data);

        return json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private function sortKeysRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);

        if ($isList) {
            return array_map(fn ($item) => $this->sortKeysRecursive($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn ($item) => $this->sortKeysRecursive($item), $value);
    }
}
