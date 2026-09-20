<?php

use App\Exceptions\Catalog\DocumentOperationException;
use App\Exceptions\Catalog\MediaOperationException;
use App\Services\Catalog\Documents\PdfDocumentValidator;
use App\Support\Catalog\Media\ImageUploadValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

test('image upload rejects content that does not match the declared image', function () {
    $validator = app(ImageUploadValidator::class);

    $disguised = UploadedFile::fake()->createWithContent('photo.png', '<?php echo "not an image"; ?>');

    expect(fn () => $validator->validate($disguised))->toThrow(MediaOperationException::class);
});

test('image upload rejects SVG and other non-allowlisted formats', function () {
    $validator = app(ImageUploadValidator::class);

    $svg = UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

    expect(fn () => $validator->validate($svg))->toThrow(MediaOperationException::class);
});

test('image upload rejects files above the configured size limit', function () {
    config(['catalog.media.max_file_size_kb' => 1]);

    $validator = app(ImageUploadValidator::class);

    $oversized = UploadedFile::fake()->create('big.png', 2);

    expect(fn () => $validator->validate($oversized))->toThrow(MediaOperationException::class);
});

test('image upload accepts a valid PNG', function () {
    $validator = app(ImageUploadValidator::class);

    $image = UploadedFile::fake()->image('photo.png', 100, 100);

    $validated = $validator->validate($image);

    expect($validated->mimeType)->toBe('image/png')
        ->and($validated->width)->toBe(100)
        ->and($validated->height)->toBe(100);
});

test('PDF upload rejects files without a PDF header', function () {
    $validator = app(PdfDocumentValidator::class);

    $fake = UploadedFile::fake()->createWithContent('report.pdf', 'This is plain text, not a PDF.');

    expect(fn () => $validator->validate($fake))->toThrow(DocumentOperationException::class);
});

test('PDF upload rejects double-extension filenames', function () {
    $validator = app(PdfDocumentValidator::class);

    $fake = UploadedFile::fake()->createWithContent('report.pdf.php', '%PDF-1.7 fake header');

    expect(fn () => $validator->validate($fake))->toThrow(DocumentOperationException::class);
});

test('PDF upload rejects files above the configured size limit', function () {
    config(['documents.max_size_kb' => 1]);

    $validator = app(PdfDocumentValidator::class);

    $oversized = UploadedFile::fake()->createWithContent('big.pdf', str_repeat('%PDF-1.7', 1024));

    expect(fn () => $validator->validate($oversized))->toThrow(DocumentOperationException::class);
});

test('PDF upload accepts a valid minimal PDF', function () {
    $validator = app(PdfDocumentValidator::class);

    $pdf = UploadedFile::fake()->createWithContent('valid.pdf', '%PDF-1.7 minimal pdf content');

    $validated = $validator->validate($pdf);

    expect($validated->mimeType)->toBe('application/pdf')
        ->and($validated->extension)->toBe('pdf');
});
