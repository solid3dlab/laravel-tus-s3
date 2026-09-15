<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Storage;

use InvalidArgumentException;
use RuntimeException;
use Solid3d\LaravelTusS3\Domain\FileFingerprint;
use Solid3d\LaravelTusS3\Domain\StoredFile;
use Solid3d\LaravelTusS3\Helpers\TusFile;

final class TusFileStorage
{
    public function __construct(private S3KeyResolver $keys) {}

    public function fingerprint(TusFile $file, ?int $maximumBytes = null): FileFingerprint
    {
        $stream = $this->keys->filesystem($file->disk)->readStream($file->path);
        if (! is_resource($stream)) {
            throw new RuntimeException('The completed upload could not be opened.');
        }

        $hash = hash_init('sha256');
        $size = 0;

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1_048_576);
                if ($chunk === false) {
                    throw new RuntimeException('The completed upload could not be read.');
                }

                $size += strlen($chunk);
                if ($maximumBytes !== null && $size > $maximumBytes) {
                    throw new RuntimeException('The completed upload exceeds the maximum allowed size.');
                }

                hash_update($hash, $chunk);
            }
        } finally {
            fclose($stream);
        }

        return new FileFingerprint(hash_final($hash), $size);
    }

    public function move(TusFile $file, string $disk, string $path): StoredFile
    {
        $this->keys->assertSafeRelativeKey($file->path);
        $path = $this->keys->assertSafeStorageKey($path);

        if ($file->disk === $disk && $file->path === $path) {
            throw new InvalidArgumentException('The source and destination paths must be different.');
        }

        $source = $this->keys->filesystem($file->disk);
        $destination = $this->keys->filesystem($disk);

        if ($file->disk === $disk) {
            if (! $source->move($file->path, $path)) {
                throw new RuntimeException('The completed upload could not be moved.');
            }
        } elseif ($this->keys->canCopyServerSide($file->disk, $disk)) {
            // Scoped disks over one bucket: let the object store do the copy rather
            // than pulling every byte down into PHP and pushing it back up.
            $base = $this->keys->filesystem($this->keys->baseDisk($file->disk));

            if (! $base->move(
                $this->keys->baseRelativeKey($file->disk, $file->path),
                $this->keys->baseRelativeKey($disk, $path),
            )) {
                throw new RuntimeException('The completed upload could not be moved.');
            }
        } else {
            $stream = $source->readStream($file->path);
            if (! is_resource($stream)) {
                throw new RuntimeException('The completed upload could not be opened.');
            }

            try {
                if (! $destination->writeStream($path, $stream)) {
                    throw new RuntimeException('The completed upload could not be stored.');
                }
            } finally {
                fclose($stream);
            }

            if (! $source->delete($file->path)) {
                throw new RuntimeException('The temporary upload could not be removed.');
            }
        }

        if (! $destination->exists($path)) {
            throw new RuntimeException('The stored upload could not be verified.');
        }

        return new StoredFile(
            id: $file->id,
            disk: $disk,
            path: $path,
            metadata: $file->metadata,
        );
    }

    public function delete(TusFile $file): void
    {
        $this->keys->assertSafeRelativeKey($file->path);

        if (! $this->keys->filesystem($file->disk)->delete($file->path)) {
            throw new RuntimeException('The completed upload could not be deleted.');
        }
    }
}
