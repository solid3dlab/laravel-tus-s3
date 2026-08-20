# Laravel TUS S3

[![Run Tests](https://github.com/solid3dlab/laravel-tus-s3/actions/workflows/run-tests.yml/badge.svg)](https://github.com/solid3dlab/laravel-tus-s3/actions/workflows/run-tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/solid3d/laravel-tus-s3.svg?style=flat-square)](https://packagist.org/packages/solid3d/laravel-tus-s3)

Laravel Tus 1.0 server backed by **S3 multipart uploads**. Designed for stateless web pods: no PVC, no shared filesystem, no temporary local upload files.

Requires PHP 8.5+ and Laravel 13.

## Protocol subset

| Method | Purpose |
|--------|---------|
| `OPTIONS` | Capability discovery |
| `POST` | Create upload (`creation`) |
| `HEAD` | Authoritative `Upload-Offset` / length / expiry |
| `PATCH` | Upload next chunk |
| `DELETE` | Abort (`termination`) |

Extensions: `creation`, `expiration`, `checksum`, `termination`.

Not implemented: `concatenation`, `creation-with-upload`.

## Architecture

```
HTTP (TusUploadController)
  → TusUploadStore (DurableTusUploadStore)
      → PostgreSQL (tus_uploads: offset, parts, multipart id, expiry)
      → MultipartUploader
           → S3MultipartUploader   (production)
           → LocalMultipartUploader (local / Storage::fake)
```

- Object keys are **always** generated server-side under `tus.temporary_prefix` (default `tus/tmp/{ulid}`).
- The Laravel disk `root` is applied by Flysystem / `S3KeyResolver` — clients cannot choose bucket or key.
- PATCH takes a short row lock, uploads the part outside the transaction, then commits ETag/offset atomically.
- If S3 succeeds but the DB update fails, the next PATCH reconciles via `ListParts`.

## Configuration

| Env | Default | Notes |
|-----|---------|-------|
| `TUS_STORAGE_DISK` | `FILESYSTEM_DISK` / `s3` | Disk for temporary objects |
| `TUS_TEMPORARY_PREFIX` | `tus/tmp` | Relative to disk root |
| `TUS_UPLOAD_EXPIRATION` | `60` | Minutes |
| `TUS_PATH` | `tus` | Route prefix |
| `TUS_MIN_PART_SIZE` | `5242880` | S3 non-final part minimum (5 MiB) |
| `TUS_MAX_PART_BYTES` | `5242880` | Bounds checksum buffering; keep Uppy `chunkSize` ≤ this |

Publish config (optional):

```bash
php artisan vendor:publish --tag=tus-config
```

## Uppy

```ts
.use(Tus, {
  endpoint: '/tus',
  chunkSize: 5_242_880, // >= 5 MiB for S3 multipart
})
```

## Operations

```bash
php artisan tus:prune   # abort expired multipart uploads; delete stale rows
```

Schedule hourly. Safe to run repeatedly.

### Required S3 permissions

- `s3:CreateMultipartUpload`
- `s3:UploadPart`
- `s3:CompleteMultipartUpload`
- `s3:AbortMultipartUpload`
- `s3:ListMultipartUploadParts`
- `s3:DeleteObject`
- `s3:GetObject` (finalization streams the temp object)

Scope keys to `{disk-root}/tus/tmp/*`.

## Events

- `Solid3d\LaravelTusS3\Events\FileUploadCreated` (`$tusFile`)
- `Solid3d\LaravelTusS3\Events\FileUploadFinished` (`$tusFile`)

`TusFile` exposes `id`, `path` (relative object key), `disk`, and `metadata`. Completed
uploads can be consumed without handling streams:

```php
public function handle(FileUploadFinished $event): void
{
    $fingerprint = $event->tusFile->fingerprint(maximumBytes: 1_073_741_824);

    $stored = $event->tusFile->moveTo(
        disk: 's3',
        path: "library/{$fingerprint->sha256}.bin",
    );

    // $stored points to the durable object; the temporary object is gone.
}
```

- `fingerprint()` returns the SHA-256 and size in one bounded pass.
- `moveTo()` uses a storage-native move on the same disk and safely streams between
  different disks.
- `delete()` removes a completed temporary upload when the application reuses an
  existing object.
