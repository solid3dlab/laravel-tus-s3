<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Helpers;

use Solid3d\LaravelTusS3\Exceptions\InvalidMetadataException;

final class UploadMetadataParser
{
    /**
     * @return array<string, string>
     */
    public function parse(?string $rawMetadata): array
    {
        if ($rawMetadata === null || trim($rawMetadata) === '') {
            return [];
        }

        $allowed = array_fill_keys((array) config('tus.allowed_metadata_keys', []), true);
        $parsed = [];

        foreach (explode(',', $rawMetadata) as $pair) {
            $pair = trim($pair);

            if ($pair === '') {
                continue;
            }

            $parts = explode(' ', $pair, 2);

            if (count($parts) !== 2) {
                throw new InvalidMetadataException('Upload-Metadata entries must be key/value pairs.');
            }

            [$key, $encoded] = $parts;

            if ($key === '' || preg_match('/^[a-zA-Z0-9_-]+$/', $key) !== 1) {
                throw new InvalidMetadataException('Upload-Metadata contains an invalid key.');
            }

            if ($allowed !== [] && ! isset($allowed[$key])) {
                throw new InvalidMetadataException("Upload-Metadata key [{$key}] is not allowed.");
            }

            $decoded = base64_decode($encoded, true);

            if ($decoded === false) {
                throw new InvalidMetadataException('Upload-Metadata contains invalid base64.');
            }

            if (str_contains($decoded, "\0")) {
                throw new InvalidMetadataException('Upload-Metadata values must not contain NUL bytes.');
            }

            if ($this->looksLikePathTraversal($key, $decoded)) {
                throw new InvalidMetadataException('Upload-Metadata contains a path traversal sequence.');
            }

            $parsed[$key] = $decoded;
        }

        return $parsed;
    }

    private function looksLikePathTraversal(string $key, string $value): bool
    {
        if (! in_array($key, ['name', 'filename', 'relative_path', 'relativePath'], true)) {
            return false;
        }

        $normalized = str_replace('\\', '/', $value);

        return str_contains($normalized, '../')
            || str_contains($normalized, '..\\')
            || str_starts_with($normalized, '/')
            || str_contains($normalized, "\0");
    }
}
