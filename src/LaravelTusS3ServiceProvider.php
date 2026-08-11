<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Solid3d\LaravelTusS3\Commands\PruneExpiredTusUploadsCommand;
use Solid3d\LaravelTusS3\Contracts\MultipartUploader;
use Solid3d\LaravelTusS3\Contracts\TusUploadStore;
use Solid3d\LaravelTusS3\Storage\DurableTusUploadStore;
use Solid3d\LaravelTusS3\Storage\LocalMultipartUploader;
use Solid3d\LaravelTusS3\Storage\S3KeyResolver;
use Solid3d\LaravelTusS3\Storage\S3MultipartUploader;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelTusS3ServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-tus-s3')
            ->hasConfigFile('tus')
            ->hasRoute('tus')
            ->hasMigration('2026_08_11_000001_create_tus_uploads_table')
            ->runsMigrations()
            ->hasCommand(PruneExpiredTusUploadsCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Tus::class);
        $this->app->singleton(S3KeyResolver::class);

        $this->app->singleton(MultipartUploader::class, function ($app): MultipartUploader {
            $diskName = (string) config('tus.storage_disk');
            $disk = Storage::disk($diskName);

            if ($this->isS3Disk($disk, $diskName)) {
                return $app->make(S3MultipartUploader::class);
            }

            return $app->make(LocalMultipartUploader::class);
        });

        $this->app->singleton(TusUploadStore::class, DurableTusUploadStore::class);
    }

    private function isS3Disk(Filesystem $disk, string $diskName): bool
    {
        if (config("filesystems.disks.{$diskName}.driver") !== 's3') {
            return false;
        }

        // Storage::fake('s3') replaces the disk with a local adapter that has no client.
        return method_exists($disk, 'getClient');
    }
}
