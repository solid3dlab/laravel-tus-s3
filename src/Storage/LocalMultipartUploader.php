<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Storage;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Solid3d\LaravelTusS3\Contracts\MultipartUploader;
use Solid3d\LaravelTusS3\Domain\CompletedPart;

/**
 * Multipart-compatible uploader for local/fake disks (tests and local dev).
 */
final class LocalMultipartUploader implements MultipartUploader
{
    public function __construct(private S3KeyResolver $keys) {}

    public function createMultipartUpload(string $disk, string $objectKey): string
    {
        $this->keys->assertSafeRelativeKey($objectKey);
        $uploadId = bin2hex(random_bytes(16));
        Storage::disk($disk)->makeDirectory($this->partsDirectory($objectKey, $uploadId));

        return $uploadId;
    }

    public function uploadPart(
        string $disk,
        string $objectKey,
        string $uploadId,
        int $partNumber,
        mixed $body,
        int $size,
    ): string {
        $this->keys->assertSafeRelativeKey($objectKey);

        if (! is_resource($body)) {
            throw new RuntimeException('Upload body must be a stream resource.');
        }

        $contents = stream_get_contents($body);

        if ($contents === false || strlen($contents) !== $size) {
            throw new RuntimeException('Failed to read the upload part body.');
        }

        $partPath = $this->partPath($objectKey, $uploadId, $partNumber);
        Storage::disk($disk)->put($partPath, $contents);

        return md5($contents);
    }

    public function completeMultipartUpload(
        string $disk,
        string $objectKey,
        string $uploadId,
        array $parts,
    ): void {
        $this->keys->assertSafeRelativeKey($objectKey);
        $filesystem = Storage::disk($disk);
        $handle = fopen('php://temp', 'w+b');

        if ($handle === false) {
            throw new RuntimeException('Unable to assemble multipart object.');
        }

        try {
            foreach ($parts as $part) {
                $chunk = $filesystem->get($this->partPath($objectKey, $uploadId, $part->partNumber));

                if ($chunk === null) {
                    throw new RuntimeException("Missing multipart part [{$part->partNumber}].");
                }

                fwrite($handle, $chunk);
            }

            rewind($handle);
            $filesystem->writeStream($objectKey, $handle);
        } finally {
            fclose($handle);
            $this->deleteParts($disk, $objectKey, $uploadId);
        }
    }

    public function abortMultipartUpload(string $disk, string $objectKey, string $uploadId): void
    {
        $this->keys->assertSafeRelativeKey($objectKey);
        $this->deleteParts($disk, $objectKey, $uploadId);
    }

    public function listParts(string $disk, string $objectKey, string $uploadId): array
    {
        $this->keys->assertSafeRelativeKey($objectKey);
        $filesystem = Storage::disk($disk);
        $directory = $this->partsDirectory($objectKey, $uploadId);
        $parts = [];

        foreach ($filesystem->files($directory) as $file) {
            $basename = basename($file);

            if (! preg_match('/^part-(\d+)$/', $basename, $matches)) {
                continue;
            }

            $contents = (string) $filesystem->get($file);
            $parts[] = new CompletedPart(
                partNumber: (int) $matches[1],
                etag: md5($contents),
                size: strlen($contents),
            );
        }

        usort($parts, static fn (CompletedPart $a, CompletedPart $b): int => $a->partNumber <=> $b->partNumber);

        return $parts;
    }

    public function deleteObject(string $disk, string $objectKey): void
    {
        $this->keys->assertSafeRelativeKey($objectKey);
        Storage::disk($disk)->delete($objectKey);
    }

    private function partsDirectory(string $objectKey, string $uploadId): string
    {
        return $objectKey.'.parts/'.$uploadId;
    }

    private function partPath(string $objectKey, string $uploadId, int $partNumber): string
    {
        return $this->partsDirectory($objectKey, $uploadId).'/part-'.$partNumber;
    }

    private function deleteParts(string $disk, string $objectKey, string $uploadId): void
    {
        Storage::disk($disk)->deleteDirectory($this->partsDirectory($objectKey, $uploadId));
    }
}
