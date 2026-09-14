<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Solid3d\LaravelTusS3\Contracts\MultipartUploader;
use Solid3d\LaravelTusS3\Contracts\TusUploadStore;
use Solid3d\LaravelTusS3\Storage\S3KeyResolver;
use Solid3d\LaravelTusS3\Storage\S3MultipartUploader;

it('uploads and completes a real multipart object through a scoped s3 disk', function (): void {
    // Arrange
    if (getenv('TUS_S3_INTEGRATION') !== '1') {
        $this->markTestSkipped('Set TUS_S3_INTEGRATION=1 to run the S3 integration test.');
    }

    $bucket = 'laravel-tus-s3-tests';
    config([
        'tus.storage_disk' => 'uploads',
        'tus.min_part_size' => 5_242_880,
        'tus.max_part_bytes' => 5_242_880,
        'filesystems.disks.s3' => [
            'driver' => 's3',
            'key' => getenv('TUS_S3_KEY') ?: 'minioadmin',
            'secret' => getenv('TUS_S3_SECRET') ?: 'minioadmin',
            'region' => 'us-east-1',
            'bucket' => $bucket,
            'endpoint' => getenv('TUS_S3_ENDPOINT') ?: 'http://127.0.0.1:9000',
            'use_path_style_endpoint' => true,
            'root' => 'integration',
            'throw' => true,
        ],
        'filesystems.disks.uploads' => [
            'driver' => 'scoped',
            'disk' => 's3',
            'prefix' => 'scoped',
        ],
    ]);
    Storage::forgetDisk('s3');
    Storage::forgetDisk('uploads');

    $keys = app(S3KeyResolver::class);
    $client = $keys->client('uploads');

    if (! $client->doesBucketExistV2($bucket)) {
        $client->createBucket(['Bucket' => $bucket]);
    }

    $store = app(TusUploadStore::class);
    $part = str_repeat('a', 5_242_880);
    $file = $store->create(strlen($part) + 4, ['name' => 'multipart.bin']);

    // Act
    $first = fopen('php://temp', 'w+b');
    fwrite($first, $part);
    rewind($first);
    $store->append($file->id, 0, $first, strlen($part));
    fclose($first);

    $final = fopen('php://temp', 'w+b');
    fwrite($final, 'done');
    rewind($final);
    $offset = $store->append($file->id, strlen($part), $final, 4);
    fclose($final);

    // Assert
    expect(app(MultipartUploader::class))->toBeInstanceOf(S3MultipartUploader::class)
        ->and($offset)->toBe(strlen($part) + 4)
        ->and(Storage::disk('uploads')->get($file->path))->toBe($part.'done');

    Storage::disk('uploads')->delete($file->path);
});
