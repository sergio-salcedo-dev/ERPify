<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain;

use Erpify\Shared\Uuid\Domain\Uuid;
use InvalidArgumentException;

/**
 * The resource a dependent image derives from: the owning context's own tag for the aggregate and its id. An image that
 * carries one dies with that resource; an image that carries none is fungible (ADR
 * `images-vs-documents-conservation-contract.md` D5, D8).
 *
 * The type is an opaque `<Context>.<Aggregate>` tag the caller supplies — the spelling `DomainEvent::aggregateType()`
 * already uses — so this module names no consumer and decides nothing about what the tag means. The id is a UUID,
 * lower-cased here for the reason {@see ImageId} gives: it is compared by value in the database and by spelling here.
 */
final readonly class DerivativeOrigin
{
    public const int TYPE_MAX_LENGTH = 64;

    /** `D`: without it `$` also matches before a trailing newline. */
    private const string TYPE_PATTERN = '/^[A-Z][A-Za-z0-9]*\.[A-Z][A-Za-z0-9]*$/D';

    public string $type;

    public string $id;

    public function __construct(string $type, string $id)
    {
        if (\strlen($type) > self::TYPE_MAX_LENGTH || 1 !== \preg_match(self::TYPE_PATTERN, $type)) {
            throw new InvalidArgumentException(
                'The origin type must be a `<Context>.<Aggregate>` tag of at most 64 characters.',
            );
        }

        Uuid::ensure($id);

        $this->type = $type;
        $this->id = \strtolower($id);
    }
}
