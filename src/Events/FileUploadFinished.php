<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Solid3d\LaravelTusS3\Helpers\TusFile;

class FileUploadFinished
{
    use Dispatchable;

    public function __construct(public TusFile $tusFile) {}
}
