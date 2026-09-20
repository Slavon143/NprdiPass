<?php

namespace App\Services\Fortnox;

use App\Enums\Fortnox\FortnoxField;

/**
 * Implements the ADR-R3-006 source-of-truth matrix.
 */
class FortnoxSourceOfTruthMatrix
{
    public const FORTNOX = 'fortnox';

    public const NORDIPASS = 'nordipass';

    public const BIDIRECTIONAL = 'bidirectional';

    public function authority(FortnoxField $field): string
    {
        return match ($field) {
            FortnoxField::ArticleNumber, FortnoxField::Price, FortnoxField::Stock => self::FORTNOX,
            FortnoxField::Name, FortnoxField::Description, FortnoxField::Gtin => self::BIDIRECTIONAL,
        };
    }

    /**
     * Fields the sync may write into catalog columns. Price maps into the
     * product commerce payload; stock still has no catalog target.
     */
    public function isCatalogWritable(FortnoxField $field): bool
    {
        return match ($field) {
            FortnoxField::ArticleNumber, FortnoxField::Name, FortnoxField::Description, FortnoxField::Price, FortnoxField::Gtin => true,
            FortnoxField::Stock => false,
        };
    }

    /**
     * Fields that participate in conflict detection. Fortnox-authoritative
     * fields are applied without a conflict (or not applied at all).
     */
    public function isConflictCapable(FortnoxField $field): bool
    {
        return $this->authority($field) === self::BIDIRECTIONAL && $this->isCatalogWritable($field);
    }

    public function defaultResolution(FortnoxField $field): string
    {
        return $this->authority($field) === self::NORDIPASS ? self::NORDIPASS : self::FORTNOX;
    }
}
