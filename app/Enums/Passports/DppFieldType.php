<?php

namespace App\Enums\Passports;

enum DppFieldType: string
{
    case ShortText = 'short_text';
    case LongText = 'long_text';
    case Boolean = 'boolean';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Date = 'date';
    case Email = 'email';
    case Url = 'url';
    case CountryCode = 'country_code';
    case StringList = 'string_list';
    case MaterialList = 'material_list';
    case JsonList = 'json_list';
}
