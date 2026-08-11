<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;

class FileUploadBeforeCreated
{
    use Dispatchable;

    public function __construct(public Request $request) {}
}
