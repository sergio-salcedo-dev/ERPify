<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Shared\Domain\Aggregate\AggregateRoot;
use Erpify\Shared\Media\Domain\Repository\MediaRepository;
use RuntimeException;

/**
 * (repositoryClass is the domain interface; the concrete repository implementation is wired via DI).
 *
 * @phpstan-ignore argument.type
 */
#[ORM\Entity(repositoryClass: MediaRepository::class)]
#[ORM\Table(name: 'media')]
#[ORM\UniqueConstraint(name: 'media_content_hash_uniq', fields: ['contentHash'])]
final class Media extends AggregateRoot
{
    private function __construct(
        string $id,
        #[ORM\Column(name: 'content_hash', length: 64)]
        private string $contentHash,
        #[ORM\Column(name: 'mime_type', length: 64)]
        private string $mimeType,
        #[ORM\Column(name: 'byte_size', type: Types::INTEGER)]
        private int $byteSize,
        /** @var resource|string */
        #[ORM\Column(name: 'raw_bytes', type: Types::BLOB)]
        private mixed $rawBytes,
    ) {
        parent::__construct();

        $this->id = $id;
    }

    public static function create(
        string $id,
        string $contentHash,
        string $mimeType,
        int $byteSize,
        string $rawBytes,
    ): self {
        return new self($id, $contentHash, $mimeType, $byteSize, $rawBytes);
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getByteSize(): int
    {
        return $this->byteSize;
    }

    public function getRawBytes(): string
    {
        if (\is_resource($this->rawBytes)) {
            $contents = \stream_get_contents($this->rawBytes);

            // why: a failed BLOB stream read must not degrade to '' — callers serve these
            // bytes as the response body, and an empty 200 is silent, cacheable corruption.
            if (false === $contents) {
                throw new RuntimeException(\sprintf('Failed to read raw bytes stream of media "%s".', $this->id));
            }

            $this->rawBytes = $contents;
        }

        // @phpstan-ignore return.type (BLOB hydrates to string|resource; narrowed to string above)
        return $this->rawBytes;
    }
}
