<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use RuntimeException;

/**
 * {@see CreateUser::create()} returned an identity the persistence layer never assigned an id to, so the
 * membership that has to be granted against that id cannot be. Raised rather than tolerated because the
 * caller's next step writes a row keyed on it: a null there would provision an administrator nobody belongs
 * to, and the bootstrap would report success.
 *
 * Deliberately NOT a {@see \Erpify\Shared\ErrorContract\Domain\Exception\DomainException}, unlike the two
 * sibling guards on the same shape ({@see \Erpify\Iam\Invitation\Domain\Exception\InvitedIdentityUnavailable}
 * and {@see \Erpify\Organization\Membership\Domain\Exception\OrganizationNotProvisioned}): those are reached
 * over HTTP and need a `ProblemDetails.type`, while the only caller here is a console command whose contract
 * is its exit code. Minting a wire-format error name for a path that never answers a request would put a
 * member in that vocabulary nothing can ever emit.
 *
 * The message names neither the email nor the id: the operator learns the invariant that broke, and a person
 * identifier reaching the console's own log is exactly what
 * {@see \Erpify\Shared\Monitoring\Infrastructure\Monolog\ConsoleCommandRedactionProcessor} exists to prevent.
 */
final class IdentityProvisionedWithoutId extends RuntimeException
{
    public static function afterCreation(): self
    {
        return new self('The created identity was provisioned without an id, so no membership can be granted.');
    }
}
