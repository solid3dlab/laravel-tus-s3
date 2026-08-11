<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Endpoint
    |--------------------------------------------------------------------------
    */
    'url' => env('TUS_URL'),
    'path' => env('TUS_PATH', 'tus'),

    /*
    |--------------------------------------------------------------------------
    | HTTP middleware applied to all Tus routes
    |--------------------------------------------------------------------------
    |
    | Applications typically override this in a published config to add auth.
    |
    */
    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Storage disk used for temporary upload objects
    |--------------------------------------------------------------------------
    |
    | Prefer an S3-compatible disk in production. The Flysystem root (e.g.
    | AWS_ROOT = s|p) is always respected — object keys are generated
    | server-side under temporary_prefix and never accepted from clients.
    |
    */
    'storage_disk' => env('TUS_STORAGE_DISK', env('FILESYSTEM_DISK', 's3')),

    /*
    |--------------------------------------------------------------------------
    | Temporary object key prefix (relative to the disk root)
    |--------------------------------------------------------------------------
    */
    'temporary_prefix' => env('TUS_TEMPORARY_PREFIX', 'tus/tmp'),

    /*
    |--------------------------------------------------------------------------
    | Upload size limit (bytes). Null uses post_max_size.
    |--------------------------------------------------------------------------
    |
    | Compared with a strict greater-than, so add one for an inclusive limit.
    |
    */
    'file_size_limit' => env('TUS_FILE_SIZE_LIMIT'),

    /*
    |--------------------------------------------------------------------------
    | Upload expiration (minutes). Null/0 disables automatic expiry.
    |--------------------------------------------------------------------------
    */
    'upload_expiration' => env('TUS_UPLOAD_EXPIRATION', 60),

    /*
    |--------------------------------------------------------------------------
    | Checksum algorithms advertised/accepted by the checksum extension
    |--------------------------------------------------------------------------
    */
    'checksum_algorithm' => ['sha256'],

    /*
    |--------------------------------------------------------------------------
    | Enabled Tus extensions
    |--------------------------------------------------------------------------
    */
    'extensions' => [
        'creation',
        'expiration',
        'checksum',
        'termination',
    ],

    /*
    |--------------------------------------------------------------------------
    | S3 multipart constraints
    |--------------------------------------------------------------------------
    |
    | Non-final parts must be at least min_part_size (S3 requires 5 MiB).
    | max_part_bytes bounds in-memory/php://temp buffering for checksums.
    | Configure Uppy chunkSize >= min_part_size (default: 5_242_880).
    |
    */
    'min_part_size' => (int) env('TUS_MIN_PART_SIZE', 5_242_880),
    'max_part_bytes' => (int) env('TUS_MAX_PART_BYTES', 5_242_880),

    /*
    |--------------------------------------------------------------------------
    | PATCH lock lease (seconds)
    |--------------------------------------------------------------------------
    */
    'patch_lock_ttl' => (int) env('TUS_PATCH_LOCK_TTL', 120),

    /*
    |--------------------------------------------------------------------------
    | Allowed Upload-Metadata keys (strict allowlist)
    |--------------------------------------------------------------------------
    */
    'allowed_metadata_keys' => [
        'name',
        'filename',
        'type',
        'filetype',
        'session_id',
        'upload_token',
        'relative_path',
        'relativePath',
    ],
];
