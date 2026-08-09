<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\StoredIdentityIntegrity;
use Erpify\Iam\Identity\Application\StoredIdentityProbeFailed;
use Override;

/**
 * A {@see StoredIdentityIntegrity} whose reads always fail, standing in for a database the control could not
 * question — the outcome no scenario can arrange, since a run that breaks the connection breaks the suite
 * carrying it, and the one a caller must never read as a clean table.
 *
 * @internal
 */
final readonly class FailingStoredIdentityIntegrity implements StoredIdentityIntegrity
{
    #[Override]
    public function roleValuesOutsideTheEnum(): array
    {
        throw StoredIdentityProbeFailed::uncountableResultFrom('identity_user.roles');
    }

    #[Override]
    public function identitiesWithMalformedRoles(): int
    {
        throw StoredIdentityProbeFailed::uncountableResultFrom('identity_user.roles');
    }

    #[Override]
    public function identitiesWithAnUnreadableCredential(): int
    {
        throw StoredIdentityProbeFailed::uncountableResultFrom('identity_user.password_hash');
    }

    #[Override]
    public function admittedIdentitiesWithoutACredential(): int
    {
        throw StoredIdentityProbeFailed::uncountableResultFrom('identity_user.password_hash');
    }
}
