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

        DB::unprepared('DROP TRIGGER IF EXISTS passport_validation_results_prevent_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS passport_validation_results_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS passport_validation_runs_prevent_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS passport_validation_runs_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS product_passport_versions_prevent_published_update');
        DB::unprepared('DROP TRIGGER IF EXISTS product_passport_assets_prevent_published_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS product_passport_versions_prevent_published_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS product_document_versions_prevent_delete');

        try {
            DB::transaction(function () use ($company): void {
                $productIds = Product::query()->withTrashed()->forCompany($company)->pluck('id')->toArray();
                $variantIds = ProductVariant::query()->withTrashed()->forCompany($company)
                    ->whereIn('product_id', $productIds)->pluck('id')->toArray();
                $passportIds = ProductPassport::query()->forCompany($company)->pluck('id')->toArray();
                $documentIds = ProductDocument::query()->whereIn('product_id', $productIds)->pluck('id')->toArray();

                if ($passportIds !== []) {
                    ProductPassportVersion::query()->whereIn('passport_id', $passportIds)->update([
                        'validation_run_id' => null,
                        'readiness_evidence' => null,
                    ]);
                    $validationRunIds = DB::table('passport_validation_runs')
                        ->whereIn('passport_id', $passportIds)
                        ->pluck('id')
                        ->all();
                    DB::table('passport_validation_results')->whereIn('validation_run_id', $validationRunIds)->delete();
                    DB::table('passport_validation_runs')->whereIn('id', $validationRunIds)->delete();

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
            DB::unprepared(<<<'SQL'
                    CREATE TRIGGER passport_validation_runs_prevent_update
                    BEFORE UPDATE ON passport_validation_runs FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Passport validation runs are immutable.'
                SQL);
            DB::unprepared(<<<'SQL'
                    CREATE TRIGGER passport_validation_runs_prevent_delete
                    BEFORE DELETE ON passport_validation_runs FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Passport validation runs are immutable.'
                SQL);
            DB::unprepared(<<<'SQL'
                    CREATE TRIGGER passport_validation_results_prevent_update
                    BEFORE UPDATE ON passport_validation_results FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Passport validation results are immutable.'
                SQL);
            DB::unprepared(<<<'SQL'
                    CREATE TRIGGER passport_validation_results_prevent_delete
                    BEFORE DELETE ON passport_validation_results FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Passport validation results are immutable.'
                SQL);
            DB::unprepared(<<<'SQL'
                    CREATE TRIGGER product_passport_versions_prevent_published_update
                    BEFORE UPDATE ON product_passport_versions FOR EACH ROW
                    BEGIN
                        IF OLD.status IN ('superseded', 'withdrawn') THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Superseded and withdrawn passport versions are immutable.';
                        END IF;
                        IF OLD.status = 'published' THEN
                            IF NEW.status IN ('superseded', 'withdrawn') THEN
                                IF NOT (NEW.payload <=> OLD.payload)
                                    OR NOT (NEW.content_checksum <=> OLD.content_checksum)
                                    OR NOT (NEW.validation_run_id <=> OLD.validation_run_id)
                                    OR NOT (NEW.readiness_evidence <=> OLD.readiness_evidence)
                                    OR NOT (NEW.version_number <=> OLD.version_number)
                                    OR NOT (NEW.published_at <=> OLD.published_at)
                                    OR NOT (NEW.published_by <=> OLD.published_by)
                                    OR NOT (NEW.uuid <=> OLD.uuid)
                                    OR NOT (NEW.company_id <=> OLD.company_id)
                                    OR NOT (NEW.passport_id <=> OLD.passport_id)
                                    OR NOT (NEW.draft_revision <=> OLD.draft_revision)
                                    OR NOT (NEW.schema_version <=> OLD.schema_version)
                                    OR NOT (NEW.created_by <=> OLD.created_by)
                                    OR NOT (NEW.created_at <=> OLD.created_at) THEN
                                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published passport evidence is immutable.';
                                END IF;
                            ELSE
                                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published versions may only transition to superseded or withdrawn.';
                            END IF;
                        END IF;
                    END
                SQL);
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
