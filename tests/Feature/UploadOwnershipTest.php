<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Solid3d\LaravelTusS3\Contracts\UploadOwnerResolver;
use Solid3d\LaravelTusS3\Domain\UploadOwner;
use Solid3d\LaravelTusS3\Models\TusUpload;

beforeEach(function (): void {
    config([
        'tus.ownership.enabled' => true,
        'tus.min_part_size' => 4,
    ]);
});

it('allows anonymous uploads and resumptions', function (): void {
    // Arrange
    $location = $this->withHeaders(tusHeaders(4, ['name' => 'anonymous.bin']))
        ->post('/tus')
        ->assertCreated()
        ->headers
        ->get('Location');

    // Act
    $response = $this->call('HEAD', (string) $location, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
    ]);

    // Assert
    $response->assertOk();
    expect(TusUpload::query()->firstOrFail())
        ->owner_type->toBeNull()
        ->owner_id->toBeNull();
});

it('restricts authenticated uploads to their owner', function (): void {
    // Arrange
    app()->instance(UploadOwnerResolver::class, new HeaderUploadOwnerResolver);
    $location = $this->withHeaders([
        ...tusHeaders(4, ['name' => 'owned.bin']),
        'X-Upload-Owner' => 'owner-1',
    ])->post('/tus')
        ->assertCreated()
        ->headers
        ->get('Location');

    // Act and assert
    $this->call('HEAD', (string) $location, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'HTTP_X_UPLOAD_OWNER' => 'owner-1',
    ])->assertOk();

    $this->call('HEAD', (string) $location, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
    ])->assertNotFound();

    $this->call('PATCH', (string) $location, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'HTTP_UPLOAD_OFFSET' => '0',
        'HTTP_X_UPLOAD_OWNER' => 'owner-2',
        'CONTENT_TYPE' => 'application/offset+octet-stream',
        'CONTENT_LENGTH' => 4,
    ], content: 'mesh')->assertNotFound();

    $this->call('DELETE', (string) $location, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'HTTP_X_UPLOAD_OWNER' => 'owner-2',
    ])->assertNotFound();

    expect(TusUpload::query()->firstOrFail())
        ->owner_type->toBe('test-user')
        ->owner_id->toBe('owner-1');
});

it('can disable ownership enforcement for url-only access', function (): void {
    // Arrange
    app()->instance(UploadOwnerResolver::class, new HeaderUploadOwnerResolver);
    $location = $this->withHeaders([
        ...tusHeaders(4, ['name' => 'owned.bin']),
        'X-Upload-Owner' => 'owner-1',
    ])->post('/tus')
        ->assertCreated()
        ->headers
        ->get('Location');
    config(['tus.ownership.enabled' => false]);

    // Act
    $response = $this->call('HEAD', (string) $location, server: [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
    ]);

    // Assert
    $response->assertOk();
});

final class HeaderUploadOwnerResolver implements UploadOwnerResolver
{
    public function resolve(Request $request): ?UploadOwner
    {
        $ownerId = $request->header('X-Upload-Owner');

        return is_string($ownerId)
            ? new UploadOwner('test-user', $ownerId)
            : null;
    }
}
