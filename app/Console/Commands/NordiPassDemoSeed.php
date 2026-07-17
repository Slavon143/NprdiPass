<?php

namespace App\Console\Commands;

use Database\Seeders\NordiPassShowcaseSeeder;
use Illuminate\Console\Command;

class NordiPassDemoSeed extends Command
{
    protected $signature = 'nordipass:demo:seed {--reset : Remove existing demo data before seeding}';

    protected $description = 'Seed the NordiPass showcase demo dataset (local/testing only)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This command can only run in local or testing environment.');

            return Command::FAILURE;
        }

        if ($this->option('reset')) {
            $this->call(NordiPassDemoReset::class);

            return Command::SUCCESS;
        }

        $this->info('Seeding NordiPass showcase demo data...');

        $this->call('db:seed', ['--class' => NordiPassShowcaseSeeder::class]);

        $this->info('Showcase demo data seeded successfully.');

        return Command::SUCCESS;
    }
}
