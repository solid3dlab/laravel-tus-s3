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

    public function baseDisk(string $disk): string
    {
        return $this->resolvedDisk($disk)['disk'];
    }

    /**
     * True when both disks are views onto the same underlying disk, so a transfer
     * between them can be a server-side copy instead of a download plus upload.
     */
    public function sharesObjectStore(string $source, string $destination): bool
    {
        return $this->baseDisk($source) === $this->baseDisk($destination);
    }

    /**
     * Whether a transfer may be handed to the shared base disk.
     *
     * Restricted to S3 on purpose. Reaching for the base disk by name assumes
     * that resolving that name yields the same bytes the scoped disk sees, and
     * that only holds when both views come from the same bucket configuration;
     * a locally rooted or faked disk can be swapped out from under the scoped
     * one, and then the copy lands somewhere the caller cannot read. Streaming
     * a local file is cheap anyway — the saving here is a network round trip.
     */
    public function canCopyServerSide(string $source, string $destination): bool
    {
        return $this->sharesObjectStore($source, $destination)
            && $this->usesS3($source);
    }

    /**
     * Rewrite a key that is relative to a (possibly scoped) disk into one that is
     * relative to its base disk, which is the form the base adapter expects.
     */
    public function baseRelativeKey(string $disk, string $key): string
    {
        $normalized = ltrim($this->assertSafeStorageKey($key), '/');
        ['scoped_prefix' => $prefix] = $this->resolvedDisk($disk);

        return $prefix === '' ? $normalized : $prefix.'/'.$normalized;
    }

    public function filesystem(string $disk): Filesystem
    {
        return $this->disk($disk);
    }

    /**
     * @return array{disk: string, prefix: string, scoped_prefix: string}
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
        $scopedPrefix = implode('/', $prefixes);

        if ($root !== '') {
            array_unshift($prefixes, $this->assertSafeStorageKey($root));
        }

        return [
            'disk' => $disk,
            'prefix' => implode('/', $prefixes),
            'scoped_prefix' => $scopedPrefix,
        ];
    }
}
