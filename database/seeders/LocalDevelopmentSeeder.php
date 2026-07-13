<?php

namespace Database\Seeders;

use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class LocalDevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $users = collect([
            'superadmin@nordipass.local' => 'Super Admin',
            'owner@nordipass.local' => 'Demo Owner',
            'admin@nordipass.local' => 'Demo Admin',
            'editor@nordipass.local' => 'Demo Editor',
            'viewer@nordipass.local' => 'Demo Viewer',
            'multi@nordipass.local' => 'Multi Company User',
        ])->mapWithKeys(function (string $name, string $email): array {
            $user = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make('password'),
                    'status' => UserStatus::Active,
                    'email_verified_at' => now(),
                ],
            );

            return [$email => $user];
        });

        $demoCompany = Company::query()->updateOrCreate(
            ['name' => 'NordiPass Demo AB'],
            [
                'legal_name' => 'NordiPass Demo AB',
                'organization_number' => '559000-0001',
                'country_code' => 'SE',
                'billing_email' => 'billing.demo@nordipass.local',
                'status' => CompanyStatus::Active,
                'settings' => ['locale' => 'sv'],
            ],
        );

        $testCompany = Company::query()->updateOrCreate(
            ['name' => 'NordiPass Test AB'],
            [
                'legal_name' => 'NordiPass Test AB',
                'organization_number' => '559000-0002',
                'country_code' => 'SE',
                'billing_email' => 'billing.test@nordipass.local',
                'status' => CompanyStatus::Active,
                'settings' => ['locale' => 'sv'],
            ],
        );

        $this->syncMembership($demoCompany, $users['owner@nordipass.local'], CompanyRole::Owner);
        $this->syncMembership($demoCompany, $users['admin@nordipass.local'], CompanyRole::Admin);
        $this->syncMembership($demoCompany, $users['editor@nordipass.local'], CompanyRole::Editor);
        $this->syncMembership($demoCompany, $users['viewer@nordipass.local'], CompanyRole::Viewer);
        $this->syncMembership($demoCompany, $users['multi@nordipass.local'], CompanyRole::Viewer);
        $this->syncMembership($testCompany, $users['multi@nordipass.local'], CompanyRole::Owner);
    }

    private function syncMembership(Company $company, User $user, CompanyRole $role): void
    {
        $membership = CompanyMembership::query()->firstOrNew([
            'company_id' => $company->getKey(),
            'user_id' => $user->getKey(),
        ]);

        $membership->role = $role->value;
        $membership->joined_at ??= now();
        $membership->save();
    }
}
