<?php

namespace App\Enums\Passports;

enum ProductPassportAssetKind: string
{
    case ProductMedia = 'product_media';
    case VariantMedia = 'variant_media';
    case Document = 'document';
}
