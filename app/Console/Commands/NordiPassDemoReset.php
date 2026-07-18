<?php

namespace App\Console\Commands;

use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
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

        $isMysql = DB::getDriverName() === 'mysql';

        if ($isMysql) {
            DB::unprepared('DROP TRIGGER IF EXISTS product_passport_assets_prevent_published_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS product_passport_versions_prevent_published_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS product_document_versions_prevent_delete');
        }

        try {
            DB::transaction(function () use ($company): void {
                $productIds = Product::query()->withTrashed()->forCompany($company)->pluck('id')->toArray();
                $variantIds = ProductVariant::query()->withTrashed()->forCompany($company)
                    ->whereIn('product_id', $productIds)->pluck('id')->toArray();
                $passportIds = ProductPassport::query()->forCompany($company)->pluck('id')->toArray();
                $documentIds = ProductDocument::query()->whereIn('product_id', $productIds)->pluck('id')->toArray();

                if ($passportIds !== []) {
                    ProductPassport::query()->forCompany($company)->update([
                        'current_draft_version_id' => null,
                        'current_published_version_id' => null,
                    ]);

                    ProductPassportAsset::query()->whereIn('passport_id', $passportIds)->delete();
                    ProductPassportVersion::query()->whereIn('passport_id', $passportIds)->delete();
                    ProductPassport::query()->forCompany($company)->delete();
                }

                if ($documentIds !== []) {
                    ProductDocument::query()->whereIn('id', $documentIds)->update(['current_version_id' => null]);
                    ProductDocumentVersion::query()->whereIn('document_id', $documentIds)->delete();
                    ProductDocument::query()->whereIn('id', $documentIds)->delete();
                }

                DB::table('category_product')->whereIn('product_id', $productIds)->delete();

                DB::table('product_attribute_value_options')->whereIn('product_attribute_value_id',
                    DB::table('product_attribute_values')->whereIn('product_id', $productIds)->pluck('id')->toArray()
                )->delete();
                DB::table('product_attribute_values')->whereIn('product_id', $productIds)->delete();

                DB::table('variant_attribute_value_options')->whereIn('variant_attribute_value_id',
                    DB::table('variant_attribute_values')->whereIn('product_variant_id', $variantIds)->pluck('id')->toArray()
                )->delete();
                DB::table('variant_attribute_values')->whereIn('product_variant_id', $variantIds)->delete();

                Product::query()->withTrashed()->whereIn('id', $productIds)->update([
                    'default_variant_id' => null,
                    'primary_media_id' => null,
                ]);
                ProductVariant::query()->withTrashed()->whereIn('id', $variantIds)->update(['primary_media_id' => null]);
                ProductMedia::query()->withTrashed()->whereIn('product_id', $productIds)->forceDelete();

                ProductVariant::query()->withTrashed()->whereIn('id', $variantIds)->forceDelete();
                Product::query()->withTrashed()->whereIn('id', $productIds)->forceDelete();

                AttributeDefinition::query()->forCompany($company)->delete();

                Category::query()
                    ->withTrashed()
                    ->forCompany($company)
                    ->orderByDesc('depth')
                    ->orderByDesc('id')
                    ->get()
                    ->each(fn (Category $category) => $category->forceDelete());
            });
        } finally {
            if ($isMysql) {
                DB::unprepared(<<<'SQL'
                    CREATE TRIGGER product_passport_assets_prevent_published_delete
                    BEFORE DELETE ON product_passport_assets
                    FOR EACH ROW
                    BEGIN
                        DECLARE ver_status VARCHAR(20);

                        SELECT status INTO ver_status
                        FROM product_passport_versions
                        WHERE id = OLD.version_id;

                        IF ver_status IS NULL THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Parent version not found for passport asset.';
                        END IF;

                        IF ver_status <> 'draft' THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot delete asset of a published, superseded, or withdrawn passport version.';
                        END IF;
                    END
                SQL);

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

                DB::unprepared(<<<'SQL'
                    CREATE TRIGGER product_document_versions_prevent_delete
                    BEFORE DELETE ON product_document_versions
                    FOR EACH ROW
                    BEGIN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'product_document_versions cannot be deleted.';
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
