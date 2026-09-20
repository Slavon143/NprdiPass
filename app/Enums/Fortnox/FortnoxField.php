<?php

namespace App\Enums\Fortnox;

enum FortnoxField: string
{
    case ArticleNumber = 'article_number';
    case Name = 'name';
    case Description = 'description';
    case Price = 'price';
    case Stock = 'stock';
    case Gtin = 'gtin';

    public function label(): string
    {
        return match ($this) {
            self::ArticleNumber => 'Article number',
            self::Name => 'Name',
            self::Description => 'Description',
            self::Price => 'Price',
            self::Stock => 'Stock',
            self::Gtin => 'GTIN/EAN',
        };
    }
}
