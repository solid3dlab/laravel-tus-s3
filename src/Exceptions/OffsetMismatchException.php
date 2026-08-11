<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Exceptions;

use Solid3d\LaravelTusS3\Facades\Tus;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OffsetMismatchException extends HttpException
{
    public function __construct(int $currentOffset)
    {
        parent::__construct(
            statusCode: 409,
            headers: Tus::headers()->default()->offset($currentOffset)->toArray()
        );
    }
}
