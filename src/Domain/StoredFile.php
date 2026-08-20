<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Domain;

final readonly class StoredFile
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $disk,
        public string $path,
        public array $metadata,
    ) {}
}
