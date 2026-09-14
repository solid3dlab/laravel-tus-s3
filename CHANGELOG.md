# Changelog

All notable changes to Laravel TUS S3 will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com),
and this project adheres to [Conventional Commits](https://www.conventionalcommits.org).

## Unreleased

### Added

- Optional ownership for authenticated uploads while preserving anonymous uploads.
- Scoped S3 disk support and MinIO-backed multipart integration coverage.
- `Upload-Metadata` on `HEAD` responses.

### Fixed

- Reject malformed checksum headers instead of silently skipping verification.
- Recover when S3 completed an object before the database completion commit.
- Emit `FileUploadFinished` only once for idempotent PATCH retries.
- Publish configuration through the documented `tus-config` tag without publishing duplicate migrations.
