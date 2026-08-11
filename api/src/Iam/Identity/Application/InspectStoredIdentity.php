<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

/**
 * Asks every stored-identity probe once and returns what they found, as one value.
 *
 * It exists because the assembly has two callers — the operator's command and the scheduled alarm — and an
 * assembly duplicated across them is the shape that rots: adding a fifth probe would leave whichever caller
 * nobody remembered reporting a table as clean over a finding it never asked about. Neither the exit code nor
 * the alarm would say so.
 */
final readonly class InspectStoredIdentity
{
    public function __construct(private StoredIdentityIntegrity $storedIdentities)
    {
    }

    /**
     * @throws StoredIdentityProbeFailed when any read fails, so the caller reaches no verdict
     */
    public function __invoke(): StoredIdentityDrift
    {
        return new StoredIdentityDrift(
            $this->storedIdentities->roleValuesOutsideTheEnum(),
            $this->storedIdentities->identitiesWithMalformedRoles(),
            $this->storedIdentities->identitiesWithAnUnreadableCredential(),
            $this->storedIdentities->admittedIdentitiesWithoutACredential(),
        );
    }
}
