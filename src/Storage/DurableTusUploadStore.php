<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Storage;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Solid3d\LaravelTusS3\Contracts\MultipartUploader;
use Solid3d\LaravelTusS3\Contracts\TusUploadStore;
use Solid3d\LaravelTusS3\Domain\CompletedPart;
use Solid3d\LaravelTusS3\Enums\UploadStatus;
use Solid3d\LaravelTusS3\Exceptions\ChecksumAlgorithmMismatchException;
use Solid3d\LaravelTusS3\Exceptions\ChecksumMismatchException;
use Solid3d\LaravelTusS3\Exceptions\FileAppendException;
use Solid3d\LaravelTusS3\Exceptions\FileNotFoundException;
use Solid3d\LaravelTusS3\Exceptions\OffsetMismatchException;
use Solid3d\LaravelTusS3\Exceptions\UploadConflictException;
use Solid3d\LaravelTusS3\Helpers\ObjectKeyGenerator;
use Solid3d\LaravelTusS3\Helpers\TusFile;
use Solid3d\LaravelTusS3\Models\TusUpload;
use Throwable;

final class DurableTusUploadStore implements TusUploadStore
{
    public function __construct(
        private MultipartUploader $uploader,
        private ObjectKeyGenerator $keys,
    ) {}

    public function create(int $uploadLength, array $metadata): TusFile
    {
        $disk = (string) config('tus.storage_disk');
        $id = $this->keys->uploadId();
        $objectKey = $this->keys->temporaryKey($id);
        $uploadId = $this->uploader->createMultipartUpload($disk, $objectKey);

        $expirationMinutes = (int) config('tus.upload_expiration');
        $expiresAt = $expirationMinutes > 0 ? now()->addMinutes($expirationMinutes) : null;

        try {
            $upload = TusUpload::query()->create([
                'id' => $id,
                'disk' => $disk,
                'object_key' => $objectKey,
                'multipart_upload_id' => $uploadId,
                'expected_size' => $uploadLength,
                'offset' => 0,
                'next_part_number' => 1,
                'status' => UploadStatus::Pending,
                'expires_at' => $expiresAt,
                'metadata' => $metadata,
                'parts' => [],
            ]);
        } catch (Throwable $exception) {
            try {
                $this->uploader->abortMultipartUpload($disk, $objectKey, $uploadId);
            } catch (Throwable) {
                //
            }

            throw $exception;
        }

        return $upload->toTusFile();
    }

    public function find(string $id): TusFile
    {
        return $this->uploadOrFail($id)->toTusFile();
    }

    public function offset(string $id): int
    {
        return $this->uploadOrFail($id)->offset;
    }

    public function expectedLength(string $id): int
    {
        return $this->uploadOrFail($id)->expected_size;
    }

    public function expiresAt(string $id): ?DateTimeInterface
    {
        return $this->uploadOrFail($id)->expires_at;
    }

    public function append(
        string $id,
        int $expectedOffset,
        mixed $body,
        int $length,
        ?string $checksumAlgorithm = null,
        ?string $checksumHash = null,
    ): int {
        if ($length < 0) {
            throw new FileAppendException(message: 'Invalid upload chunk length.');
        }

        $maxPart = (int) config('tus.max_part_bytes');

        if ($maxPart > 0 && $length > $maxPart) {
            throw new FileAppendException(statusCode: 413, message: 'Upload chunk exceeds the configured maximum part size.');
        }

        [$stream, $payload] = $this->bufferBody($body, $length);
        $this->assertChecksum($checksumAlgorithm, $checksumHash, $payload);

        $lockOwner = (string) Str::uuid();
        $partNumber = 0;
        $disk = '';
        $objectKey = '';
        $multipartUploadId = '';

        try {
            /** @var TusUpload $upload */
            $upload = DB::transaction(function () use ($id, $expectedOffset, $length, $lockOwner, &$partNumber, &$disk, &$objectKey, &$multipartUploadId): TusUpload {
                $upload = TusUpload::query()->whereKey($id)->lockForUpdate()->first();

                if ($upload === null) {
                    throw new FileNotFoundException;
                }

                $this->reconcileFromStorage($upload);

                if ($upload->offset === $expectedOffset + $length) {
                    // Idempotent retry: this exact chunk was already persisted
                    // (including after a prior successful completion).
                    return $upload;
                }

                $this->assertMutable($upload);

                if ($upload->offset !== $expectedOffset) {
                    throw new OffsetMismatchException($upload->offset);
                }

                if ($expectedOffset + $length > $upload->expected_size) {
                    throw new FileAppendException(statusCode: 400, message: 'Upload exceeds the declared Upload-Length.');
                }

                $isFinal = ($expectedOffset + $length) === $upload->expected_size;
                $minPart = (int) config('tus.min_part_size', 5_242_880);

                if (! $isFinal && $length < $minPart) {
                    throw new FileAppendException(
                        statusCode: 400,
                        message: "Non-final Tus chunks must be at least {$minPart} bytes for S3 multipart uploads.",
                    );
                }

                if ($upload->hasActivePatchLock()) {
                    throw new UploadConflictException('Another PATCH is currently in progress for this upload.');
                }

                $partNumber = $upload->next_part_number;
                $disk = $upload->disk;
                $objectKey = $upload->object_key;
                $multipartUploadId = (string) $upload->multipart_upload_id;

                $upload->status = UploadStatus::Uploading;
                $upload->patch_lock_owner = $lockOwner;
                $upload->patch_lock_at = now();
                $upload->save();

                return $upload;
            }, 5);

            if ($upload->offset === $expectedOffset + $length && $upload->patch_lock_owner !== $lockOwner) {
                return $upload->offset;
            }

            rewind($stream);
            $etag = $this->uploader->uploadPart(
                $disk,
                $objectKey,
                $multipartUploadId,
                $partNumber,
                $stream,
                $length,
            );

            $shouldComplete = false;
            $completedParts = [];

            $offset = DB::transaction(function () use (
                $id,
                $expectedOffset,
                $length,
                $lockOwner,
                $partNumber,
                $etag,
                &$shouldComplete,
                &$completedParts,
            ): int {
                $upload = TusUpload::query()->whereKey($id)->lockForUpdate()->first();

                if ($upload === null) {
                    throw new FileNotFoundException;
                }

                if ($upload->patch_lock_owner !== $lockOwner) {
                    $this->reconcileFromStorage($upload);

                    if ($upload->offset === $expectedOffset + $length) {
                        return $upload->offset;
                    }

                    throw new UploadConflictException('The upload lock was lost before the part could be committed.');
                }

                if ($upload->offset !== $expectedOffset) {
                    throw new OffsetMismatchException($upload->offset);
                }

                $parts = $upload->parts ?? [];
                $parts[] = (new CompletedPart($partNumber, $etag, $length))->toArray();

                $upload->parts = $parts;
                $upload->offset = $expectedOffset + $length;
                $upload->next_part_number = $partNumber + 1;
                $upload->patch_lock_owner = null;
                $upload->patch_lock_at = null;

                if ($upload->offset === $upload->expected_size) {
                    $shouldComplete = true;
                    $completedParts = $upload->completedParts();
                    $upload->status = UploadStatus::Uploading;
                    $upload->save();
                } else {
                    $upload->status = UploadStatus::Pending;
                    $upload->save();
                }

                return $upload->offset;
            }, 5);

            if ($shouldComplete) {
                $this->finalizeMultipart($id, $completedParts);
            }

            return $offset;
        } catch (Throwable $exception) {
            $this->releaseLock($id, $lockOwner);
            $this->attemptReconcile($id);

            throw $exception;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function abort(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $upload = TusUpload::query()->whereKey($id)->lockForUpdate()->first();

            if ($upload === null) {
                return false;
            }

            if ($upload->status === UploadStatus::Cancelled) {
                return true;
            }

            if ($upload->status === UploadStatus::Completed) {
                $this->safeDeleteObject($upload);

                $upload->status = UploadStatus::Cancelled;
                $upload->multipart_upload_id = null;
                $upload->patch_lock_owner = null;
                $upload->patch_lock_at = null;
                $upload->save();

                return true;
            }

            $this->safeAbortMultipart($upload);
            $this->safeDeleteObject($upload);

            $upload->status = UploadStatus::Cancelled;
            $upload->multipart_upload_id = null;
            $upload->patch_lock_owner = null;
            $upload->patch_lock_at = null;
            $upload->save();

            return true;
        }, 5);
    }

    public function delete(string $id): bool
    {
        return $this->abort($id);
    }

    public function pruneExpired(): int
    {
        $expirationMinutes = (int) config('tus.upload_expiration');

        if ($expirationMinutes < 1 || ! Schema::hasTable('tus_uploads')) {
            return 0;
        }

        $cleaned = 0;

        TusUpload::query()
            ->whereIn('status', [UploadStatus::Pending->value, UploadStatus::Uploading->value])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(50, function ($uploads) use (&$cleaned): void {
                foreach ($uploads as $upload) {
                    DB::transaction(function () use ($upload, &$cleaned): void {
                        /** @var TusUpload|null $locked */
                        $locked = TusUpload::query()->whereKey($upload->id)->lockForUpdate()->first();

                        if ($locked === null || $locked->status->isTerminal()) {
                            return;
                        }

                        if ($locked->expires_at === null || $locked->expires_at->isFuture()) {
                            return;
                        }

                        $this->safeAbortMultipart($locked);
                        $this->safeDeleteObject($locked);
                        $locked->status = UploadStatus::Expired;
                        $locked->multipart_upload_id = null;
                        $locked->patch_lock_owner = null;
                        $locked->patch_lock_at = null;
                        $locked->save();
                        $cleaned++;
                    }, 3);
                }
            });

        // Remove terminal records older than twice the expiration window.
        $deleted = TusUpload::query()
            ->whereIn('status', [
                UploadStatus::Cancelled->value,
                UploadStatus::Expired->value,
                UploadStatus::Completed->value,
            ])
            ->where('updated_at', '<=', now()->subMinutes($expirationMinutes * 2))
            ->delete();

        return $cleaned + $deleted;
    }

    private function uploadOrFail(string $id): TusUpload
    {
        $upload = TusUpload::query()->find($id);

        if ($upload === null || $upload->status === UploadStatus::Cancelled || $upload->status === UploadStatus::Expired) {
            throw new FileNotFoundException;
        }

        if ($upload->isExpired() && ! $upload->status->isTerminal()) {
            $this->abortExpired($upload);
            throw new FileNotFoundException;
        }

        return $upload;
    }

    private function abortExpired(TusUpload $upload): void
    {
        DB::transaction(function () use ($upload): void {
            $locked = TusUpload::query()->whereKey($upload->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status->isTerminal()) {
                return;
            }

            $this->safeAbortMultipart($locked);
            $this->safeDeleteObject($locked);
            $locked->status = UploadStatus::Expired;
            $locked->multipart_upload_id = null;
            $locked->patch_lock_owner = null;
            $locked->patch_lock_at = null;
            $locked->save();
        }, 3);
    }

    private function assertMutable(TusUpload $upload): void
    {
        if ($upload->status === UploadStatus::Completed) {
            throw new UploadConflictException('The upload is already complete.', 403);
        }

        if ($upload->status === UploadStatus::Cancelled || $upload->status === UploadStatus::Expired) {
            throw new FileNotFoundException;
        }

        if ($upload->isExpired()) {
            $this->abortExpired($upload);
            throw new FileNotFoundException;
        }
    }

    /**
     * @param  list<CompletedPart>  $parts
     */
    private function finalizeMultipart(string $id, array $parts): void
    {
        $upload = TusUpload::query()->find($id);

        if ($upload === null || $upload->status === UploadStatus::Completed) {
            return;
        }

        if ($upload->multipart_upload_id !== null) {
            $this->uploader->completeMultipartUpload(
                $upload->disk,
                $upload->object_key,
                $upload->multipart_upload_id,
                $parts,
            );
        }

        DB::transaction(function () use ($id): void {
            $locked = TusUpload::query()->whereKey($id)->lockForUpdate()->first();

            if ($locked === null || $locked->status === UploadStatus::Completed) {
                return;
            }

            $locked->status = UploadStatus::Completed;
            $locked->completed_at = now();
            $locked->multipart_upload_id = null;
            $locked->patch_lock_owner = null;
            $locked->patch_lock_at = null;
            $locked->save();
        }, 5);
    }

    private function completeOnce(TusUpload $upload): void
    {
        if ($upload->status === UploadStatus::Completed) {
            return;
        }

        $parts = $upload->completedParts();
        $uploadId = $upload->multipart_upload_id;

        // Persist intent first so a crash after S3 success can be reconciled.
        $upload->status = UploadStatus::Uploading;
        $upload->save();

        if ($uploadId !== null) {
            $this->uploader->completeMultipartUpload(
                $upload->disk,
                $upload->object_key,
                $uploadId,
                $parts,
            );
        }

        $upload->status = UploadStatus::Completed;
        $upload->completed_at = now();
        $upload->multipart_upload_id = null;
        $upload->save();
    }

    private function reconcileFromStorage(TusUpload $upload): void
    {
        if ($upload->multipart_upload_id === null || $upload->status === UploadStatus::Completed) {
            return;
        }

        try {
            $remoteParts = $this->uploader->listParts(
                $upload->disk,
                $upload->object_key,
                $upload->multipart_upload_id,
            );
        } catch (Throwable) {
            return;
        }

        if ($remoteParts === []) {
            return;
        }

        $known = [];
        foreach ($upload->parts ?? [] as $part) {
            $known[(int) $part['part_number']] = $part;
        }

        $changed = false;
        $offset = 0;
        $maxPart = 0;

        foreach ($remoteParts as $remote) {
            $known[$remote->partNumber] = $remote->toArray();
            $offset += $remote->size;
            $maxPart = max($maxPart, $remote->partNumber);
            $changed = true;
        }

        if (! $changed) {
            return;
        }

        ksort($known);
        $upload->parts = array_values($known);
        $upload->offset = $offset;
        $upload->next_part_number = $maxPart + 1;

        if ($upload->offset === $upload->expected_size && $upload->status !== UploadStatus::Completed) {
            $this->completeOnce($upload);
        } else {
            $upload->save();
        }
    }

    private function attemptReconcile(string $id): void
    {
        try {
            DB::transaction(function () use ($id): void {
                $upload = TusUpload::query()->whereKey($id)->lockForUpdate()->first();

                if ($upload === null) {
                    return;
                }

                $this->reconcileFromStorage($upload);
            }, 3);
        } catch (Throwable) {
            // Best-effort recovery path.
        }
    }

    private function releaseLock(string $id, string $lockOwner): void
    {
        try {
            TusUpload::query()
                ->whereKey($id)
                ->where('patch_lock_owner', $lockOwner)
                ->update([
                    'patch_lock_owner' => null,
                    'patch_lock_at' => null,
                    'status' => UploadStatus::Pending->value,
                ]);
        } catch (Throwable) {
            //
        }
    }

    /**
     * @param  resource  $body
     * @return array{0: resource, 1: string}
     */
    private function bufferBody(mixed $body, int $length): array
    {
        if (! is_resource($body)) {
            throw new FileAppendException(message: 'Upload body must be a stream resource.');
        }

        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new FileAppendException(message: 'Unable to allocate a temporary upload buffer.');
        }

        $copied = stream_copy_to_stream($body, $stream, $length);

        if ($copied === false || $copied !== $length) {
            fclose($stream);
            throw new FileAppendException(message: 'Unable to read the upload chunk.');
        }

        rewind($stream);
        $payload = stream_get_contents($stream);

        if ($payload === false) {
            fclose($stream);
            throw new FileAppendException(message: 'Unable to buffer the upload chunk.');
        }

        rewind($stream);

        return [$stream, $payload];
    }

    private function assertChecksum(?string $algorithm, ?string $hash, string $payload): void
    {
        if ($algorithm === null || $hash === null) {
            return;
        }

        if (! in_array($algorithm, (array) config('tus.checksum_algorithm'), true)) {
            throw new ChecksumAlgorithmMismatchException;
        }

        $expected = base64_decode($hash, true);

        if ($expected === false || ! hash_equals($expected, hash($algorithm, $payload, true))) {
            throw new ChecksumMismatchException;
        }
    }

    private function safeAbortMultipart(TusUpload $upload): void
    {
        if ($upload->multipart_upload_id === null) {
            return;
        }

        try {
            $this->uploader->abortMultipartUpload(
                $upload->disk,
                $upload->object_key,
                $upload->multipart_upload_id,
            );
        } catch (Throwable) {
            // Idempotent cleanup.
        }
    }

    private function safeDeleteObject(TusUpload $upload): void
    {
        try {
            $this->uploader->deleteObject($upload->disk, $upload->object_key);
        } catch (Throwable) {
            // Idempotent cleanup.
        }
    }
}
