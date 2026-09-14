<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Auth;

use Illuminate\Http\Request;
use Solid3d\LaravelTusS3\Contracts\UploadOwnerResolver;
use Solid3d\LaravelTusS3\Domain\UploadOwner;

final class AuthenticatedUploadOwnerResolver implements UploadOwnerResolver
{
    public function resolve(Request $request): ?UploadOwner
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        return new UploadOwner(
            type: $user::class,
            id: (string) $user->getAuthIdentifier(),
        );
    }
}
