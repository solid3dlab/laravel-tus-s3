# solid3d/laravel-tus-s3

First-party Laravel Tus 1.0 server backed by **S3 multipart uploads** (Exoscale SOS / AWS S3). Designed for stateless web pods: no PVC, no shared filesystem, no temporary local upload files.

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
- The Laravel disk `root` (`AWS_ROOT` = `s` / `p`) is applied by Flysystem / `S3KeyResolver` — clients cannot choose bucket or key.
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
| `TUS_MAX_PART_BYTES` | chunk size | Bounds checksum buffering |
| `FILE_LIBRARY_CHUNK_SIZE_BYTES` | `5242880` | Uppy `chunkSize` — must be ≥ 5 MiB |

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

Schedule hourly (already wired in Relay). Safe to run repeatedly.

### Required S3 permissions

- `s3:CreateMultipartUpload`
- `s3:UploadPart`
- `s3:CompleteMultipartUpload`
- `s3:AbortMultipartUpload`
- `s3:ListMultipartUploadParts`
- `s3:DeleteObject`
- `s3:GetObject` (finalization streams the temp object)

Scope keys to `{AWS_ROOT}/tus/tmp/*`.

## Events

Compatible with Relay listeners:

- `Solid3d\LaravelTusS3\Events\FileUploadCreated` (`$tusFile`)
- `Solid3d\LaravelTusS3\Events\FileUploadFinished` (`$tusFile`)

`TusFile`: `id`, `path` (relative object key), `disk`, `metadata`.

## Finalization note

Prefer streaming the completed temporary object (`Storage::readStream`). If a downstream library requires a local path, spool with a hard byte bound (Relay’s `LocalFile::pathOrSpool`) and delete both the spool and the S3 temp object afterward.
