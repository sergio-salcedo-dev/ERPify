<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Images\Domain\DerivativeOrigin;
use Erpify\Shared\Images\Domain\ImageId;
use InvalidArgumentException;

/**
 * An image and its canonical representation — dimensions, media type, digest of the canonical
 * bytes — and, for a dependent image, the {@see DerivativeOrigin} it derives from and dies with (ADR
 * D5, D8); an image without one is fungible. Carries no bytes, no owner, no filename, no URL and no
 * conservation-contract classification (D3: there is no method, command or endpoint anywhere that could reclassify one
 * from fungible to evidence — the only supported path for treating the same content as evidence is
 * re-uploading it as a new resource of a future `Documents` context).
 *
 * `final readonly` with only a constructor and accessors is what makes that true by construction:
 * there is no mutation surface to reclassify, not even internally. That modifier is load-bearing
 * beyond style — Doctrine's readonly guard refuses to overwrite an initialised property, so the
 * runtime, and not a test, is what keeps `createdAt` from being re-stamped on hydration.
 *
 * It maps itself rather than extending the shared `AggregateRoot`, and the reason is a contract
 * mismatch rather than taste. That base collects domain events, which this aggregate never records:
 * the owning context decides an image is no longer needed and publishes after its own commit, so
 * `pullDomainEvents()` here would answer the empty list for ever. Inheriting it would additionally
 * force an `updated_at` column this schema does not have, publish three setters that would dissolve
 * the paragraph above, and collide outright with the value-object accessor below, since the base
 * reserves `id()` as `final protected` returning a string.
 *
 * The identity is an {@see ImageId} at the boundary and a scalar `GUID` in storage. Keeping the
 * stored half a plain `Types::GUID` rather than a dedicated DBAL type is what keeps this column
 * inside the universe of `make php.lint.person-reference`, which enumerates person-bearing columns
 * by that exact type — a custom type would leave the classification unguarded.
 *
 * `refresh()` on a managed instance is outside this contract: rehydrating re-writes initialised
 * readonly properties and is refused. That is the immutability working, not a defect.
 */
#[ORM\Entity]
#[ORM\Table(name: 'image')]
// The release's re-query by origin. Partial, because fungible images, the large majority, carry no origin; the
// predicate is spelled as PostgreSQL prints it back, since the schema comparison matches it as text.
#[ORM\Index(
    name: 'idx_image_origin',
    columns: ['origin_type', 'origin_id'],
    options: ['where' => '(origin_id IS NOT NULL)'],
)]
final readonly class Image
{
    private const int DIGEST_HEX_LENGTH = 64;

    /**
     * Doctrine "assigned" identifier: the id is minted by {@see ImageId::generate()} and assigned
     * before persist, so there is deliberately no {@see ORM\GeneratedValue} to overwrite it.
     */
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    /**
     * Stored with microsecond precision through the project's own `datetimetz_immutable` type. The
     * built-in immutable type declares `TIMESTAMP(0)` on PostgreSQL, which would round every value
     * to the whole second and leave the round-trip test unable to tell a preserved stamp from a
     * fresh one taken in the same second.
     */
    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * Both origin columns are set together or not at all (a `CHECK` in the migration), and never change: an image
     * fixed to its origin cannot be reclassified, by the same absence of a mutation surface that keeps D3 true.
     */
    #[ORM\Column(name: 'origin_type', length: DerivativeOrigin::TYPE_MAX_LENGTH, nullable: true)]
    private ?string $originType;

    /**
     * A plain `Types::GUID`, so the column stays inside the person-reference sweep (`api/.person-reference-policy`).
     */
    #[ORM\Column(name: 'origin_id', type: Types::GUID, nullable: true)]
    private ?string $originId;

    public function __construct(
        ImageId $id,
        #[ORM\Column(length: self::DIGEST_HEX_LENGTH)]
        private string $digest,
        #[ORM\Column(name: 'media_type', length: 64)]
        private string $mediaType,
        #[ORM\Column(type: Types::INTEGER)]
        private int $width,
        #[ORM\Column(type: Types::INTEGER)]
        private int $height,
        #[ORM\Column(name: 'byte_size', type: Types::INTEGER)]
        private int $byteSize,
        ?DerivativeOrigin $origin = null,
    ) {
        // `D` is load-bearing: without it `$` also matches before a trailing newline, so 63 hex digits
        // followed by "\n" are 64 characters that satisfy both halves of this guard.
        if (self::DIGEST_HEX_LENGTH !== \strlen($this->digest) || 1 !== \preg_match('/^[0-9a-f]+$/D', $this->digest)) {
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

        $this->id = $id->toString();
        $this->createdAt = SystemClock::now();
        $this->originType = $origin?->type;
        $this->originId = $origin?->id;
    }

    public function id(): ImageId
    {
        return ImageId::fromString($this->id);
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
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

    /**
     * Whether the image derives from another resource, read without rebuilding the origin: the read route asks it on
     * every request, and a stored tag the value object would refuse must not turn that answer into a 500.
     */
    public function isDependent(): bool
    {
        return null !== $this->originId;
    }

    public function origin(): ?DerivativeOrigin
    {
        if (null === $this->originType || null === $this->originId) {
            return null;
        }

        return new DerivativeOrigin($this->originType, $this->originId);
    }
}
