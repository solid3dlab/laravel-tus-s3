<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Domain;

final readonly class CompletedPart
{
    public function __construct(
        public int $partNumber,
        public string $etag,
        public int $size,
    ) {}

    /**
     * @return array{part_number: int, etag: string, size: int}
     */
    public function toArray(): array
    {
        return [
            'part_number' => $this->partNumber,
            'etag' => $this->etag,
            'size' => $this->size,
        ];
    }

    /**
     * @param  array{part_number?: int, PartNumber?: int, etag?: string, ETag?: string, size?: int, Size?: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            partNumber: (int) ($data['part_number'] ?? $data['PartNumber'] ?? 0),
            etag: (string) ($data['etag'] ?? $data['ETag'] ?? ''),
            size: (int) ($data['size'] ?? $data['Size'] ?? 0),
        );
    }
}
