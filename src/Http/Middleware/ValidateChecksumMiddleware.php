<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Solid3d\LaravelTusS3\Exceptions\ChecksumAlgorithmMismatchException;
use Solid3d\LaravelTusS3\Exceptions\ChecksumMismatchException;
use Solid3d\LaravelTusS3\Exceptions\InvalidChecksumException;
use Solid3d\LaravelTusS3\Facades\Tus;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates Upload-Checksum for small POST bodies. PATCH checksums are
 * validated in the store while buffering a single bounded chunk.
 */
class ValidateChecksumMiddleware
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasHeader('upload-checksum') || ! Tus::extensionIsActive('checksum')) {
            return $next($request);
        }

        $parts = explode(' ', (string) $request->header('upload-checksum'), 2);

        if (
            count($parts) !== 2
            || $parts[0] === ''
            || $parts[1] === ''
            || base64_decode($parts[1], true) === false
        ) {
            throw new InvalidChecksumException;
        }

        [$algorithm, $hash] = $parts;

        if (! in_array($algorithm, (array) config('tus.checksum_algorithm'), true)) {
            throw new ChecksumAlgorithmMismatchException;
        }

        // PATCH bodies are validated in the store to avoid double-reading streams.
        if ($request->isMethod('PATCH')) {
            return $next($request);
        }

        if (! Tus::isValidChecksum($algorithm, $hash, $request->getContent())) {
            throw new ChecksumMismatchException;
        }

        return $next($request);
    }
}
