# Laravel TUS S3

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solid3d/laravel-tus-s3.svg?style=flat-square)](https://packagist.org/packages/solid3d/laravel-tus-s3)
[![GitHub Tests Action Status](https://github.com/solid3dlab/laravel-tus-s3/actions/workflows/run-tests.yml/badge.svg)](https://github.com/solid3dlab/laravel-tus-s3/actions/workflows/run-tests.yml)
[![GitHub Code Style Action Status](https://github.com/solid3dlab/laravel-tus-s3/actions/workflows/php-code-style.yml/badge.svg)](https://github.com/solid3dlab/laravel-tus-s3/actions/workflows/php-code-style.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/solid3d/laravel-tus-s3.svg?style=flat-square)](https://packagist.org/packages/solid3d/laravel-tus-s3)

Tus 1.0 server for Laravel, backed by S3 multipart uploads. Built for stateless web pods: no shared filesystem and no local temp files.

Requires PHP 8.5+ and Laravel 13.

## Installation

You can install the package via Composer:

```bash
composer require solid3d/laravel-tus-s3
```

Run the migrations (creates `tus_uploads`):

```bash
php artisan migrate
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag=tus-config
```

Point `TUS_STORAGE_DISK` at an S3-compatible Laravel disk. Object keys are generated server-side under `temporary_prefix` — clients cannot choose the bucket or key.

| Env | Default | Notes |
|-----|---------|-------|
| `TUS_STORAGE_DISK` | `FILESYSTEM_DISK` / `s3` | Disk for temporary objects |
| `TUS_TEMPORARY_PREFIX` | `tus/tmp` | Relative to the disk root |
| `TUS_UPLOAD_EXPIRATION` | `60` | Minutes |
| `TUS_PATH` | `tus` | Route prefix |
| `TUS_OWNERSHIP_ENABLED` | `true` | Bind authenticated uploads to their creator |
| `TUS_MIN_PART_SIZE` | `5242880` | S3 non-final part minimum (5 MiB) |
| `TUS_MAX_PART_BYTES` | `5242880` | Keep Uppy `chunkSize` ≤ this |

Routes are registered automatically at `/tus`. Default middleware is `web`; add `auth` (or replace the stack) via `tus.middleware` when anonymous creation should be prohibited.

### S3 permissions

- `s3:CreateMultipartUpload`
- `s3:UploadPart`
- `s3:CompleteMultipartUpload`
- `s3:AbortMultipartUpload`
- `s3:ListMultipartUploadParts`
- `s3:DeleteObject`
- `s3:GetObject` (finalization streams the temp object)

Scope keys to `{disk-root}/tus/tmp/*`. Native and scoped S3 disks are supported.

## Usage

### Uppy

```ts
.use(Tus, {
  endpoint: '/tus',
  chunkSize: 5_242_880, // >= 5 MiB for S3 multipart
})
```

### Completed uploads

Listen for `FileUploadFinished` (`$event->tusFile`). It fires only for the request that actually completes the upload.

```php
use Solid3d\LaravelTusS3\Events\FileUploadFinished;

public function handle(FileUploadFinished $event): void
{
    $fingerprint = $event->tusFile->fingerprint(maximumBytes: 1_073_741_824);

    $event->tusFile->moveTo(
        disk: 's3',
        path: "library/{$fingerprint->sha256}.bin",
    );
}
```

`TusFile` also exposes `id`, `path`, `disk`, and `metadata`. Use `delete()` when you keep an existing object instead of moving the temp file.

`FileUploadCreated` is available if you need to react when an upload is created.

### Ownership

Anonymous uploads work out of the box — the unguessable upload URL is the credential.

When a Laravel user is authenticated, the upload is bound to that user. Later `HEAD` / `PATCH` / `DELETE` must come from the same user. Set `tus.ownership.enabled` to `false` for URL-only access, or implement `UploadOwnerResolver` and set `tus.ownership.resolver`.

### Scheduling

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('tus:prune')->hourly();
Schedule::command('tus:dispatch-finished')->everyMinute();
```

`tus:prune` aborts expired multipart uploads and deletes stale rows. `tus:dispatch-finished` delivers `FileUploadFinished` if a worker died after S3 completed.

## Protocol

Tus 1.0: `OPTIONS`, `POST` (creation), `HEAD`, `PATCH`, `DELETE` (termination).

Extensions: `creation`, `expiration`, `checksum`, `termination`.

Not implemented: `concatenation`, `creation-with-upload`.

## Testing

```bash
composer test
```

The default suite uses local Flysystem fakes. To also run S3 multipart tests against MinIO:

```bash
docker run --detach --name minio \
  --publish 9000:9000 \
  --env MINIO_ROOT_USER=minioadmin \
  --env MINIO_ROOT_PASSWORD=minioadmin \
  pgsty/minio:RELEASE.2026-06-18T00-00-00Z server /data

TUS_S3_INTEGRATION=1 TUS_S3_ENDPOINT=http://127.0.0.1:9000 vendor/bin/pest
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Oliver Kaufmann](https://github.com/okaufmann)
- [All Contributors](../../contributors)

## License

The MIT License (MIT).
