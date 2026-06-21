<?php

declare(strict_types=1);

namespace Erpify\Shared\Uuid\Domain;

use Symfony\Component\Uid\Uuid as SymfonyUuid;

/**
 * UUID identity for the domain. Built on `symfony/uid` under a documented layer
 * exception (see `docs/rules/architecture.md`): it is a leaf component with no
 * framework coupling, and is the best primitive for creating and validating
 * UUIDs across versions. Abstract base for UUID value objects: children extend
 * it and inherit `generate()`, the single static v7 mint.
 */
abstract class Uuid
{
    public static function generate(): string
    {
        return SymfonyUuid::v7()->toRfc4122();
    }

    /**
     * Predicate for a well-formed RFC 4122 UUID. The single domain definition of
     * UUID validity: callers that need a guard use {@see Uuid::ensure()}, callers
     * that branch and raise their own error (e.g. search filters) use this.
     *
     * Validates format, not version: any RFC 4122 UUID is accepted — minting
     * stays v7 (see {@see Uuid::generate()}), but identifiers received from the
     * outside are only required to be well-formed.
     */
    public static function isValid(string $value): bool
    {
        return SymfonyUuid::isValid($value);
    }

    /**
     * Guards that `$value` is a well-formed RFC 4122 UUID, throwing the domain
     * {@see InvalidUuidException} (mapped to 400 `invalid-input`) otherwise.
     *
     * @throws InvalidUuidException
     */
    public static function ensure(string $value): void
    {
        if (!self::isValid($value)) {
            throw new InvalidUuidException();
        }
    }
}
