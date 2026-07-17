<?php

namespace Tests\Feature\Api\V1\Passports;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class OpenApiSpecificationTest extends TestCase
{
    public function test_dpp_operations_are_documented(): void
    {
        $path = base_path('docs/api/openapi-v1.yaml');
        $this->assertFileExists($path, 'OpenAPI spec file not found.');
        $contents = file_get_contents($path);

        $this->assertIsString($contents);
        $this->assertStringContainsString('openapi:', $contents);

        $yaml = Yaml::parse($contents);

        $this->assertIsArray($yaml, 'Failed to parse openapi-v1.yaml as YAML.');
        $this->assertArrayHasKey('paths', $yaml, 'openapi-v1.yaml is missing paths key.');

        $passportOps = $this->collectPassportOperations($yaml['paths']);

        $this->assertNotEmpty($passportOps, 'No Passport operations documented.');
        $this->assertGreaterThanOrEqual(7, count($passportOps), sprintf(
            'Expected at least 7 DPP Passport operations, found %d: %s',
            count($passportOps),
            implode(', ', $passportOps),
        ));

        $allOperationIds = $this->collectAllOperationIds($yaml['paths']);
        $duplicates = $this->findDuplicates($allOperationIds);

        $this->assertEmpty($duplicates, sprintf(
            'Duplicate operationIds found: %s',
            implode(', ', $duplicates),
        ));
    }

    /**
     * @param  array<string, mixed>  $paths
     * @return list<string>
     */
    private function collectPassportOperations(array $paths): array
    {
        $ops = [];

        foreach ($paths as $path => $methods) {
            foreach ($methods as $method => $details) {
                if (! is_array($details)) {
                    continue;
                }

                $tags = $details['tags'] ?? [];

                if (in_array('Passports', $tags, true)) {
                    $ops[] = $details['operationId'] ?? "{$method} {$path}";
                }
            }
        }

        sort($ops);

        return $ops;
    }

    /**
     * @param  array<string, mixed>  $paths
     * @return list<string>
     */
    private function collectAllOperationIds(array $paths): array
    {
        $ids = [];

        foreach ($paths as $path => $methods) {
            foreach ($methods as $method => $details) {
                if (is_array($details) && isset($details['operationId'])) {
                    $ids[] = $details['operationId'];
                }
            }
        }

        return $ids;
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function findDuplicates(array $ids): array
    {
        $counts = array_count_values($ids);
        $duplicates = [];

        foreach ($counts as $id => $count) {
            if ($count > 1) {
                $duplicates[] = $id;
            }
        }

        sort($duplicates);

        return $duplicates;
    }
}
