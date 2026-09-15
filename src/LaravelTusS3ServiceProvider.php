<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3;

use RuntimeException;
use Solid3d\LaravelTusS3\Auth\AuthenticatedUploadOwnerResolver;
use Solid3d\LaravelTusS3\Commands\DispatchFinishedTusUploadsCommand;
use Solid3d\LaravelTusS3\Commands\PruneExpiredTusUploadsCommand;
use Solid3d\LaravelTusS3\Contracts\MultipartUploader;
use Solid3d\LaravelTusS3\Contracts\TusUploadStore;
use Solid3d\LaravelTusS3\Contracts\UploadOwnerResolver;
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
            ->hasCommands([
                PruneExpiredTusUploadsCommand::class,
                DispatchFinishedTusUploadsCommand::class,
            ]);
    }

    public function packageBooted(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/tus.php' => config_path('tus.php'),
        ], 'tus-config');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Tus::class);
        $this->app->singleton(S3KeyResolver::class);
        $this->app->singleton(UploadOwnerResolver::class, function ($app): UploadOwnerResolver {
            $resolver = $app->make((string) config(
                'tus.ownership.resolver',
                AuthenticatedUploadOwnerResolver::class,
            ));

            if (! $resolver instanceof UploadOwnerResolver) {
                throw new RuntimeException('The configured Tus owner resolver is invalid.');
            }

            return $resolver;
        });

        $this->app->singleton(MultipartUploader::class, function ($app): MultipartUploader {
            $diskName = (string) config('tus.storage_disk');

            if ($this->isS3Disk($diskName)) {
                return $app->make(S3MultipartUploader::class);
            }

            return $app->make(LocalMultipartUploader::class);
        });

        $this->app->singleton(TusUploadStore::class, DurableTusUploadStore::class);
    }

    private function isS3Disk(string $diskName): bool
    {
        return $this->app->make(S3KeyResolver::class)->usesS3($diskName);
    }
}
