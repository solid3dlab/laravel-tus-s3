<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tus_uploads', function (Blueprint $table): void {
            $table->string('sha256', 64)->nullable()->after('offset');
            $table->timestamp('finished_notified_at')->nullable()->after('completed_at');

            // Drives the sweep that guarantees FileUploadFinished is delivered even
            // when the request that completed the upload never got to dispatch it.
            $table->index(['status', 'finished_notified_at'], 'tus_uploads_finish_sweep_index');
        });
    }

    public function down(): void
    {
        Schema::table('tus_uploads', function (Blueprint $table): void {
            $table->dropIndex('tus_uploads_finish_sweep_index');
            $table->dropColumn(['sha256', 'finished_notified_at']);
        });
    }
};
