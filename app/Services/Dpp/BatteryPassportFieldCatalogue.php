<?php

namespace App\Services\Dpp;

use InvalidArgumentException;

class BatteryPassportFieldCatalogue
{
    /**
     * Values are never returned. Candidate presence is not evidence of compliance.
     * No guidance row can grant publication or restricted-data access.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    public function assess(array $payload, string $category, ?string $passportUri = null): array
    {
        $categories = config('battery_passport_guidance.categories');
        $categoryIndex = array_search($category, $categories, true);
        if ($categoryIndex === false) {
            throw new InvalidArgumentException('Unsupported battery guidance category.');
        }
        $locale = data_get($payload, 'languages.default', 'en');
        $rows = config('battery_passport_guidance.points');
        foreach ($rows as &$row) {
            $row['guidance_applicability'] = $row['applicability'][$categoryIndex];
            $row['mapping'] = $row['aliases'] !== [] ? 'duplicate' : ($row['candidate_paths'] === [] ? 'unmapped' : 'candidate_only');
            $row['candidate_present'] = false;
            foreach ($row['candidate_paths'] as $path) {
                $value = $path === '@passport_uri' ? $passportUri : data_get($payload, str_replace('{locale}', (string) $locale, $path));
                if ($value !== null && $value !== '' && $value !== []) {
                    $row['candidate_present'] = true;
                }
            }
            // A country, a document or a generic value must never be a passed check.
            $row['compliance_verified'] = false;
        }
        unset($row);

        return $rows;
    }
}
