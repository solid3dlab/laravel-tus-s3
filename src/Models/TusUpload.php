<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Solid3d\LaravelTusS3\Domain\CompletedPart;
use Solid3d\LaravelTusS3\Enums\UploadStatus;
use Solid3d\LaravelTusS3\Helpers\TusFile;

/**
 * @property string $id
 * @property string $disk
 * @property string $object_key
 * @property string|null $multipart_upload_id
 * @property int $expected_size
 * @property int $offset
 * @property int $next_part_number
 * @property UploadStatus $status
 * @property Carbon|null $expires_at
 * @property array<string, mixed> $metadata
 * @property list<array{part_number: int, etag: string, size: int}> $parts
 * @property string|null $patch_lock_owner
 * @property Carbon|null $patch_lock_at
 * @property Carbon|null $completed_at
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
        'next_part_number',
        'status',
        'expires_at',
        'metadata',
        'parts',
        'patch_lock_owner',
        'patch_lock_at',
        'completed_at',
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
        ];
    }

    public function toTusFile(): TusFile
    {
        return new TusFile(
            id: $this->id,
            path: $this->object_key,
            metadata: [
                ...$this->metadata,
                'size' => $this->expected_size,
            ],
            disk: $this->disk,
        );
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
