<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Exceptions;

use Solid3d\LaravelTusS3\Facades\Tus;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FileAppendException extends HttpException
{
    public function __construct(int $statusCode = 500, string $message = '', array $headers = [])
    {
        parent::__construct(
            statusCode: $statusCode,
            message: $message,
            headers: $headers === [] ? Tus::headers()->default()->toArray() : $headers
        );
    }
}
