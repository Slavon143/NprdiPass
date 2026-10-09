<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Passports\ProductPassportAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PassportAssetsScanCommand extends Command
{
    protected $signature = 'passports:scan-assets
        {--company= : Company UUID to scan}
        {--all-companies : Scan every company}
        {--limit=5000 : Maximum number of assets to check}
        {--format=table : Output format (table or json)}';

    protected $description = 'Verify that every published passport asset physically exists and matches its recorded checksum';

    public function handle(): int
    {
        $companyUuid = (string) ($this->option('company') ?? '');
        $allCompanies = (bool) $this->option('all-companies');

        if ($companyUuid === '' && ! $allCompanies) {
            $this->error('You must specify either --company or --all-companies.');

            return self::INVALID;
        }

        $format = (string) ($this->option('format') ?? 'table');
        if (! in_array($format, ['table', 'json'], true)) {
            $format = 'table';
        }

        $query = ProductPassportAsset::query();

        if ($companyUuid !== '') {
            if (! Str::isUuid($companyUuid)) {
                $this->error('The --company option must be a valid company UUID.');

                return self::INVALID;
            }

            $company = Company::query()->where('uuid', $companyUuid)->first();

            if ($company === null) {
                $this->error('The requested company UUID was not found.');

                return self::INVALID;
            }

            $query->where('company_id', $company->getKey());
        }

        $disk = Storage::disk('passport_assets');
        $assets = $query->orderBy('id')->limit(max(1, min(100000, (int) $this->option('limit'))))->get();

        $rows = [];

        foreach ($assets as $asset) {
            $status = 'ok';

            if (! $disk->exists($asset->storage_key)) {
                $status = 'missing';
            } elseif (hash('sha256', (string) $disk->get($asset->storage_key)) !== $asset->checksum_sha256) {
                $status = 'mismatch';
            }

            $rows[] = [
                'company_uuid' => Company::query()->whereKey($asset->company_id)->value('uuid'),
                'asset_uuid' => $asset->uuid,
                'storage_key' => $asset->storage_key,
                'status' => $status,
            ];
        }

        $counts = array_count_values(array_column($rows, 'status'));
        $ok = (int) ($counts['ok'] ?? 0);
        $missing = (int) ($counts['missing'] ?? 0);
        $mismatch = (int) ($counts['mismatch'] ?? 0);

        if ($format === 'json') {
            $this->line(json_encode([
                'checked' => count($rows),
                'ok' => $ok,
                'missing' => $missing,
                'mismatch' => $mismatch,
                'findings' => array_values(array_filter($rows, fn (array $row): bool => $row['status'] !== 'ok')),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Company', 'Asset', 'Storage key', 'Status'], array_map(
                fn (array $row): array => [$row['company_uuid'], $row['asset_uuid'], $row['storage_key'], $row['status']],
                $rows,
            ));

            $this->line(sprintf('Checked: %d, OK: %d, Missing: %d, Checksum mismatch: %d', count($rows), $ok, $missing, $mismatch));
        }

        return $missing + $mismatch === 0 ? self::SUCCESS : self::FAILURE;
    }
}
