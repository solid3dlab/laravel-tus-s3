<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Contracts;

use Illuminate\Http\Request;
use Solid3d\LaravelTusS3\Domain\UploadOwner;

interface UploadOwnerResolver
{
    public function resolve(Request $request): ?UploadOwner;
}
