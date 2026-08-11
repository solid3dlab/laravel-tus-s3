<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Contracts;

use Solid3d\LaravelTusS3\Domain\CompletedPart;

interface MultipartUploader
{
    /**
     * Start a multipart upload for a server-generated relative object key.
     *
     * @return string Multipart upload ID
     */
    public function createMultipartUpload(string $disk, string $objectKey): string;

    /**
     * Upload one part. $body is a readable stream resource.
     */
    public function uploadPart(
        string $disk,
        string $objectKey,
        string $uploadId,
        int $partNumber,
        mixed $body,
        int $size,
    ): string;

    /**
     * @param  list<CompletedPart>  $parts
     */
    public function completeMultipartUpload(
        string $disk,
        string $objectKey,
        string $uploadId,
        array $parts,
    ): void;

    public function abortMultipartUpload(string $disk, string $objectKey, string $uploadId): void;

    /**
     * @return list<CompletedPart>
     */
    public function listParts(string $disk, string $objectKey, string $uploadId): array;

    public function deleteObject(string $disk, string $objectKey): void;
}
