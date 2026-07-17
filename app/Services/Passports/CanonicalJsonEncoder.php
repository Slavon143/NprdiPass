<?php

namespace App\Services\Passports;

class CanonicalJsonEncoder
{
    public function encode(array $data): string
    {
        return $this->encodeCanonical($data);
    }

    public function hash(array $data): string
    {
        return hash('sha256', $this->encodeCanonical($data));
    }

    private function encodeCanonical(array $data): string
    {
        $canonical = $this->sortRecursive($data);

        return json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $v) => $this->sortRecursive($v), $value);
        }

        ksort($value);

        return array_map(fn (mixed $v) => $this->sortRecursive($v), $value);
    }
}
