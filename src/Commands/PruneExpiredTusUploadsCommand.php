<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Commands;

use Illuminate\Console\Command;
use Solid3d\LaravelTusS3\Contracts\TusUploadStore;

class PruneExpiredTusUploadsCommand extends Command
{
    public $signature = 'tus:prune';

    public $description = 'Abort expired Tus multipart uploads and remove stale records';

    public function handle(TusUploadStore $store): int
    {
        if ((int) config('tus.upload_expiration') < 1) {
            $this->comment('Tus upload expiration is disabled.');

            return self::SUCCESS;
        }

        $cleaned = $store->pruneExpired();
        $this->comment("Cleaned {$cleaned} expired or stale Tus uploads");

        return self::SUCCESS;
    }
}
