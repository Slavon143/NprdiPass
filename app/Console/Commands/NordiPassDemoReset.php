<?php

namespace App\Console\Commands;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportAsset;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class NordiPassDemoReset extends Command
{
    protected $signature = 'nordipass:demo:reset';

    protected $description = 'Remove NordiPass Demo AB showcase data (local/testing only)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This command can only run in local or testing environment.');

            return Command::FAILURE;
        }

        $company = Company::query()->where('name', 'NordiPass Demo AB')->first();

        if (! $company instanceof Company) {
            $this->info('No demo company found.');

            return Command::SUCCESS;
        }

        $this->info('Removing demo data for NordiPass Demo AB...');

        // Step 1: Clean catalog data within transaction
        DB::transaction(function () use ($company): void {
            $productIds = Product::query()->forCompany($company)->pluck('id')->toArray();
            $variantIds = ProductVariant::query()->forCompany($company)
                ->whereIn('product_id', $productIds)->pluck('id')->toArray();

            DB::table('category_product')->whereIn('product_id', $productIds)->delete();

            DB::table('product_attribute_value_options')->whereIn('product_attribute_value_id',
                DB::table('product_attribute_values')->whereIn('product_id', $productIds)->pluck('id')->toArray()
            )->delete();
            DB::table('product_attribute_values')->whereIn('product_id', $productIds)->delete();

            DB::table('variant_attribute_value_options')->whereIn('variant_attribute_value_id',
                DB::table('variant_attribute_values')->whereIn('product_variant_id', $variantIds)->pluck('id')->toArray()
            )->delete();
            DB::table('variant_attribute_values')->whereIn('product_variant_id', $variantIds)->delete();

            ProductMedia::query()->whereIn('product_id', $productIds)->delete();
            ProductMedia::query()->whereIn('product_variant_id', $variantIds)->delete();

            foreach (ProductDocument::query()->whereIn('product_id', $productIds)->get() as $doc) {
                $doc->versions()->delete();
                $doc->delete();
            }

            ProductVariant::query()->whereIn('id', $variantIds)->delete();
            Product::query()->forCompany($company)->delete();
        });

        // Step 2: Clean passport data (outside transaction to handle trigger manipulation)
        $passportIds = ProductPassport::query()->forCompany($company)->pluck('id')->toArray();
        if ($passportIds !== []) {
            ProductPassport::query()->forCompany($company)->update([
                'current_draft_version_id' => null,
                'current_published_version_id' => null,
            ]);

            ProductPassportAsset::query()->whereIn('passport_id', $passportIds)->delete();

            DB::unprepared('DROP TRIGGER IF EXISTS product_passport_versions_prevent_published_delete');

            try {
                ProductPassportVersion::query()->whereIn('passport_id', $passportIds)->delete();
                ProductPassport::query()->forCompany($company)->delete();
            } finally {
                DB::unprepared(<<<'SQL'
                    CREATE TRIGGER product_passport_versions_prevent_published_delete
                    BEFORE DELETE ON product_passport_versions
                    FOR EACH ROW
                    BEGIN
                        IF OLD.status <> 'draft' THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published, superseded, and withdrawn passport versions cannot be deleted.';
                        END IF;
                    END
                SQL);
            }
        }

        // Step 3: Clean storage
        Storage::disk('catalog_media')->deleteDirectory($company->uuid);
        Storage::disk('product_documents')->deleteDirectory($company->uuid);
        Storage::disk('passport_assets')->deleteDirectory($company->uuid);

        // Step 4: Clean memberships
        foreach (['demo.owner', 'demo.admin', 'demo.editor', 'demo.viewer'] as $prefix) {
            $user = User::query()->where('email', "{$prefix}@nordipass.test")->first();
            if ($user) {
                CompanyMembership::query()->where('user_id', $user->getKey())->delete();
            }
        }

        $this->info('Demo data removed successfully.');

        return Command::SUCCESS;
    }
}
