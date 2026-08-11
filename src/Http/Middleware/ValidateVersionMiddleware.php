<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Solid3d\LaravelTusS3\Exceptions\VersionMismatchException;
use Solid3d\LaravelTusS3\Facades\Tus;
use Symfony\Component\HttpFoundation\Response;

class ValidateVersionMiddleware
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        if ($request->header('tus-resumable') !== Tus::version()) {
            throw new VersionMismatchException;
        }

        return $next($request);
    }
}
