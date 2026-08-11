<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Enums;

enum UploadStatus: string
{
    case Pending = 'pending';
    case Uploading = 'uploading';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Cancelled, self::Expired => true,
            default => false,
        };
    }
}
