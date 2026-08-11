<?php

declare(strict_types=1);

use Solid3d\LaravelTusS3\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * @param  array<string, string>  $metadata
 * @return array<string, string>
 */
function tusHeaders(int $size, array $metadata = []): array
{
    $headers = [
        'Tus-Resumable' => '1.0.0',
        'Upload-Length' => (string) $size,
    ];

    if ($metadata !== []) {
        $headers['Upload-Metadata'] = collect($metadata)
            ->map(fn (string $value, string $key): string => $key.' '.base64_encode($value))
            ->implode(',');
    }

    return $headers;
}
