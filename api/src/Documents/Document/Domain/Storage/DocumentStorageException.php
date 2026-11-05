<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Domain\Storage;

use RuntimeException;

/**
 * Base of the port's failures. It carries a fixed sentence and the area it concerns, and deliberately neither
 * the key nor the library's message: both quote a path derived from the scope id, and a message travels into
 * `messenger_messages`, which no erasure path prunes by subject.
 */
abstract class DocumentStorageException extends RuntimeException
{
    final public function __construct(private readonly StorageArea $area, string $reason)
    {
        parent::__construct($reason);
    }

    final public function area(): StorageArea
    {
        return $this->area;
    }
}
