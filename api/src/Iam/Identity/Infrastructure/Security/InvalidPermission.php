<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Security;

use InvalidArgumentException;

/**
 * Raised when a permission string is not the canonical `<resource>.<action>` form.
 *
 * This is a programmer error, not a client-facing fault: permission literals are authored in the code
 * (`#[IsGranted('bank.write')]`), never taken from a request, so a malformed one is a bug to fix. Hence a
 * plain {@see InvalidArgumentException} rather than an RFC 9457 domain exception — it is never an invariant
 * a client can trip, and routing it through the Problem Details pipeline would wrongly imply a 4xx a caller
 * could provoke. In practice it never fires on a request path: the voter only builds a {@see Permission}
 * after {@see Permission::isWellFormed()} passes.
 */
final class InvalidPermission extends InvalidArgumentException
{
    public function __construct(string $malformed)
    {
        parent::__construct(\sprintf(
            'Malformed permission "%s"; expected the canonical form "<resource>.<action>".',
            $malformed,
        ));
    }
}
