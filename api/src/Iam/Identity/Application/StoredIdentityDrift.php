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
     * @return array<string, mixed>
     */
    public function toAuditMetadata(): array
    {
        return [
            'orphan_role_values' => $this->orphanRoleValues,
            'malformed_roles' => $this->malformedRoles,
            'unreadable_credentials' => $this->unreadableCredentials,
            'admitted_without_credential' => $this->admittedWithoutCredential,
        ];
    }
}
