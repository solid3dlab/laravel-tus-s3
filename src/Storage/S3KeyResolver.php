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

        ['prefix' => $root] = $this->resolvedDisk($disk);
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
        $normalized = $this->assertSafeStorageKey($relativeKey);
        $prefix = trim((string) config('tus.temporary_prefix', 'tus/tmp'), '/');

        if (! str_starts_with($normalized, $prefix.'/')) {
            throw new InvalidArgumentException('Object key is outside the Tus temporary prefix.');
        }
    }

    public function assertSafeStorageKey(string $relativeKey): string
    {
        $normalized = str_replace('\\', '/', $relativeKey);

        if (
            $normalized === ''
            || str_contains($normalized, '..')
            || str_starts_with($normalized, '/')
        ) {
            throw new InvalidArgumentException('Object key must be a safe relative path.');
        }

        return $normalized;
    }

    public function bucket(string $disk): string
    {
        ['disk' => $baseDisk] = $this->resolvedDisk($disk);
        $bucket = (string) (config("filesystems.disks.{$baseDisk}.bucket") ?? '');

        if ($bucket === '') {
            throw new RuntimeException("Disk [{$disk}] has no bucket configured.");
        }

        return $bucket;
    }

    public function client(string $disk): mixed
    {
        ['disk' => $baseDisk] = $this->resolvedDisk($disk);
        $adapter = $this->disk($baseDisk);

        if (! method_exists($adapter, 'getClient')) {
            throw new RuntimeException("Disk [{$disk}] does not expose an S3 client.");
        }

        return $adapter->getClient();
    }

    public function usesS3(string $disk): bool
    {
        ['disk' => $baseDisk] = $this->resolvedDisk($disk);

        return config("filesystems.disks.{$baseDisk}.driver") === 's3'
            && method_exists($this->disk($baseDisk), 'getClient');
    }

    public function filesystem(string $disk): Filesystem
    {
        return $this->disk($disk);
    }

    /**
     * @return array{disk: string, prefix: string}
     */
    private function resolvedDisk(string $disk): array
    {
        $seen = [];
        $prefixes = [];

        while (config("filesystems.disks.{$disk}.driver") === 'scoped') {
            if (isset($seen[$disk])) {
                throw new RuntimeException('Scoped filesystem disks contain a circular reference.');
            }

            $seen[$disk] = true;
            $prefix = trim((string) config("filesystems.disks.{$disk}.prefix"), '/');

            if ($prefix !== '') {
                $prefixes[] = $this->assertSafeStorageKey($prefix);
            }

            $parent = config("filesystems.disks.{$disk}.disk");

            if (! is_string($parent) || $parent === '') {
                throw new RuntimeException("Scoped disk [{$disk}] has no parent disk configured.");
            }

            $disk = $parent;
        }

        $root = trim((string) config("filesystems.disks.{$disk}.root"), '/');
        $prefixes = array_reverse($prefixes);

        if ($root !== '') {
            array_unshift($prefixes, $this->assertSafeStorageKey($root));
        }

        return [
            'disk' => $disk,
            'prefix' => implode('/', $prefixes),
        ];
    }
}
