<?php

declare(strict_types=1);

namespace Erpify\Shared\Storage\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Immutable handle to a content-addressable blob persisted by
 * {@see \Erpify\Shared\Storage\Application\StoredImageObjectWriter}.
 * Groups the four columns (key, mime type, byte size, content hash) that
 * aggregates such as Bank persist inline alongside their own row.
 *
 * Mapped as a Doctrine embeddable so any aggregate can adopt it with a single
 * {@see ORM\Embedded} property under its own `columnPrefix`. The fields are
 * nullable with defaults because Doctrine always instantiates the embeddable —
 * even for an all-NULL row — so {@see isEmpty()} lets the owner expose a clean
 * `?StoredObject` getter. Each field pins an explicit column `name` (the bare
 * suffix) so `columnPrefix . name` reproduces the legacy column names exactly,
 * independent of the naming strategy.
 */
#[ORM\Embeddable]
final readonly class StoredObject
{
    public function __construct(
        #[ORM\Column(name: 'key', length: 512, nullable: true)]
        public ?string $objectKey = null,
        #[ORM\Column(name: 'mime_type', length: 64, nullable: true)]
        public ?string $mimeType = null,
        #[ORM\Column(name: 'byte_size', type: Types::INTEGER, nullable: true)]
        public ?int $byteSize = null,
        #[ORM\Column(name: 'content_hash', length: 64, nullable: true)]
        public ?string $contentHash = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return null === $this->objectKey;
    }
}
