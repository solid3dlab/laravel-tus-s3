<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Solid3d\LaravelTusS3\Helpers\TusFile;
use Solid3d\LaravelTusS3\Storage\S3KeyResolver;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('archive');
    config([
        'tus.storage_disk' => 'local',
        'tus.temporary_prefix' => 'tus/tmp',
    ]);
});

it('fingerprints a completed upload', function (): void {
    Storage::disk('local')->put('tus/tmp/upload-id', 'abcdefgh');
    $file = new TusFile('upload-id', 'tus/tmp/upload-id', ['name' => 'part.stl']);

    $fingerprint = $file->fingerprint();

    expect($fingerprint->sha256)->toBe(hash('sha256', 'abcdefgh'))
        ->and($fingerprint->size)->toBe(8);
});

it('rejects a completed upload above the fingerprint size limit', function (): void {
    Storage::disk('local')->put('tus/tmp/upload-id', 'abcdefgh');
    $file = new TusFile('upload-id', 'tus/tmp/upload-id', []);

    expect(fn () => $file->fingerprint(maximumBytes: 7))
        ->toThrow(RuntimeException::class, 'The completed upload exceeds the maximum allowed size.')
        ->and(Storage::disk('local')->exists($file->path))->toBeTrue();
});

it('moves a completed upload on the same disk', function (): void {
    Storage::disk('local')->put('tus/tmp/upload-id', 'mesh');
    $file = new TusFile('upload-id', 'tus/tmp/upload-id', ['name' => 'part.stl']);

    $stored = $file->moveTo('local', 'library/part.stl');
    $file->delete();

    expect($stored->disk)->toBe('local')
        ->and($stored->path)->toBe('library/part.stl')
        ->and($stored->metadata)->toBe($file->metadata)
        ->and(Storage::disk('local')->get($stored->path))->toBe('mesh')
        ->and(Storage::disk('local')->exists($file->path))->toBeFalse();
});

it('moves a completed upload between disks', function (): void {
    Storage::disk('local')->put('tus/tmp/upload-id', 'mesh');
    $file = new TusFile('upload-id', 'tus/tmp/upload-id', []);

    $stored = $file->moveTo('archive', 'library/part.stl');

    expect(Storage::disk('archive')->get($stored->path))->toBe('mesh')
        ->and(Storage::disk('local')->exists($file->path))->toBeFalse();
});

it('moves between scoped views of one disk', function (): void {
    config([
        'filesystems.disks.tus-scoped.driver' => 'scoped',
        'filesystems.disks.tus-scoped.disk' => 'local',
        'filesystems.disks.tus-scoped.prefix' => 'staging',
        'filesystems.disks.media-scoped.driver' => 'scoped',
        'filesystems.disks.media-scoped.disk' => 'local',
        'filesystems.disks.media-scoped.prefix' => 'media',
    ]);
    Storage::disk('tus-scoped')->put('tus/tmp/upload-id', 'mesh');
    $file = new TusFile('upload-id', 'tus/tmp/upload-id', [], 'tus-scoped');

    $stored = $file->moveTo('media-scoped', 'library/part.stl');

    expect($stored->path)->toBe('library/part.stl')
        ->and(Storage::disk('media-scoped')->get('library/part.stl'))->toBe('mesh')
        ->and(Storage::disk('local')->get('media/library/part.stl'))->toBe('mesh')
        ->and(Storage::disk('local')->exists('staging/tus/tmp/upload-id'))->toBeFalse();
});

it('only hands a move to the shared base disk when that disk is s3', function (): void {
    config([
        'filesystems.disks.tus-scoped.driver' => 'scoped',
        'filesystems.disks.tus-scoped.disk' => 'local',
        'filesystems.disks.tus-scoped.prefix' => 'staging',
        'filesystems.disks.media-scoped.driver' => 'scoped',
        'filesystems.disks.media-scoped.disk' => 'local',
        'filesystems.disks.media-scoped.prefix' => 'media',
    ]);

    $keys = app(S3KeyResolver::class);

    // Both are views onto one disk, but writing through the base adapter and
    // reading back through the scoped one is only guaranteed to agree on S3.
    expect($keys->sharesObjectStore('tus-scoped', 'media-scoped'))->toBeTrue()
        ->and($keys->canCopyServerSide('tus-scoped', 'media-scoped'))->toBeFalse();
});

it('rejects unsafe destination paths', function (): void {
    Storage::disk('local')->put('tus/tmp/upload-id', 'mesh');
    $file = new TusFile('upload-id', 'tus/tmp/upload-id', []);

    expect(fn () => $file->moveTo('local', '../outside.stl'))
        ->toThrow(InvalidArgumentException::class)
        ->and(Storage::disk('local')->exists($file->path))->toBeTrue();
});

it('deletes a completed upload', function (): void {
    Storage::disk('local')->put('tus/tmp/upload-id', 'mesh');
    $file = new TusFile('upload-id', 'tus/tmp/upload-id', []);

    $file->delete();

    expect(Storage::disk('local')->exists($file->path))->toBeFalse();
});
