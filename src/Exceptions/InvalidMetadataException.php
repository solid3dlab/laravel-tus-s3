<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Exceptions;

use Solid3d\LaravelTusS3\Facades\Tus;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InvalidMetadataException extends HttpException
{
    public function __construct(string $message = 'Invalid Upload-Metadata.')
    {
        parent::__construct(
            statusCode: 400,
            message: $message,
            headers: Tus::headers()->default()->toArray()
        );
    }
}
