<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * What a value under an {@see AuditMetadataKey} may look like. Every shape but two is a COUNTABLE or CLOSED
 * thing — a count, a flag, a server-defined name, an identifier of a non-person — which is the only kind of
 * value `metadata` can hold and stay outside every erasure pass: both anonymisers rewrite columns and never
 * this JSON, so anything person-shaped written here outlives the erasure of the person it names.
 *
 * The two that admit text the server did not choose are named for it. {@see StoredValues} carries the raw
 * cells an integrity probe could not explain, because an operator repairs the string they were shown and a
 * normalised one would be useless to them. {@see FieldDiff} carries a change diff whose personal fields are
 * sealed before they get here ({@see SEALED_VALUE_KEY}); its clear fields are whatever the aggregate held.
 * Neither is a licence for a third.
 */
enum AuditMetadataShape
{
    /** The key a sealed diff value is wrapped under, so a reader can show it as sealed without the plaintext. */
    public const string SEALED_VALUE_KEY = '__enc__';

    private const string ROUTE_NAME_PATTERN = '/^[A-Za-z0-9_.]{1,100}$/';

    /** Kebab case, at most 64 characters: the lookahead bounds the length the repetition cannot. */
    private const string SLUG_PATTERN = '/^(?=.{1,64}$)[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private const string AGGREGATE_TYPE_PATTERN = '/^[A-Z][A-Za-z0-9]{0,99}$/';

    private const string ROLE_NAME_PATTERN = '/^[A-Z][A-Z0-9_]*$/';

    public function admits(mixed $value): bool
    {
        return match ($this) {
            self::Count => self::isCount($value),
            self::Flag => \is_bool($value),
            self::RouteName => null === $value || self::matches($value, self::ROUTE_NAME_PATTERN),
            self::AuditEntryId => null === $value || self::isUuid($value),
            self::Slug => self::matches($value, self::SLUG_PATTERN),
            self::Pseudonym => self::isUuid($value),
            self::EncryptionScope => self::isEncryptionScope($value),
            self::RoleNames => self::isListOf(
                $value,
                static fn (mixed $role): bool => self::matches($role, self::ROLE_NAME_PATTERN),
            ),
            self::Instant => self::isInstant($value),
            self::StoredValues => self::isListOf($value, \is_string(...)),
            self::FieldDiff => self::isFieldDiff($value),
            self::WriteOperation => self::isWriteOperation($value),
        };
    }

    /**
     * A type test of an input, not the narrowing of a store result that `is_int()` is confined against.
     */
    private static function isCount(mixed $value): bool
    {
        return 'int' === \get_debug_type($value) && $value >= 0;
    }

    private static function matches(mixed $value, string $pattern): bool
    {
        return \is_string($value) && 1 === \preg_match($pattern, $value);
    }

    private static function isUuid(mixed $value): bool
    {
        return \is_string($value) && Uuid::isValid($value);
    }

    private static function isWriteOperation(mixed $value): bool
    {
        return \is_string($value) && \defined(AuditWriteOperation::class . '::' . $value);
    }

    private static function isEncryptionScope(mixed $value): bool
    {
        if (!\is_string($value)) {
            return false;
        }

        $parts = \explode(':', $value, 2);

        return 2 === \count($parts)
            && 1 === \preg_match(self::AGGREGATE_TYPE_PATTERN, $parts[0])
            && Uuid::isValid($parts[1]);
    }

    private static function isInstant(mixed $value): bool
    {
        if (!\is_string($value)) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value);

        return $parsed instanceof DateTimeImmutable && $parsed->format(DateTimeInterface::ATOM) === $value;
    }

    /**
     * @param callable(mixed): bool $element
     */
    private static function isListOf(mixed $value, callable $element): bool
    {
        if (!\is_array($value) || !\array_is_list($value)) {
            return false;
        }

        return \array_all($value, static fn ($item) => $element($item));
    }

    private static function isFieldDiff(mixed $value): bool
    {
        if (!\is_array($value)) {
            return false;
        }

        return \array_all($value, static fn ($pair, $field): bool => \is_string($field) && self::isDiffPair($pair));
    }

    private static function isDiffPair(mixed $pair): bool
    {
        return \is_array($pair)
            && 2 === \count($pair)
            && \array_key_exists('old', $pair)
            && \array_key_exists('new', $pair)
            && self::isDiffSide($pair['old'])
            && self::isDiffSide($pair['new']);
    }

    private static function isDiffSide(mixed $side): bool
    {
        if (null === $side || \is_scalar($side)) {
            return true;
        }

        return \is_array($side)
            && 1 === \count($side)
            && \array_key_exists(self::SEALED_VALUE_KEY, $side)
            && \is_string($side[self::SEALED_VALUE_KEY]);
    }

    /** A non-negative integer: how many rows, tokens or sessions an act touched. */
    case Count;

    case Flag;

    /** A Symfony route NAME — never a path, whose segments may carry an identifier. Nullable. */
    case RouteName;

    /** The id of another `audit_log` row: an investigation record, never a person. Nullable. */
    case AuditEntryId;

    /** A kebab-case token minted by the server from a closed set, such as a refusal type. */
    case Slug;

    /** A UUID minted by an erasure to stand in for a person, with no link back to them. */
    case Pseudonym;

    /**
     * An `<AggregateType>:<uuid>` crypto-shredding scope. Narrower than the scope type itself, which leaves its
     * id unconstrained: here it must be a UUID, so the text after the separator can only be an aggregate's id.
     */
    case EncryptionScope;

    /** A list of role names, possibly empty: the enum's backing values, upper snake case. */
    case RoleNames;

    /** An instant in `DATE_ATOM`, the spelling the API serves every other timestamp in. */
    case Instant;

    /** A list of raw stored strings an integrity probe could not explain. */
    case StoredValues;

    /** `<field> => {old, new}`, each side a scalar, null, or a sealed `{__enc__: <ciphertext>}`. */
    case FieldDiff;

    /** The NAME of an {@see AuditWriteOperation} case. */
    case WriteOperation;
}
