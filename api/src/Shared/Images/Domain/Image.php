<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain;

use DateTimeImmutable;
use Erpify\Shared\Clock\Domain\SystemClock;
use InvalidArgumentException;

/**
 * A fungible image and its canonical representation — dimensions, media type, digest of the
 * canonical bytes. Carries no bytes, no owner, no filename, no URL and no conservation-contract
 * classification (D3: there is no method, command or endpoint anywhere that could reclassify one
 * from fungible to evidence — the only supported path for treating the same content as evidence is
 * re-uploading it as a new resource of a future `Documents` context).
 *
 * `final readonly` with only a constructor and accessors is what makes that true by construction:
 * there is no mutation surface to reclassify, not even internally.
 */
final readonly class Image
{
    private const int DIGEST_HEX_LENGTH = 64;

    public DateTimeImmutable $createdAt;

    public function __construct(
        private ImageId $id,
        private string $digest,
        private string $mediaType,
        private int $width,
        private int $height,
        private int $byteSize,
    ) {
        if (self::DIGEST_HEX_LENGTH !== \strlen($this->digest) || 1 !== \preg_match('/^[0-9a-f]+$/', $this->digest)) {
            throw new InvalidArgumentException('The digest must be a 64-character hexadecimal SHA-256 string.');
        }

        if ($this->width <= 0) {
            throw new InvalidArgumentException('The width must be a positive integer.');
        }

        if ($this->height <= 0) {
            throw new InvalidArgumentException('The height must be a positive integer.');
        }

        if ($this->byteSize <= 0) {
            throw new InvalidArgumentException('The byte size must be a positive integer.');
        }

        if ('' === \trim($this->mediaType)) {
            throw new InvalidArgumentException('The media type must not be empty.');
        }

        $this->createdAt = SystemClock::now();
    }

    public function id(): ImageId
    {
        return $this->id;
    }

    public function digest(): string
    {
        return $this->digest;
    }

    public function mediaType(): string
    {
        return $this->mediaType;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function byteSize(): int
    {
        return $this->byteSize;
    }
}
