<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Solid3d\LaravelTusS3\LaravelTusS3ServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tus.storage_disk' => 'local',
            'tus.temporary_prefix' => 'tus/tmp',
            'tus.upload_expiration' => 60,
            'tus.file_size_limit' => 10_485_761,
            'tus.min_part_size' => 5,
            'tus.max_part_bytes' => 10_485_760,
            'tus.middleware' => [],
            'tus.path' => 'tus',
            'tus.extensions' => ['creation', 'expiration', 'checksum', 'termination'],
            'filesystems.disks.local.root' => storage_path('framework/testing/disks/local'),
            'filesystems.disks.local.driver' => 'local',
        ]);

        Schema::create('tus_uploads', function (Blueprint $table): void {
            $table->string('id', 40)->primary();
            $table->string('disk', 64);
            $table->string('object_key', 512);
            $table->string('multipart_upload_id', 255)->nullable();
            $table->unsignedBigInteger('expected_size');
            $table->unsignedBigInteger('offset')->default(0);
            $table->unsignedInteger('next_part_number')->default(1);
            $table->string('status', 32);
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata');
            $table->json('parts');
            $table->string('patch_lock_owner', 64)->nullable();
            $table->timestamp('patch_lock_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    protected function getPackageProviders($app): array
    {
        return [LaravelTusS3ServiceProvider::class];
    }
}
