<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

final class S3KeyResolver
{
    public function disk(string $disk): FilesystemAdapter
    {
        $filesystem = Storage::disk($disk);

        if (! $filesystem instanceof FilesystemAdapter) {
            throw new RuntimeException("Disk [{$disk}] is not a filesystem adapter.");
        }

        return $filesystem;
    }

    /**
     * Resolve the absolute object key including the disk root (AWS_ROOT).
     *
     * Relative keys must already be server-generated and must not escape the
     * configured temporary prefix.
     */
    public function absoluteKey(string $disk, string $relativeKey): string
    {
        $this->assertSafeRelativeKey($relativeKey);

        $root = trim((string) (config("filesystems.disks.{$disk}.root") ?? ''), '/');
        $relative = ltrim($relativeKey, '/');

        if ($root === '') {
            return $relative;
        }

        // Prevent escaping the environment root via crafted relative keys.
        $absolute = $root.'/'.$relative;
        $normalizedRoot = $root.'/';

        if (! str_starts_with($absolute, $normalizedRoot) && $absolute !== $root) {
            throw new InvalidArgumentException('Object key escapes the configured disk root.');
        }

        return $absolute;
    }

    public function assertSafeRelativeKey(string $relativeKey): void
    {
        $normalized = str_replace('\\', '/', $relativeKey);
        $prefix = trim((string) config('tus.temporary_prefix', 'tus/tmp'), '/');

        if (
            $normalized === ''
            || str_contains($normalized, '..')
            || str_starts_with($normalized, '/')
            || ! str_starts_with($normalized, $prefix.'/')
        ) {
            throw new InvalidArgumentException('Object key is outside the Tus temporary prefix.');
        }
    }

    public function bucket(string $disk): string
    {
        $bucket = (string) (config("filesystems.disks.{$disk}.bucket") ?? '');

        if ($bucket === '') {
            throw new RuntimeException("Disk [{$disk}] has no bucket configured.");
        }

        return $bucket;
    }

    public function client(string $disk): mixed
    {
        $adapter = $this->disk($disk);

        if (! method_exists($adapter, 'getClient')) {
            throw new RuntimeException("Disk [{$disk}] does not expose an S3 client.");
        }

        return $adapter->getClient();
    }

    public function filesystem(string $disk): Filesystem
    {
        return $this->disk($disk);
    }
}
