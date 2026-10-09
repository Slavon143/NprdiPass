<?php

namespace App\Console\Commands;

use App\Actions\Fortnox\ResyncFortnoxAction;
use App\Models\Company;
use App\Models\Fortnox\FortnoxConnection;
use App\Tenancy\Contracts\CurrentCompany;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class FortnoxResyncCommand extends Command
{
    protected $signature = 'fortnox:resync
        {--company= : Company UUID to resync}
        {--mode=watermark : Resync mode: watermark (reset committed watermark) or full (also clear applied article state)}
        {--confirm : Required for full mode}';

    protected $description = 'Reset the Fortnox sync watermark for one company to force a full re-fetch';

    public function handle(
        ResyncFortnoxAction $resync,
        CurrentCompany $currentCompany,
        TokenCurrentCompany $tokenCurrentCompany,
    ): int {
        if (! (bool) config('features.fortnox_integration')) {
            $this->info('Fortnox integration is disabled; resync skipped.');

            return self::SUCCESS;
        }

        $companyUuid = (string) ($this->option('company') ?? '');
        $mode = (string) ($this->option('mode') ?? 'watermark');

        if (! Str::isUuid($companyUuid)) {
            $this->error('The --company option must be a valid company UUID.');

            return self::INVALID;
        }

        if (! in_array($mode, ['watermark', 'full'], true)) {
            $this->error('--mode must be "watermark" or "full".');

            return self::INVALID;
        }

        if ($mode === 'full' && ! (bool) $this->option('confirm')) {
            $this->error('Full resync clears applied article state and requires --confirm.');

            return self::INVALID;
        }

        $company = Company::query()->where('uuid', $companyUuid)->first();

        if ($company === null) {
            $this->error('The requested company UUID was not found.');

            return self::INVALID;
        }

        $connection = FortnoxConnection::query()->with('connectedBy')->where('company_id', $company->getKey())->first();
        $actor = $connection?->connectedBy;

        if ($connection === null || $actor === null) {
            $this->error('The company has no Fortnox connection with a connected user.');

            return self::FAILURE;
        }

        $currentCompany->clear();
        $tokenCurrentCompany->set($company);

        try {
            $resync->execute($actor, $company, $mode);

            $this->info("Fortnox resync ({$mode}) completed for company {$company->uuid}.");

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Resync failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $tokenCurrentCompany->clear();
            $currentCompany->clear();
        }
    }
}
