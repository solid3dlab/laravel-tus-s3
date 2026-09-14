<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Domain;

final readonly class UploadOwner
{
    public function __construct(
        public string $type,
        public string $id,
    ) {}

    public function matches(?self $owner): bool
    {
        return $owner !== null
            && hash_equals($this->type, $owner->type)
            && hash_equals($this->id, $owner->id);
    }
}
