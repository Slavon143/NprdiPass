<?php

namespace App\Services\Imports;

use App\Enums\Imports\ImportNumberFormat;
use App\Exceptions\Catalog\AttributeOperationException;

class ImportDecimalNormalizer
{
    public function normalize(string $value, ImportNumberFormat $format): string
    {
        $separator = $format === ImportNumberFormat::Comma ? ',' : '.';
        if (preg_match('/^-?\d{1,16}(?:'.preg_quote($separator, '/').'\d{1,4})?$/D', $value) !== 1) {
            throw AttributeOperationException::invalid('value', __('imports.invalid_decimal'));
        }

        return str_replace($separator, '.', $value);
    }
}
