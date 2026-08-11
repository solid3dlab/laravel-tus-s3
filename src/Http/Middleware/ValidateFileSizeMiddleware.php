<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Solid3d\LaravelTusS3\Exceptions\FileSizeLimitException;
use Solid3d\LaravelTusS3\Facades\Tus;
use Symfony\Component\HttpFoundation\Response;

class ValidateFileSizeMiddleware
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ((int) config('tus.file_size_limit') <= 0) {
            return $next($request);
        }

        if ($request->hasHeader('upload-length') && ! Tus::isInMaxFileSize((int) $request->header('upload-length'))) {
            throw new FileSizeLimitException;
        }

        if ($request->hasHeader('content-length') && ! Tus::isInMaxFileSize((int) $request->header('content-length'))) {
            throw new FileSizeLimitException;
        }

        return $next($request);
    }
}
