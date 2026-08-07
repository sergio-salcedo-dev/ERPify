<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when the invitation flow cannot find the invited identity where its own invariants require it: the
 * just-provisioned identity reports no id, or a resent invitation points at an identity that no longer exists.
 * Both are internal integrity violations, not input the caller could correct.
 *
 * Marker-less by design → HTTP 500: an integrity violation is a server fault, never a client-correctable 4xx.
 * A 4xx marker would disguise that server fault as a client error the caller could fix by changing its input.
 */
final class InvitedIdentityUnavailable extends DomainException
{
    private function __construct(string $title)
    {
        parent::__construct(
            type: 'invited-identity-unavailable',
            title: $title,
        );
    }

    public static function withoutId(): self
    {
        return new self('The invited identity was provisioned without an id.');
    }

    public static function missing(): self
    {
        return new self('The invited identity no longer exists.');
    }

    public static function withdrawn(): self
    {
        return new self('The invited identity has been withdrawn.');
    }
}
