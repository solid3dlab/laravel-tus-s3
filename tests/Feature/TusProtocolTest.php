<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Solid3d\LaravelTusS3\Contracts\TusUploadStore;
use Solid3d\LaravelTusS3\Events\FileUploadFinished;
use Solid3d\LaravelTusS3\Models\TusUpload;
use Solid3d\LaravelTusS3\Storage\S3KeyResolver;

beforeEach(function (): void {
    Storage::fake('local');
    config([
        'tus.storage_disk' => 'local',
        'tus.min_part_size' => 4,
        'filesystems.disks.local.root' => '',
    ]);
});

it('creates an upload and returns tus headers', function (): void {
    $response = $this->withHeaders(tusHeaders(4, [
        'name' => 'part.stl',
        'session_id' => 'session-1',
    ]))->post('/tus');

    $response->assertCreated()
        ->assertHeader('Tus-Resumable', '1.0.0')
        ->assertHeader('Upload-Offset', '0');

    expect($response->headers->get('Location'))->not->toBeEmpty()
        ->and(TusUpload::query()->count())->toBe(1)
        ->and(TusUpload::query()->first()->object_key)->toStartWith('tus/tmp/');
});

it('rejects metadata outside the allowlist and path traversal', function (): void {
    $this->withHeaders(tusHeaders(4, ['bucket' => 'evil']))
        ->post('/tus')
        ->assertStatus(400);

    $this->withHeaders(tusHeaders(4, ['relative_path' => '../secret']))
        ->post('/tus')
        ->assertStatus(400);
});

it('heads the authoritative offset and completes a multi-part upload', function (): void {
    Event::fake([FileUploadFinished::class]);

    $location = $this->withHeaders(tusHeaders(8, ['name' => 'a.bin']))
        ->post('/tus')
        ->assertCreated()
        ->headers
        ->get('Location');

    $this->call('HEAD', (string) $location, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
    ])
        ->assertOk()
        ->assertHeader('Upload-Offset', '0')
        ->assertHeader('Upload-Length', '8');

    $this->call('PATCH', (string) $location, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'HTTP_UPLOAD_OFFSET' => '0',
        'CONTENT_TYPE' => 'application/offset+octet-stream',
        'CONTENT_LENGTH' => 4,
    ], content: 'abcd')->assertNoContent()->assertHeader('Upload-Offset', '4');

    $this->call('PATCH', (string) $location, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'HTTP_UPLOAD_OFFSET' => '4',
        'CONTENT_TYPE' => 'application/offset+octet-stream',
        'CONTENT_LENGTH' => 4,
    ], content: 'efgh')->assertNoContent()->assertHeader('Upload-Offset', '8');

    Event::assertDispatched(FileUploadFinished::class);

    $upload = TusUpload::query()->first();
    expect($upload->status->value)->toBe('completed')
        ->and(Storage::disk('local')->get($upload->object_key))->toBe('abcdefgh');
});

it('returns 409 with the correct offset on mismatch', function (): void {
    $location = $this->withHeaders(tusHeaders(4, ['name' => 'a.bin']))
        ->post('/tus')
        ->headers
        ->get('Location');

    $this->call('PATCH', (string) $location, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'HTTP_UPLOAD_OFFSET' => '2',
        'CONTENT_TYPE' => 'application/offset+octet-stream',
        'CONTENT_LENGTH' => 2,
    ], content: 'xx')->assertStatus(409)->assertHeader('Upload-Offset', '0');
});

it('rejects invalid checksums', function (): void {
    $location = $this->withHeaders(tusHeaders(4, ['name' => 'a.bin']))
        ->post('/tus')
        ->headers
        ->get('Location');

    $this->call('PATCH', (string) $location, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'HTTP_UPLOAD_OFFSET' => '0',
        'HTTP_UPLOAD_CHECKSUM' => 'sha256 '.base64_encode(hash('sha256', 'nope', true)),
        'CONTENT_TYPE' => 'application/offset+octet-stream',
        'CONTENT_LENGTH' => 4,
    ], content: 'mesh')->assertStatus(460);
});

it('cancels uploads via delete and prunes expired records', function (): void {
    $location = $this->withHeaders(tusHeaders(4, ['name' => 'a.bin']))
        ->post('/tus')
        ->headers
        ->get('Location');

    $this->call('DELETE', (string) $location, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
    ])->assertNoContent();

    $this->call('HEAD', (string) $location, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
    ])->assertNotFound();

    $upload = TusUpload::query()->first();
    $upload->expires_at = now()->subMinute();
    $upload->status = 'pending';
    $upload->multipart_upload_id = 'x';
    $upload->save();

    expect(app(TusUploadStore::class)->pruneExpired())->toBeGreaterThan(0);
});

it('is idempotent for duplicate patch of an already-applied chunk', function (): void {
    Event::fake([FileUploadFinished::class]);

    $location = $this->withHeaders(tusHeaders(4, ['name' => 'a.bin']))
        ->post('/tus')
        ->headers
        ->get('Location');

    $server = [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'HTTP_UPLOAD_OFFSET' => '0',
        'CONTENT_TYPE' => 'application/offset+octet-stream',
        'CONTENT_LENGTH' => 4,
    ];

    $this->call('PATCH', (string) $location, server: $server, content: 'mesh')->assertNoContent();
    $this->call('PATCH', (string) $location, server: $server, content: 'mesh')->assertNoContent()
        ->assertHeader('Upload-Offset', '4');

    Event::assertDispatchedTimes(FileUploadFinished::class, 2);
});

it('cannot escape the environment disk root via object keys', function (): void {
    config(['filesystems.disks.local.root' => 's']);

    expect(fn () => app(S3KeyResolver::class)->absoluteKey('local', 'tus/tmp/../../p/secret'))
        ->toThrow(InvalidArgumentException::class);
});
