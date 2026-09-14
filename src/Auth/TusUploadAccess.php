<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Auth;

use Illuminate\Http\Request;
use Solid3d\LaravelTusS3\Contracts\TusUploadStore;
use Solid3d\LaravelTusS3\Contracts\UploadOwnerResolver;
use Solid3d\LaravelTusS3\Exceptions\FileNotFoundException;

final class TusUploadAccess
{
    public function __construct(
        private TusUploadStore $store,
        private UploadOwnerResolver $ownerResolver,
    ) {}

    public function assertAccessible(Request $request, string $uploadId): void
    {
        if (! (bool) config('tus.ownership.enabled', true)) {
            return;
        }

        $owner = $this->store->owner($uploadId);

        if ($owner !== null && ! $owner->matches($this->ownerResolver->resolve($request))) {
            throw new FileNotFoundException;
        }
    }
}
