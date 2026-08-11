<?php

declare(strict_types=1);

use Solid3d\LaravelTusS3\Exceptions\ChecksumMismatchException;
use Solid3d\LaravelTusS3\Exceptions\OffsetMismatchException;
use Solid3d\LaravelTusS3\Helpers\ObjectKeyGenerator;
use Solid3d\LaravelTusS3\Storage\InMemoryTusUploadStore;

it('supports create head patch completion and cancellation in memory', function (): void {
    config(['tus.min_part_size' => 4]);
    $store = new InMemoryTusUploadStore(app(ObjectKeyGenerator::class));
    $file = $store->create(8, ['session_id' => 's1']);

    expect($store->offset($file->id))->toBe(0)
        ->and($store->expectedLength($file->id))->toBe(8);

    $stream = fopen('php://memory', 'r+b');
    fwrite($stream, 'abcd');
    rewind($stream);
    expect($store->append($file->id, 0, $stream, 4))->toBe(4);
    fclose($stream);

    $stream = fopen('php://memory', 'r+b');
    fwrite($stream, 'efgh');
    rewind($stream);
    expect($store->append($file->id, 4, $stream, 4))->toBe(8);
    fclose($stream);

    expect($store->objectContents($file->path))->toBe('abcdefgh');

    expect($store->abort($file->id))->toBeTrue();
});

it('rejects checksum and offset mismatches', function (): void {
    config(['tus.min_part_size' => 4]);
    $store = new InMemoryTusUploadStore(app(ObjectKeyGenerator::class));
    $file = $store->create(4, []);

    $stream = fopen('php://memory', 'r+b');
    fwrite($stream, 'mesh');
    rewind($stream);

    expect(fn () => $store->append(
        $file->id,
        0,
        $stream,
        4,
        'sha256',
        base64_encode(hash('sha256', 'nope', true)),
    ))->toThrow(ChecksumMismatchException::class);

    rewind($stream);
    expect(fn () => $store->append($file->id, 1, $stream, 4))
        ->toThrow(OffsetMismatchException::class);

    fclose($stream);
});
