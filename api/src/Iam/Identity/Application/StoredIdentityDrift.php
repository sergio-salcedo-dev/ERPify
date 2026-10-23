<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

/**
 * What one run of the stored-identity inspection found, as one value.
 *
 * It exists so "is the table clean" is answered in a single place. Four independent facts spread across a
 * caller's `if` means the day a fifth is added, the clean branch is the one nobody remembers to widen — and a
 * clean branch that has fallen behind is not a bug that shows up as a failure, it is a control that reports
 * SUCCESS over the very finding it just read.
 *
 * Counts and values only, never identities: the drifted rows are exactly the person ids that axis would then
 * owe an erasure, and every finding here is actionable without one.
 */
final readonly class StoredIdentityDrift
{
    /** How many orphan values the audit row samples; the total travels beside them. */
    public const int SAMPLED_ORPHAN_VALUES = 20;

    /** How many characters of each sampled value the audit row keeps. */
    public const int SAMPLED_VALUE_LENGTH = 64;

    /**
     * @param list<string> $orphanRoleValues
     */
    public function __construct(
        public array $orphanRoleValues,
        public int $malformedRoles,
        public int $unreadableCredentials,
        public int $admittedWithoutCredential,
    ) {
    }

    public function isClean(): bool
    {
        return [] === $this->orphanRoleValues
            && 0 === $this->malformedRoles
            && 0 === $this->unreadableCredentials
            && 0 === $this->admittedWithoutCredential;
    }

    /**
     * The orphan values are SAMPLED, not listed: they are raw column text, as many and as long as the corruption
     * made them, and the audit row is bounded — a row refused for its size would leave the trail with no record
     * that the drift was ever detected, which is the one thing this finding exists to leave. The sample and the
     * total keep the row actionable; the complete list is what the inspection prints for the operator.
     *
     * @return array{
     *     orphan_role_values: list<string>,
     *     orphan_role_value_count: int<0, max>,
     *     malformed_roles: int,
     *     unreadable_credentials: int,
     *     admitted_without_credential: int,
     * }
     */
    public function toAuditMetadata(): array
    {
        return [
            'orphan_role_values' => \array_map(
                static fn (string $value): string => \mb_substr($value, 0, self::SAMPLED_VALUE_LENGTH),
                \array_slice($this->orphanRoleValues, 0, self::SAMPLED_ORPHAN_VALUES),
            ),
            'orphan_role_value_count' => \count($this->orphanRoleValues),
            'malformed_roles' => $this->malformedRoles,
            'unreadable_credentials' => $this->unreadableCredentials,
            'admitted_without_credential' => $this->admittedWithoutCredential,
        ];
    }
}
