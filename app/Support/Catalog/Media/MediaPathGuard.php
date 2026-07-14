<?php

namespace App\Support\Catalog\Media;

use App\Exceptions\Catalog\MediaOperationException;

class MediaPathGuard
{
    public function assertSafeRelative(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')
            || str_starts_with($path, '/') || preg_match('/^[a-z]:/i', $path) === 1
            || str_starts_with($path, '//')) {
            throw MediaOperationException::invalid('media', 'The image path is invalid.', 'unsafe_media_path');
        }

        $segments = explode('/', $path);

        if (in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw MediaOperationException::invalid('media', 'The image path is invalid.', 'unsafe_media_path');
        }

        return implode('/', $segments);
    }

    public function assertExistingLocalContainment(string $root, string $candidate): void
    {
        $realRoot = realpath($root);
        $realCandidate = realpath($candidate);

        if ($realRoot === false || $realCandidate === false) {
            throw MediaOperationException::invalid('media', 'The image file is unavailable.', 'missing_media_file');
        }

        $rootPrefix = rtrim(str_replace('\\', '/', $realRoot), '/').'/';
        $candidatePath = str_replace('\\', '/', $realCandidate);

        if (! str_starts_with($candidatePath, $rootPrefix)) {
            throw MediaOperationException::invalid('media', 'The image path is invalid.', 'unsafe_media_path');
        }
    }
}
