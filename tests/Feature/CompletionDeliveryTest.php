<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Solid3d\LaravelTusS3\Contracts\TusUploadStore;
use Solid3d\LaravelTusS3\Enums\UploadStatus;
use Solid3d\LaravelTusS3\Events\FileUploadFinished;
use Solid3d\LaravelTusS3\Models\TusUpload;

beforeEach(function (): void {
    Storage::fake('local');
    config([
        'tus.storage_disk' => 'local',
        'tus.min_part_size' => 4,
        'filesystems.disks.local.root' => '',
    ]);
});

function completeUpload(object $test, string $contents = 'mesh'): string
{
    $location = $test->withHeaders(tusHeaders(strlen($contents), ['name' => 'part.stl']))
        ->post('/tus')
        ->headers
        ->get('Location');

    $test->call('PATCH', (string) $location, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'HTTP_UPLOAD_OFFSET' => '0',
        'CONTENT_TYPE' => 'application/offset+octet-stream',
        'CONTENT_LENGTH' => strlen($contents),
    ], content: $contents)->assertNoContent();

    return (string) $location;
}

it('claims the completion notification once, so a worker swap cannot replay or lose it', function (): void {
    completeUpload($this);
    $id = TusUpload::query()->firstOrFail()->id;
    $store = app(TusUploadStore::class);

    // The PATCH already claimed it. A fresh container (new Octane worker) must not
    // hand the same completion out again.
    app()->forgetInstance(TusUploadStore::class);

    expect(app(TusUploadStore::class)->pullCompleted($id))->toBeNull()
        ->and($store->pullCompleted($id))->toBeNull()
        ->and(TusUpload::query()->firstOrFail()->finished_notified_at)->not->toBeNull();
});

it('sweeps completions whose notification was never claimed', function (): void {
    completeUpload($this);
    $upload = TusUpload::query()->firstOrFail();

    // Simulate the PATCH dying between completing the upload and dispatching.
    $upload->finished_notified_at = null;
    $upload->save();

    Event::fake([FileUploadFinished::class]);

    expect(app(TusUploadStore::class)->unnotifiedCompleted())->toBe([$upload->id]);

    $this->artisan('tus:dispatch-finished')->assertSuccessful();

    Event::assertDispatchedTimes(FileUploadFinished::class, 1);
    expect(TusUpload::query()->firstOrFail()->finished_notified_at)->not->toBeNull();

    // Second run has nothing left to do.
    $this->artisan('tus:dispatch-finished')->assertSuccessful();
    Event::assertDispatchedTimes(FileUploadFinished::class, 1);
});

it('keeps unnotified completions out of the prune sweep', function (): void {
    completeUpload($this);
    $upload = TusUpload::query()->firstOrFail();
    $upload->finished_notified_at = null;
    $upload->updated_at = now()->subDay();
    $upload->save();

    app(TusUploadStore::class)->pruneExpired();

    expect(TusUpload::query()->count())->toBe(1);

    TusUpload::query()->whereKey($upload->id)->update([
        'finished_notified_at' => now(),
        'updated_at' => now()->subDay(),
    ]);
    app(TusUploadStore::class)->pruneExpired();

    expect(TusUpload::query()->count())->toBe(0);
});

it('reports whether an upload can still accept bytes', function (): void {
    $location = $this->withHeaders(tusHeaders(8, ['name' => 'part.stl']))
        ->post('/tus')
        ->headers
        ->get('Location');
    $id = TusUpload::query()->firstOrFail()->id;
    $store = app(TusUploadStore::class);

    expect($store->isActive($id))->toBeTrue()
        ->and($store->isActive('missing-upload'))->toBeFalse();

    $store->abort($id);

    expect($store->isActive($id))->toBeFalse()
        ->and(TusUpload::query()->firstOrFail()->status)->toBe(UploadStatus::Cancelled);
});

it('hashes a single-chunk upload while it streams instead of reading it back', function (): void {
    completeUpload($this, 'mesh');
    $upload = TusUpload::query()->firstOrFail();

    expect($upload->sha256)->toBe(hash('sha256', 'mesh'));

    // Fingerprinting must not need the object at all now.
    Storage::disk('local')->delete($upload->object_key);
    $fingerprint = $upload->toTusFile()->fingerprint();

    expect($fingerprint->sha256)->toBe(hash('sha256', 'mesh'))
        ->and($fingerprint->size)->toBe(4);
});

it('leaves multi-chunk uploads to fingerprint from the stored object', function (): void {
    config(['tus.min_part_size' => 4]);
    $location = $this->withHeaders(tusHeaders(8, ['name' => 'part.stl']))
        ->post('/tus')
        ->headers
        ->get('Location');

    foreach ([[0, 'mesh'], [4, 'work']] as [$offset, $chunk]) {
        $this->call('PATCH', (string) $location, server: [
            'HTTP_TUS_RESUMABLE' => '1.0.0',
            'HTTP_UPLOAD_OFFSET' => (string) $offset,
            'CONTENT_TYPE' => 'application/offset+octet-stream',
            'CONTENT_LENGTH' => 4,
        ], content: $chunk)->assertNoContent();
    }

    $upload = TusUpload::query()->firstOrFail();

    expect($upload->sha256)->toBeNull()
        ->and($upload->toTusFile()->fingerprint()->sha256)->toBe(hash('sha256', 'meshwork'));
});
