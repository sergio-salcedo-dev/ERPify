<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Identity\Infrastructure\Security;

/**
 * A permission as a value: the pair (resource, action) rendered canonically as `"<resource>.<action>"`
 * (e.g. `bank.write`). It is the vocabulary the authorization pipeline speaks; there is deliberately no
 * `Permission` entity or table — a permission is a value, not a stored record.
 *
 * It lives under Infrastructure/Security as a packaging choice, not because it is framework-bound: it is
 * pure PHP with no Symfony dependency, parked next to the voter so the whole authorization core can be
 * promoted to a shared kernel later without a redesign. {@see AuthorizationPolicy} keeps it framework-free.
 */
final readonly class Permission
{
    private const string SEPARATOR = '.';

    private function __construct(
        private string $resource,
        private string $action,
    ) {
    }

    /**
     * @throws InvalidPermission when $permission is not exactly `<resource>.<action>` (both parts non-empty)
     */
    public static function fromString(string $permission): self
    {
        $pair = self::split($permission);

        if (null === $pair) {
            throw new InvalidPermission($permission);
        }

        return new self($pair[0], $pair[1]);
    }

    /**
     * Whether $candidate has the exact `<resource>.<action>` shape a {@see self} requires. The voter asks
     * this to decide it supports an attribute before ever constructing one, so a Symfony token such as
     * `ROLE_AUDIT_READER` (no separator) is classified "not a permission" without raising.
     */
    public static function isWellFormed(string $candidate): bool
    {
        return null !== self::split($candidate);
    }

    public function resource(): string
    {
        return $this->resource;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function toString(): string
    {
        return $this->resource . self::SEPARATOR . $this->action;
    }

    public function equals(self $other): bool
    {
        return $this->resource === $other->resource && $this->action === $other->action;
    }

    /**
     * @return array{string, string}|null the (resource, action) pair, or null when the form is invalid
     */
    private static function split(string $permission): ?array
    {
        $parts = \explode(self::SEPARATOR, $permission);

        if (2 !== \count($parts) || !self::isCanonicalSegment($parts[0]) || !self::isCanonicalSegment($parts[1])) {
            return null;
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * A resource/action segment is a non-empty run of letters and digits that starts with a letter — the
     * camelCase vocabulary permissions are authored in (`bank`, `auditTrail`, `changeStatus`). Rejecting
     * whitespace, punctuation and the `*` tier-wildcard sentinel means a typo'd literal fails loudly rather
     * than building a permission that silently matches nothing (a route no one can reach). Case is
     * significant, so a case-only typo still slips through — inherent to a case-sensitive vocabulary.
     */
    private static function isCanonicalSegment(string $segment): bool
    {
        return 1 === \preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $segment);
    }
}
