<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Commands;

use Illuminate\Console\Command;
use Solid3d\LaravelTusS3\Contracts\TusUploadStore;
use Solid3d\LaravelTusS3\Events\FileUploadFinished;

class DispatchFinishedTusUploadsCommand extends Command
{
    public $signature = 'tus:dispatch-finished {--limit=100 : Maximum uploads to notify per run}';

    public $description = 'Deliver FileUploadFinished for uploads that completed but were never notified';

    /**
     * A PATCH can complete the S3 multipart upload and then lose the worker
     * before dispatching the event. Without this sweep those uploads would sit
     * finished-but-unprocessed forever.
     */
    public function handle(TusUploadStore $store): int
    {
        $notified = 0;

        foreach ($store->unnotifiedCompleted((int) $this->option('limit')) as $id) {
            $completed = $store->pullCompleted($id);

            if ($completed === null) {
                continue;
            }

            event(new FileUploadFinished($completed));
            $notified++;
        }

        $this->comment("Notified {$notified} completed Tus uploads");

        return self::SUCCESS;
    }
}
