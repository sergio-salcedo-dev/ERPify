<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Application;

use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * The replacement half of a {@see SubjectPseudonymisation}, typed so it cannot be confused with the
 * identifier it replaces. Both are UUIDs, so no validation can tell them apart: a type can. Swapping the pair
 * takes `SubjectPseudonym::fromString($subjectId)`, a line that names the mistake instead of hiding it in an
 * argument order.
 */
final readonly class SubjectPseudonym
{
    private function __construct(
        public string $value,
    ) {
    }

    /**
     * @throws InvalidUuidException when `$value` is not a well-formed UUID
     */
    public static function fromString(string $value): self
    {
        Uuid::ensure($value);

        return new self($value);
    }
}
