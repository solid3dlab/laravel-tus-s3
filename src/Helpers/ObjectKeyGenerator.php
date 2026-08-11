<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Helpers;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class ObjectKeyGenerator
{
    public function temporaryKey(string $uploadId): string
    {
        $prefix = trim((string) config('tus.temporary_prefix', 'tus/tmp'), '/');

        if ($prefix === '' || str_contains($prefix, '..') || str_contains($prefix, '\\')) {
            throw new InvalidArgumentException('tus.temporary_prefix is invalid.');
        }

        if ($uploadId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $uploadId) !== 1) {
            throw new InvalidArgumentException('Upload id is invalid.');
        }

        return $prefix.'/'.$uploadId;
    }

    public function uploadId(): string
    {
        return (string) Str::ulid();
    }
}
