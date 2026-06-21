<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;

final class MediaNotFoundException extends DomainException implements NotFound
{
    public static function withContentHash(string $contentHash): self
    {
        return new self(
            type: 'media-not-found',
            title: \sprintf('Media with content hash <%s> not found.', $contentHash),
            context: ['contentHash' => $contentHash],
        );
    }
}
