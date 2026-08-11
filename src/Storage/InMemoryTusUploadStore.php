<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Storage;

use DateTimeInterface;
use Illuminate\Support\Str;
use Solid3d\LaravelTusS3\Contracts\TusUploadStore;
use Solid3d\LaravelTusS3\Enums\UploadStatus;
use Solid3d\LaravelTusS3\Exceptions\ChecksumAlgorithmMismatchException;
use Solid3d\LaravelTusS3\Exceptions\ChecksumMismatchException;
use Solid3d\LaravelTusS3\Exceptions\FileAppendException;
use Solid3d\LaravelTusS3\Exceptions\FileNotFoundException;
use Solid3d\LaravelTusS3\Exceptions\OffsetMismatchException;
use Solid3d\LaravelTusS3\Exceptions\UploadConflictException;
use Solid3d\LaravelTusS3\Helpers\ObjectKeyGenerator;
use Solid3d\LaravelTusS3\Helpers\TusFile;

/**
 * In-memory Tus store for protocol unit tests (no S3, no database).
 */
final class InMemoryTusUploadStore implements TusUploadStore
{
    /** @var array<string, array<string, mixed>> */
    private array $uploads = [];

    /** @var array<string, string> */
    private array $objects = [];

    public function __construct(private ObjectKeyGenerator $keys) {}

    public function create(int $uploadLength, array $metadata): TusFile
    {
        $id = $this->keys->uploadId();
        $objectKey = $this->keys->temporaryKey($id);
        $expirationMinutes = (int) config('tus.upload_expiration');

        $this->uploads[$id] = [
            'id' => $id,
            'disk' => (string) config('tus.storage_disk'),
            'object_key' => $objectKey,
            'expected_size' => $uploadLength,
            'offset' => 0,
            'status' => UploadStatus::Pending,
            'expires_at' => $expirationMinutes > 0 ? now()->addMinutes($expirationMinutes) : null,
            'metadata' => $metadata,
            'body' => '',
            'lock' => null,
        ];

        return $this->toFile($id);
    }

    public function find(string $id): TusFile
    {
        return $this->toFile($id);
    }

    public function offset(string $id): int
    {
        return (int) $this->require($id)['offset'];
    }

    public function expectedLength(string $id): int
    {
        return (int) $this->require($id)['expected_size'];
    }

    public function expiresAt(string $id): ?DateTimeInterface
    {
        return $this->require($id)['expires_at'];
    }

    public function append(
        string $id,
        int $expectedOffset,
        mixed $body,
        int $length,
        ?string $checksumAlgorithm = null,
        ?string $checksumHash = null,
    ): int {
        $upload = &$this->require($id);

        if ($upload['status'] === UploadStatus::Completed) {
            throw new UploadConflictException('The upload is already complete.', 403);
        }

        if ($upload['expires_at'] !== null && $upload['expires_at']->isPast()) {
            $upload['status'] = UploadStatus::Expired;
            throw new FileNotFoundException;
        }

        if ($upload['lock'] !== null) {
            throw new UploadConflictException('Another PATCH is currently in progress for this upload.');
        }

        if ($upload['offset'] === $expectedOffset + $length) {
            return $upload['offset'];
        }

        if ($upload['offset'] !== $expectedOffset) {
            throw new OffsetMismatchException($upload['offset']);
        }

        $chunk = is_resource($body) ? stream_get_contents($body) : (string) $body;

        if ($chunk === false || strlen($chunk) !== $length) {
            throw new FileAppendException(message: 'Unable to read the upload chunk.');
        }

        if ($checksumAlgorithm !== null && $checksumHash !== null) {
            if (! in_array($checksumAlgorithm, (array) config('tus.checksum_algorithm'), true)) {
                throw new ChecksumAlgorithmMismatchException;
            }

            $expected = base64_decode($checksumHash, true);

            if ($expected === false || ! hash_equals($expected, hash($checksumAlgorithm, $chunk, true))) {
                throw new ChecksumMismatchException;
            }
        }

        $isFinal = ($expectedOffset + $length) === $upload['expected_size'];
        $minPart = (int) config('tus.min_part_size', 5_242_880);

        if (! $isFinal && $length < $minPart) {
            throw new FileAppendException(statusCode: 400, message: 'Chunk too small for non-final part.');
        }

        $upload['lock'] = (string) Str::uuid();
        $upload['body'] .= $chunk;
        $upload['offset'] = $expectedOffset + $length;
        $upload['lock'] = null;

        if ($upload['offset'] === $upload['expected_size']) {
            $upload['status'] = UploadStatus::Completed;
            $this->objects[$upload['object_key']] = $upload['body'];
        }

        return $upload['offset'];
    }

    public function abort(string $id): bool
    {
        if (! isset($this->uploads[$id])) {
            return false;
        }

        $upload = &$this->uploads[$id];
        $upload['status'] = UploadStatus::Cancelled;
        unset($this->objects[$upload['object_key']]);

        return true;
    }

    public function delete(string $id): bool
    {
        return $this->abort($id);
    }

    public function pruneExpired(): int
    {
        $count = 0;

        foreach ($this->uploads as $id => $upload) {
            if ($upload['expires_at'] !== null && $upload['expires_at']->isPast() && ! $upload['status']->isTerminal()) {
                $this->uploads[$id]['status'] = UploadStatus::Expired;
                $count++;
            }
        }

        return $count;
    }

    public function objectContents(string $objectKey): ?string
    {
        return $this->objects[$objectKey] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function &require(string $id): array
    {
        if (! isset($this->uploads[$id])) {
            throw new FileNotFoundException;
        }

        if (in_array($this->uploads[$id]['status'], [UploadStatus::Cancelled, UploadStatus::Expired], true)) {
            throw new FileNotFoundException;
        }

        return $this->uploads[$id];
    }

    private function toFile(string $id): TusFile
    {
        $upload = $this->require($id);

        return new TusFile(
            id: $id,
            path: $upload['object_key'],
            metadata: [
                ...$upload['metadata'],
                'size' => $upload['expected_size'],
            ],
            disk: $upload['disk'],
        );
    }
}
