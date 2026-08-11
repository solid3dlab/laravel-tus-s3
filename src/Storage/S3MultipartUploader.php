<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Storage;

use Solid3d\LaravelTusS3\Contracts\MultipartUploader;
use Solid3d\LaravelTusS3\Domain\CompletedPart;

final class S3MultipartUploader implements MultipartUploader
{
    public function __construct(private S3KeyResolver $keys) {}

    public function createMultipartUpload(string $disk, string $objectKey): string
    {
        $result = $this->keys->client($disk)->createMultipartUpload([
            'Bucket' => $this->keys->bucket($disk),
            'Key' => $this->keys->absoluteKey($disk, $objectKey),
        ]);

        return (string) $result['UploadId'];
    }

    public function uploadPart(
        string $disk,
        string $objectKey,
        string $uploadId,
        int $partNumber,
        mixed $body,
        int $size,
    ): string {
        $result = $this->keys->client($disk)->uploadPart([
            'Bucket' => $this->keys->bucket($disk),
            'Key' => $this->keys->absoluteKey($disk, $objectKey),
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
            'Body' => $body,
            'ContentLength' => $size,
        ]);

        return trim((string) $result['ETag'], '"');
    }

    public function completeMultipartUpload(
        string $disk,
        string $objectKey,
        string $uploadId,
        array $parts,
    ): void {
        $this->keys->client($disk)->completeMultipartUpload([
            'Bucket' => $this->keys->bucket($disk),
            'Key' => $this->keys->absoluteKey($disk, $objectKey),
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => array_map(
                    static fn (CompletedPart $part): array => [
                        'ETag' => $part->etag,
                        'PartNumber' => $part->partNumber,
                    ],
                    $parts,
                ),
            ],
        ]);
    }

    public function abortMultipartUpload(string $disk, string $objectKey, string $uploadId): void
    {
        $this->keys->client($disk)->abortMultipartUpload([
            'Bucket' => $this->keys->bucket($disk),
            'Key' => $this->keys->absoluteKey($disk, $objectKey),
            'UploadId' => $uploadId,
        ]);
    }

    public function listParts(string $disk, string $objectKey, string $uploadId): array
    {
        $client = $this->keys->client($disk);
        $bucket = $this->keys->bucket($disk);
        $key = $this->keys->absoluteKey($disk, $objectKey);
        $parts = [];
        $partNumberMarker = 0;

        do {
            $result = $client->listParts([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumberMarker' => $partNumberMarker,
            ]);

            foreach ($result['Parts'] ?? [] as $part) {
                $parts[] = new CompletedPart(
                    partNumber: (int) $part['PartNumber'],
                    etag: trim((string) $part['ETag'], '"'),
                    size: (int) $part['Size'],
                );
            }

            $partNumberMarker = (int) ($result['NextPartNumberMarker'] ?? 0);
            $isTruncated = (bool) ($result['IsTruncated'] ?? false);
        } while ($isTruncated);

        return $parts;
    }

    public function deleteObject(string $disk, string $objectKey): void
    {
        $this->keys->assertSafeRelativeKey($objectKey);
        $this->keys->filesystem($disk)->delete($objectKey);
    }
}
