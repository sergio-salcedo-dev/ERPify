<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Listing;

/**
 * The outcome of opening one document's name: the name, no name because there is nothing to open, or no name because
 * opening failed with a {@see NameCause}. The caller lists `name: null` in the last two alike; only the scheduled
 * report tells them apart.
 */
final readonly class OpenedName
{
    private function __construct(
        public ?string $name,
        public ?NameCause $failure,
    ) {
    }

    public static function named(string $name): self
    {
        return new self($name, null);
    }

    public static function absent(): self
    {
        return new self(null, null);
    }

    public static function failed(NameCause $cause): self
    {
        return new self(null, $cause);
    }
}
