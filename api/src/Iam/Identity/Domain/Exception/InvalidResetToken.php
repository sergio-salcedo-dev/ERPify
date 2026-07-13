<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\InvalidInput;

/**
 * The single opaque failure of a password-reset attempt: a token that is malformed, unknown, expired or
 * already consumed all collapse to this one exception. Carries the {@see InvalidInput} marker so the RFC 9457
 * pipeline maps it to a 400, and overrides `type()` to the shared wire type `invalid-token` (the same type an
 * invitation-acceptance dead link produces, so a dead reset link is indistinguishable from a dead invitation
 * link — opacity holds across both surfaces). One message for every death case: never the reason.
 *
 * The precedent is {@see \Erpify\Shared\Uuid\Domain\InvalidUuidException} (an `InvalidInput` with a `type()`
 * override, 400). A distinct class per context by design — this reset-specific exception lives in Identity, not
 * in `Shared/ErrorContract`, so context isolation holds even though the wire type is shared.
 */
final class InvalidResetToken extends DomainException implements InvalidInput
{
    public function __construct()
    {
        parent::__construct(
            type: 'invalid-token',
            title: 'This link is no longer valid.',
        );
    }
}
