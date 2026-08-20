<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Helpers;

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
    ) {
        $this->disk = $disk ?? (string) config('tus.storage_disk');
    }

    public function fingerprint(?int $maximumBytes = null): FileFingerprint
    {
        return app(TusFileStorage::class)->fingerprint($this, $maximumBytes);
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
