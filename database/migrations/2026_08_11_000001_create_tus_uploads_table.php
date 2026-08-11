<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tus_uploads', function (Blueprint $table): void {
            $table->string('id', 40)->primary();
            $table->string('disk', 64);
            $table->string('object_key', 512);
            $table->string('multipart_upload_id', 255)->nullable();
            $table->unsignedBigInteger('expected_size');
            $table->unsignedBigInteger('offset')->default(0);
            $table->unsignedInteger('next_part_number')->default(1);
            $table->string('status', 32);
            $table->timestamp('expires_at')->nullable()->index();
            $table->json('metadata');
            $table->json('parts');
            $table->string('patch_lock_owner', 64)->nullable();
            $table->timestamp('patch_lock_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->unique(['disk', 'object_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tus_uploads');
    }
};
