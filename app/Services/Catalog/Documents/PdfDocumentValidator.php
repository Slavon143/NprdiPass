<?php

namespace App\Services\Catalog\Documents;

use App\Exceptions\Catalog\DocumentOperationException;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class PdfDocumentValidator
{
    public function validate(UploadedFile $file): ValidatedPdf
    {
        if (! $file->isValid()) {
            throw DocumentOperationException::invalid('document', 'The uploaded file is not valid.', 'invalid_upload');
        }

        $maxSizeKb = (int) config('documents.max_size_kb', 25600);
        $sizeBytes = $file->getSize();

        if ($sizeBytes === false || $sizeBytes === 0) {
            throw DocumentOperationException::invalid('document', 'The uploaded file is empty.', 'empty_file');
        }

        if ($sizeBytes > $maxSizeKb * 1024) {
            throw DocumentOperationException::invalid('document', 'The uploaded file exceeds the maximum allowed size.', 'file_too_large');
        }

        $clientMime = $file->getClientMimeType();
        $serverMime = $file->getMimeType();

        if ($serverMime !== 'application/pdf') {
            throw DocumentOperationException::invalid('document', 'The file must be a valid PDF.', 'invalid_mime_type');
        }

        $temporaryPath = $file->getRealPath();
        if ($temporaryPath === false) {
            throw new RuntimeException('The uploaded file path could not be resolved.');
        }

        $header = @file_get_contents($temporaryPath, false, null, 0, 5);
        if ($header === false || ! str_starts_with($header, '%PDF-')) {
            throw DocumentOperationException::invalid('document', 'The file does not appear to be a valid PDF.', 'invalid_pdf_header');
        }

        $checksum = hash_file('sha256', $temporaryPath);
        if ($checksum === false) {
            throw new RuntimeException('The file checksum could not be calculated.');
        }

        $clientExtension = strtolower((string) $file->getClientOriginalExtension());
        if ($clientExtension !== 'pdf') {
            throw DocumentOperationException::invalid('document', 'The file must have a .pdf extension.', 'invalid_extension');
        }

        $originalFilename = $file->getClientOriginalName();
        if ((string) $originalFilename === '') {
            throw DocumentOperationException::invalid('document', 'The file must have a name.', 'missing_filename');
        }

        $normalizedFilename = str_replace('\\', '/', (string) $originalFilename);
        if (str_contains($normalizedFilename, '/') || str_contains($normalizedFilename, '../')) {
            throw DocumentOperationException::invalid('document', 'The filename is not allowed.', 'unsafe_filename');
        }

        $basename = strtolower(pathinfo($normalizedFilename, PATHINFO_FILENAME));
        $dangerousExtensions = ['php', 'phtml', 'phar', 'js', 'html', 'htm', 'svg', 'exe', 'bat', 'cmd', 'ps1', 'sh'];
        foreach ($dangerousExtensions as $dangerousExtension) {
            if (str_ends_with($basename, '.'.$dangerousExtension)) {
                throw DocumentOperationException::invalid('document', 'Double-extension filenames are not allowed.', 'unsafe_double_extension');
            }
        }

        return new ValidatedPdf(
            temporaryPath: $temporaryPath,
            originalFilename: (string) $originalFilename,
            mimeType: 'application/pdf',
            extension: 'pdf',
            sizeBytes: $sizeBytes,
            checksum: $checksum,
        );
    }
}
