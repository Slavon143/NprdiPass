<?php

use App\Exceptions\Catalog\MediaOperationException;
use App\Support\Catalog\Media\MediaPathGuard;

test('catalog media path guard accepts a generated relative path', function () {
    $path = 'company/products/product/variants/variant/media.webp';
    expect((new MediaPathGuard)->assertSafeRelative($path))->toBe($path);
});

test('catalog media path guard rejects traversal absolute drive UNC backslash and null paths', function (string $path) {
    expect(fn () => (new MediaPathGuard)->assertSafeRelative($path))->toThrow(MediaOperationException::class);
})->with([
    '../secret.jpg',
    'company/../secret.jpg',
    '/absolute.jpg',
    'C:/drive.jpg',
    '//server/share.jpg',
    'company\\escape.jpg',
    "company/null\0.jpg",
]);
