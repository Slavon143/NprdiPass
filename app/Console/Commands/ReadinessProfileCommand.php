<?php

namespace App\Console\Commands;

use App\Services\Passports\Readiness\ReadinessProfileRepository;
use Illuminate\Console\Command;

class ReadinessProfileCommand extends Command
{
    protected $signature = 'nordipass:readiness-profile {profile} {version=1}';

    protected $description = 'Show an immutable readiness profile definition summary.';

    public function handle(ReadinessProfileRepository $profiles): int
    {
        $profile = (string) $this->argument('profile');
        $version = (int) $this->argument('version');
        $definition = $profiles->for($profile, $version);

        $this->line(json_encode([
            'profile' => $definition->code,
            'profile_version' => $definition->version,
            'name' => $definition->name,
            'status' => $definition->status,
            'rule_set_version' => $definition->ruleSetVersion,
            'rule_set_fingerprint' => $definition->fingerprint,
            'score_algorithm' => $definition->scoreAlgorithm,
            'score_algorithm_version' => $definition->scoreAlgorithmVersion,
            'weights' => $definition->weights,
            'rules' => count($definition->ruleClasses),
            'metadata' => $definition->metadata,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
