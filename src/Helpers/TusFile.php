<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Helpers;

use RuntimeException;
use Solid3d\LaravelTusS3\Domain\FileFingerprint;
use Solid3d\LaravelTusS3\Domain\StoredFile;
use Solid3d\LaravelTusS3\Storage\TusFileStorage;

final readonly class TusFile
{
    public string $disk;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $path,
        public array $metadata,
        ?string $disk = null,
        public ?string $sha256 = null,
        public ?int $size = null,
    ) {
        $this->disk = $disk ?? (string) config('tus.storage_disk');
    }

    public function fingerprint(?int $maximumBytes = null): FileFingerprint
    {
        if ($this->sha256 === null || $this->size === null) {
            return app(TusFileStorage::class)->fingerprint($this, $maximumBytes);
        }

        if ($maximumBytes !== null && $this->size > $maximumBytes) {
            throw new RuntimeException('The completed upload exceeds the maximum allowed size.');
        }

        return new FileFingerprint($this->sha256, $this->size);
    }

    public function moveTo(string $disk, string $path): StoredFile
    {
        return app(TusFileStorage::class)->move($this, $disk, $path);
    }

    public function delete(): void
    {
        app(TusFileStorage::class)->delete($this);
    }
}
