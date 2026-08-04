<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\Forbidden;

/**
 * The self-service credential change refused because the submitted current password does not match the stored
 * one. The caller's session stays valid and nothing mutates — this is a rejected re-proof of ownership, not a
 * failed authentication.
 *
 * 403 rather than 401 is load-bearing on the client side: the PWA's HTTP client diverts any 401 outside the
 * login handshake to `/login?reason=session-expired`, so answering 401 would evict from the application someone
 * who merely mistyped. Overrides `type()` to the specific `invalid-current-password` while the {@see Forbidden}
 * marker carries the status — the same marker-plus-`type()` pattern as {@see AccountLocked}, so no new marker
 * interface joins the contract. Carries no `AccessDeniedException` in its chain, so `UnauthenticatedAccessListener`
 * leaves it alone and the RFC 9457 pipeline emits the 403 through the marker branch.
 */
final class InvalidCurrentPassword extends DomainException implements Forbidden
{
    public function __construct()
    {
        parent::__construct(
            type: 'invalid-current-password',
            title: 'The current password you entered is not correct.',
        );
    }
}
