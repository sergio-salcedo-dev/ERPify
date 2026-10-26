<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

/**
 * The set of permissions the installation publishes, asked one attribute at a time. It is what
 * {@see PermissionVoter} claims: an attribute outside it is not a permission, whatever its shape, so the voter
 * abstains on it and leaves it to whichever voter owns it.
 */
interface PublishedPermissions
{
    public function contains(string $candidate): bool;
}
