<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Exceptions;

use Solid3d\LaravelTusS3\Facades\Tus;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ChecksumAlgorithmMismatchException extends HttpException
{
    public function __construct()
    {
        parent::__construct(
            statusCode: 400,
            headers: Tus::headers()->default()->toArray()
        );
    }
}
