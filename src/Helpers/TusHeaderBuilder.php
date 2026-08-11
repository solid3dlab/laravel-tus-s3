<?php

declare(strict_types=1);

namespace Solid3d\LaravelTusS3\Helpers;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Date;
use Solid3d\LaravelTusS3\Facades\Tus;

/**
 * @implements Arrayable<string, string|int>
 */
class TusHeaderBuilder implements Arrayable
{
    /** @var array<string, string|int> */
    protected array $headers;

    public function __construct(protected string $version)
    {
        $this->headers = [
            'Access-Control-Expose-Headers' => '*',
            'Tus-Resumable' => $version,
            'Cache-Control' => 'no-store',
        ];
    }

    public function version(): static
    {
        $this->headers['Tus-Version'] = $this->version;

        return $this;
    }

    public function offset(int $offset): static
    {
        $this->headers['Upload-Offset'] = $offset;

        return $this;
    }

    public function maxSize(): static
    {
        $max = Tus::maxFileSize();

        if ($max !== null) {
            $this->headers['Tus-Max-Size'] = $max;
        }

        return $this;
    }

    public function extensions(): static
    {
        $extensions = config('tus.extensions');

        if (! is_array($extensions) || $extensions === []) {
            return $this;
        }

        $this->headers['Tus-Extension'] = implode(',', $extensions);

        return $this;
    }

    public function location(string $id): static
    {
        $this->headers['Location'] = config('tus.url')
            ? rtrim((string) config('tus.url'), '/').route('tus.patch', $id, false)
            : route('tus.patch', $id);

        return $this;
    }

    public function expires(?\DateTimeInterface $expiresAt): static
    {
        if (! Tus::extensionIsActive('expiration') || $expiresAt === null) {
            return $this;
        }

        $this->headers['Upload-Expires'] = Date::instance($expiresAt)->toRfc7231String();

        return $this;
    }

    public function checksumAlgorithm(): static
    {
        if (! Tus::extensionIsActive('checksum')) {
            return $this;
        }

        $this->headers['Tus-Checksum-Algorithm'] = implode(',', (array) config('tus.checksum_algorithm'));

        return $this;
    }

    public function length(int $length): static
    {
        $this->headers['Upload-Length'] = $length;

        return $this;
    }

    public function forOptions(): static
    {
        $this->version()->maxSize()->extensions()->checksumAlgorithm();

        return $this;
    }

    public function forPost(TusFile $tusFile, int $offset, ?\DateTimeInterface $expiresAt): static
    {
        $this
            ->location($tusFile->id)
            ->offset($offset)
            ->expires($expiresAt)
            ->maxSize();

        return $this;
    }

    public function forHead(TusFile $tusFile, int $offset, int $length, ?\DateTimeInterface $expiresAt): static
    {
        $this
            ->length($length)
            ->offset($offset)
            ->expires($expiresAt);

        return $this;
    }

    public function forPatch(int $offset, ?\DateTimeInterface $expiresAt): static
    {
        $this->offset($offset)->expires($expiresAt);

        return $this;
    }

    public function default(): static
    {
        return $this;
    }

    /**
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        return $this->headers;
    }
}
