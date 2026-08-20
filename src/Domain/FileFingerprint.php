<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Domain;

final readonly class FileFingerprint
{
    public function __construct(
        public string $sha256,
        public int $size,
    ) {}
}
