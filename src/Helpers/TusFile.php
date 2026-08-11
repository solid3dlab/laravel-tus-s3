<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Helpers;

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
}
