<?php

declare(strict_types=1);

use Solid3d\LaravelTusS3\Helpers\ObjectKeyGenerator;
use Solid3d\LaravelTusS3\Storage\S3KeyResolver;

it('builds temporary keys under the configured prefix', function (): void {
    $key = app(ObjectKeyGenerator::class)->temporaryKey('01HTESTUPLOADID000000000000');

    expect($key)->toBe('tus/tmp/01HTESTUPLOADID000000000000');
});

it('rejects path traversal in relative object keys', function (): void {
    $resolver = app(S3KeyResolver::class);

    expect(fn () => $resolver->assertSafeRelativeKey('../etc/passwd'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $resolver->assertSafeRelativeKey('tus/tmp/../../p/secret'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $resolver->assertSafeRelativeKey('other/prefix/file'))
        ->toThrow(InvalidArgumentException::class);
});

it('prefixes absolute keys with the disk root without allowing escape', function (): void {
    config(['filesystems.disks.local.root' => 's']);

    $resolver = app(S3KeyResolver::class);

    expect($resolver->absoluteKey('local', 'tus/tmp/abc'))->toBe('s/tus/tmp/abc');
});
