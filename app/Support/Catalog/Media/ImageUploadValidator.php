<?php

namespace App\Support\Catalog\Media;

use App\Exceptions\Catalog\MediaOperationException;
use Illuminate\Http\UploadedFile;

class ImageUploadValidator
{
    public function validate(UploadedFile $file): ValidatedImage
    {
        if (! $file->isValid()) {
            throw MediaOperationException::invalid('image', 'The image upload did not complete successfully.');
        }

        $path = $file->getRealPath();
        $size = $file->getSize();
        $maximum = (int) config('catalog.media.max_file_size_kb') * 1024;

        if (! is_string($path) || ! is_file($path) || ! is_readable($path) || ! is_int($size) || $size <= 0) {
            throw MediaOperationException::invalid('image', 'The image file is empty or unavailable.');
        }

        if ($size > $maximum) {
            throw MediaOperationException::invalid('image', 'The image exceeds the maximum file size.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $header = @getimagesize($path);
        $allowed = config('catalog.media.mime_extensions', []);

        if (! is_string($mime) || ! is_array($allowed) || ! isset($allowed[$mime])
            || ! is_array($header) || $header['mime'] !== $mime) {
            throw MediaOperationException::invalid('image', 'Only valid JPEG, PNG, and WEBP images are supported.');
        }

        $clientMime = $file->getClientMimeType();
        if ($clientMime !== $mime) {
            throw MediaOperationException::invalid('image', 'The declared image type does not match its content.');
        }

        $extension = (string) $allowed[$mime];
        $originalExtension = strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $validExtensions = $mime === 'image/jpeg' ? ['jpg', 'jpeg'] : [$extension];

        if (! in_array($originalExtension, $validExtensions, true)) {
            throw MediaOperationException::invalid('image', 'The image filename extension does not match its content.');
        }

        $width = $header[0];
        $height = $header[1];
        if ($width <= 0 || $height <= 0
            || $width > (int) config('catalog.media.max_width')
            || $height > (int) config('catalog.media.max_height')
            || $width * $height > (int) config('catalog.media.max_pixels')) {
            throw MediaOperationException::invalid('image', 'The image dimensions exceed the allowed limits.');
        }

        $checksum = hash_file('sha256', $path);
        if (! is_string($checksum)) {
            throw MediaOperationException::invalid('image', 'The image checksum could not be calculated.');
        }

        return new ValidatedImage(
            $path,
            $this->sanitizeFilename($file->getClientOriginalName()),
            $mime,
            $extension,
            $size,
            $width,
            $height,
            $checksum,
        );
    }

    private function sanitizeFilename(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = trim($name);

        if ($name === '') {
            $name = 'image';
        }

        return mb_substr($name, 0, (int) config('catalog.media.original_filename_max'));
    }
}
