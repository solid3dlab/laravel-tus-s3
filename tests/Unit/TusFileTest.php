<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Solid3d\LaravelTusS3\Helpers\TusFile;

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
