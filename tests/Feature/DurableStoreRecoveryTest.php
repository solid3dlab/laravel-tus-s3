<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Solid3d\LaravelTusS3\Contracts\MultipartUploader;
use Solid3d\LaravelTusS3\Contracts\TusUploadStore;
use Solid3d\LaravelTusS3\Domain\CompletedPart;
use Solid3d\LaravelTusS3\Models\TusUpload;
use Solid3d\LaravelTusS3\Storage\LocalMultipartUploader;

beforeEach(function (): void {
    Storage::fake('local');
    config([
        'tus.storage_disk' => 'local',
        'tus.min_part_size' => 4,
    ]);
});

it('recovers when s3 succeeded but the database part commit was lost', function (): void {
    $store = app(TusUploadStore::class);
    $file = $store->create(8, ['name' => 'a.bin']);

    $stream = fopen('php://memory', 'r+b');
    fwrite($stream, 'abcd');
    rewind($stream);
    expect($store->append($file->id, 0, $stream, 4))->toBe(4);
    fclose($stream);

    $upload = TusUpload::query()->findOrFail($file->id);

    // Simulate DB losing the second part while S3/local multipart still has it.
    $uploader = app(MultipartUploader::class);
    assert($uploader instanceof LocalMultipartUploader);

    $stream = fopen('php://memory', 'r+b');
    fwrite($stream, 'efgh');
    rewind($stream);
    $etag = $uploader->uploadPart(
        $upload->disk,
        $upload->object_key,
        (string) $upload->multipart_upload_id,
        $upload->next_part_number,
        $stream,
        4,
    );
    fclose($stream);

    expect($etag)->not->toBeEmpty()
        ->and($upload->fresh()->offset)->toBe(4);

    // Next PATCH with the same offset should reconcile ListParts and complete.
    $stream = fopen('php://memory', 'r+b');
    fwrite($stream, 'efgh');
    rewind($stream);
    expect($store->append($file->id, 4, $stream, 4))->toBe(8);
    fclose($stream);

    expect($upload->fresh()->status->value)->toBe('completed')
        ->and(Storage::disk('local')->get($upload->object_key))->toBe('abcdefgh');
});

it('completes multipart exactly once across duplicate completion attempts', function (): void {
    $store = app(TusUploadStore::class);
    $file = $store->create(4, ['name' => 'a.bin']);

    $stream = fopen('php://memory', 'r+b');
    fwrite($stream, 'mesh');
    rewind($stream);
    $store->append($file->id, 0, $stream, 4);
    fclose($stream);

    $upload = TusUpload::query()->findOrFail($file->id);
    expect($upload->status->value)->toBe('completed')
        ->and($upload->multipart_upload_id)->toBeNull()
        ->and($upload->parts)->toHaveCount(1)
        ->and(CompletedPart::fromArray($upload->parts[0])->size)->toBe(4);

    $stream = fopen('php://memory', 'r+b');
    fwrite($stream, 'mesh');
    rewind($stream);
    expect($store->append($file->id, 0, $stream, 4))->toBe(4);
    fclose($stream);
});
