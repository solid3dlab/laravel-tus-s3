<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Solid3d\LaravelTusS3\Domain\CompletedPart;
use Solid3d\LaravelTusS3\Domain\UploadOwner;
use Solid3d\LaravelTusS3\Enums\UploadStatus;
use Solid3d\LaravelTusS3\Helpers\TusFile;

/**
 * @property string $id
 * @property string $disk
 * @property string $object_key
 * @property string|null $multipart_upload_id
 * @property int $expected_size
 * @property int $offset
 * @property string|null $sha256
 * @property int $next_part_number
 * @property UploadStatus $status
 * @property Carbon|null $expires_at
 * @property array<string, mixed> $metadata
 * @property list<array{part_number: int, etag: string, size: int}> $parts
 * @property string|null $patch_lock_owner
 * @property Carbon|null $patch_lock_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $finished_notified_at
 * @property string|null $owner_type
 * @property string|null $owner_id
 */
class TusUpload extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'tus_uploads';

    protected $fillable = [
        'id',
        'disk',
        'object_key',
        'multipart_upload_id',
        'expected_size',
        'offset',
        'sha256',
        'next_part_number',
        'status',
        'expires_at',
        'metadata',
        'parts',
        'patch_lock_owner',
        'patch_lock_at',
        'completed_at',
        'finished_notified_at',
        'owner_type',
        'owner_id',
    ];

    protected function casts(): array
    {
        return [
            'expected_size' => 'integer',
            'offset' => 'integer',
            'next_part_number' => 'integer',
            'status' => UploadStatus::class,
            'expires_at' => 'datetime',
            'metadata' => 'array',
            'parts' => 'array',
            'patch_lock_at' => 'datetime',
            'completed_at' => 'datetime',
            'finished_notified_at' => 'datetime',
        ];
    }

    public function toTusFile(): TusFile
    {
        $complete = $this->status === UploadStatus::Completed
            && $this->offset === $this->expected_size;

        return new TusFile(
            id: $this->id,
            path: $this->object_key,
            metadata: [
                ...$this->metadata,
                'size' => $this->expected_size,
            ],
            disk: $this->disk,
            // Captured while the bytes streamed through PATCH, so consumers do not
            // have to read the whole object back just to hash it.
            sha256: $complete ? $this->sha256 : null,
            size: $complete ? $this->expected_size : null,
        );
    }

    public function uploadOwner(): ?UploadOwner
    {
        if ($this->owner_type === null || $this->owner_id === null) {
            return null;
        }

        return new UploadOwner($this->owner_type, $this->owner_id);
    }

    public function isOwnedBy(Authenticatable $owner): bool
    {
        return $this->uploadOwner()?->matches(new UploadOwner(
            type: $owner::class,
            id: (string) $owner->getAuthIdentifier(),
        )) ?? false;
    }

    /**
     * @return list<CompletedPart>
     */
    public function completedParts(): array
    {
        return array_map(
            static fn (array $part): CompletedPart => CompletedPart::fromArray($part),
            $this->parts ?? [],
        );
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasActivePatchLock(): bool
    {
        if ($this->patch_lock_owner === null || $this->patch_lock_at === null) {
            return false;
        }

        $ttl = max(1, (int) config('tus.patch_lock_ttl', 120));

        return $this->patch_lock_at->gt(now()->subSeconds($ttl));
    }
}
