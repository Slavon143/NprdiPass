<?php

use App\Actions\Catalog\Documents\CreateProductDocumentAction;
use App\Actions\Catalog\Media\UploadProductMediaAction;
use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Imports\CreateImportJobAction;
use App\Enums\Documents\ProductDocumentType;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Enums\Imports\ImportMode;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Services\Passports\Publication\PublicationAssetStager;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

test('full backup restores the document, current version, and exact PDF into isolated targets', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');

    $suffix = substr(str_replace('-', '', Str::uuid()->toString()), 0, 12);
    $database = "nordipass_doc_restore_{$suffix}_testing";
    $sandbox = sys_get_temp_dir().'/nordipass-document-restore-'.$suffix;
    $disks = ['production_imports', 'product_documents', 'catalog_media', 'passport_assets'];
    $sourceRoots = [];
    $restoredRoots = [];

    expect(DB::select('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?', [$database]))->toBeEmpty()
        ->and(is_dir($sandbox))->toBeFalse();

    $this->artisan('migrate:fresh')->assertSuccessful();
    mkdir($sandbox, 0700, true);
    config()->set('logging.default', 'null');

    try {
        foreach ($disks as $disk) {
            $sourceRoots[$disk] = $sandbox.'/source/'.$disk;
            $restoredRoots[$disk] = $sandbox.'/restored/'.$disk;
            mkdir($sourceRoots[$disk], 0700, true);
            config()->set("filesystems.disks.{$disk}.root", $sourceRoots[$disk]);
            Storage::forgetDisk($disk);
        }
        config()->set('filesystems.disks.backup_document_test', [
            'driver' => 'local',
            'root' => $sandbox.'/backups',
            'throw' => true,
        ]);
        config()->set('backup.disk', 'backup_document_test');
        config()->set('backup.path', 'sets');
        config()->set('backup.temporary_path', $sandbox.'/temporary');
        config()->set('backup.database.enabled', true);
        config()->set('backup.files.enabled', true);
        Storage::forgetDisk('backup_document_test');

        $actor = User::factory()->create();
        $company = Company::factory()->create();
        CompanyMembership::factory()->owner()->create(['user_id' => $actor, 'company_id' => $company]);
        $this->actingAs($actor);
        app(CurrentCompany::class)->set($company);
        $product = app(CreateProductAction::class)->execute($actor, $company, [
            'name' => 'Recovery document product',
            'slug' => 'recovery-document-product',
        ]);
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n%%EOF";
        $document = app(CreateProductDocumentAction::class)->execute($actor, $company, $product, [
            'document_type' => ProductDocumentType::Instruction->value,
            'title' => 'Recovery document',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::Internal->value,
        ], UploadedFile::fake()->createWithContent('recovery.pdf', $pdf));
        $version = $document->currentVersion;

        expect($version)->not->toBeNull()
            ->and(Storage::disk('product_documents')->get($version->storage_key))->toBe($pdf);

        $media = app(UploadProductMediaAction::class)->execute(
            $actor, $company, $product, UploadedFile::fake()->image('recovery.png', 320, 240), makePrimary: true,
        );
        $imageBytes = Storage::disk('catalog_media')->get($media->storage_path);
        $import = app(CreateImportJobAction::class)->execute($actor, $company,
            UploadedFile::fake()->createWithContent('recovery.csv', "name;weight\n".str_repeat("Recovery fixture;0.0040\n", 20000)), ImportMode::Create);
        $importBytes = Storage::disk('production_imports')->get($import->source_path);
        $passport = ProductPassport::query()->forceCreate([
            'company_id' => $company->getKey(), 'product_id' => $product->getKey(),
            'default_language' => 'en', 'enabled_languages' => ['en'], 'status' => 'draft', 'created_by' => $actor->getKey(),
        ]);
        $stager = app(PublicationAssetStager::class);
        $snapshotUuid = (string) Str::uuid();
        $assets = $stager->promoteAssets($stager->stageAssets($passport, $snapshotUuid, [
            ['asset_uuid' => $media->uuid, 'source_disk' => 'catalog_media', 'source_storage_key' => $media->storage_path, 'file_extension' => 'png'],
            ['asset_uuid' => $version->uuid, 'source_disk' => 'product_documents', 'source_storage_key' => $version->storage_key, 'file_extension' => 'pdf'],
        ]), $snapshotUuid);
        $expectedFiles = [
            'production_imports' => [$import->source_path => $importBytes],
            'catalog_media' => [$media->storage_path => $imageBytes],
            'product_documents' => [$version->storage_key => $pdf],
            'passport_assets' => [$assets[$media->uuid]['path'] => $imageBytes, $assets[$version->uuid]['path'] => $pdf],
        ];
        expect(Artisan::call('nordipass:backup'))->toBe(0, Artisan::output());

        $backupDisk = Storage::disk('backup_document_test');
        $sets = $backupDisk->directories('sets');
        expect($sets)->toHaveCount(1);
        $backupId = basename($sets[0]);
        $manifest = json_decode($backupDisk->get("sets/{$backupId}/manifest.json"), true, flags: JSON_THROW_ON_ERROR);
        expect($manifest['version'])->toBe(2)
            ->and($manifest['scope'])->toBe(['database' => true, 'files' => true]);

        foreach ($disks as $disk) {
            config()->set("filesystems.disks.{$disk}.root", $restoredRoots[$disk]);
            Storage::forgetDisk($disk);
        }
        config()->set('database.connections.mysql_document_restore', [
            ...config('database.connections.mysql'),
            'database' => $database,
        ]);
        DB::purge('mysql_document_restore');

        expect(Artisan::call('nordipass:restore', [
            'backup-id' => $backupId,
            '--target-connection' => 'mysql_document_restore',
        ]))->toBe(0, Artisan::output());

        $restored = DB::connection('mysql_document_restore')
            ->table('product_documents as document')
            ->join('product_document_versions as version', 'version.id', '=', 'document.current_version_id')
            ->join('products as product', 'product.id', '=', 'document.product_id')
            ->where('document.uuid', $document->uuid)
            ->first([
                'document.uuid as document_uuid',
                'version.uuid as version_uuid',
                'version.document_id',
                'version.storage_key',
                'version.size_bytes',
                'version.checksum_sha256',
                'product.uuid as product_uuid',
            ]);

        expect($restored)->not->toBeNull()
            ->and($restored->document_uuid)->toBe($document->uuid)
            ->and($restored->version_uuid)->toBe($version->uuid)
            ->and($restored->document_id)->toBe($document->id)
            ->and($restored->product_uuid)->toBe($product->uuid)
            ->and($restored->size_bytes)->toBe(strlen($pdf))
            ->and($restored->checksum_sha256)->toBe(hash('sha256', $pdf))
            ->and(Storage::disk('product_documents')->get($restored->storage_key))->toBe($pdf);
        foreach ($expectedFiles as $disk => $files) {
            expect(Storage::disk($disk)->allFiles())->toEqualCanonicalizing(array_keys($files));
            foreach ($files as $path => $bytes) {
                expect(Storage::disk($disk)->get($path))->toBe($bytes)
                    ->and(hash('sha256', Storage::disk($disk)->get($path)))->toBe(hash('sha256', $bytes));
            }
        }
        $restoredMedia = DB::connection('mysql_document_restore')->table('product_media')->where('uuid', $media->uuid)->first();
        expect($restoredMedia)->not->toBeNull()->and($restoredMedia->checksum_sha256)->toBe(hash('sha256', $imageBytes));
        $restoredImport = DB::connection('mysql_document_restore')->table('import_jobs')->where('uuid', $import->uuid)->first();
        expect($restoredImport)->not->toBeNull()->and($restoredImport->source_sha256)->toBe(hash('sha256', $importBytes));
    } finally {
        DB::disconnect('mysql_document_restore');
        DB::connection('mysql')->statement("DROP DATABASE IF EXISTS `{$database}`");
        foreach ([...$disks, 'backup_document_test'] as $disk) {
            Storage::forgetDisk($disk);
        }
        (new Filesystem)->deleteDirectory($sandbox);
    }
});
