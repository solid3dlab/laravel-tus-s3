<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Facades;

use Illuminate\Support\Facades\Facade;
use Solid3d\LaravelTusS3\Contracts\TusUploadStore;
use Solid3d\LaravelTusS3\Helpers\TusHeaderBuilder;

/**
 * @method static string version()
 * @method static TusHeaderBuilder headers()
 * @method static TusUploadStore store()
 * @method static bool isValidChecksum(string $algo, string $hash, string $payload)
 * @method static int|null maxFileSize()
 * @method static bool isInMaxFileSize(int $size)
 * @method static bool extensionIsActive(string $extension)
 *
 * @see \Solid3d\LaravelTusS3\Tus
 */
class Tus extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Solid3d\LaravelTusS3\Tus::class;
    }
}
