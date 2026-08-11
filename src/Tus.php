<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3;

use Solid3d\LaravelTusS3\Contracts\TusUploadStore;
use Solid3d\LaravelTusS3\Helpers\TusHeaderBuilder;

class Tus
{
    protected const VERSION = '1.0.0';

    public function version(): string
    {
        return static::VERSION;
    }

    public function headers(): TusHeaderBuilder
    {
        return new TusHeaderBuilder(static::VERSION);
    }

    public function store(): TusUploadStore
    {
        return app(TusUploadStore::class);
    }

    public function isValidChecksum(string $algo, string $hash, string $payload): bool
    {
        if (! in_array($algo, hash_algos(), true)) {
            return false;
        }

        $expected = base64_decode($hash, true);

        if ($expected === false) {
            return false;
        }

        return hash_equals($expected, hash($algo, $payload, true));
    }

    public function maxFileSize(): ?int
    {
        $configured = config('tus.file_size_limit');

        if ($configured !== null && (int) $configured > 0) {
            return (int) $configured;
        }

        $postMax = (string) ini_get('post_max_size');

        return match (true) {
            str_contains($postMax, 'M') => (int) $postMax * 1_000_000,
            str_contains($postMax, 'G') => (int) $postMax * 1_000_000_000,
            default => null,
        };
    }

    public function isInMaxFileSize(int $size): bool
    {
        $limit = $this->maxFileSize();

        if ($limit === null) {
            return true;
        }

        return $limit > $size;
    }

    public function extensionIsActive(string $extension): bool
    {
        return in_array($extension, (array) config('tus.extensions'), true);
    }
}
