<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Contracts;

use Solid3d\LaravelTusS3\Helpers\TusFile;

interface TusUploadStore
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function create(int $uploadLength, array $metadata): TusFile;

    public function find(string $id): TusFile;

    public function offset(string $id): int;

    public function expectedLength(string $id): int;

    public function expiresAt(string $id): ?\DateTimeInterface;

    /**
     * Append the next chunk. Returns the new authoritative offset.
     *
     * @param  resource  $body
     */
    public function append(
        string $id,
        int $expectedOffset,
        mixed $body,
        int $length,
        ?string $checksumAlgorithm = null,
        ?string $checksumHash = null,
    ): int;

    /**
     * Abort an incomplete multipart upload and mark cancelled.
     */
    public function abort(string $id): bool;

    /**
     * Delete a completed temporary object (and mark cancelled if still open).
     */
    public function delete(string $id): bool;

    /**
     * Abort expired multipart uploads and remove stale records/objects.
     *
     * @return int Number of uploads cleaned up
     */
    public function pruneExpired(): int;
}
