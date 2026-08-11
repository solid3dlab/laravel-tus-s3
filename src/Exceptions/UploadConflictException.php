<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Exceptions;

use Solid3d\LaravelTusS3\Facades\Tus;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UploadConflictException extends HttpException
{
    public function __construct(string $message = 'The upload could not be modified.', int $statusCode = 409)
    {
        parent::__construct(
            statusCode: $statusCode,
            message: $message,
            headers: Tus::headers()->default()->toArray()
        );
    }
}
