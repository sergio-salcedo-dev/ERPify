<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when the invitation flow cannot find the invited identity where its own invariants require it: the
 * just-provisioned identity reports no id, or a resent invitation points at an identity that no longer exists.
 * Both are internal integrity violations, not input the caller could correct.
 *
 * Marker-less by design: it maps to a 500 (a broken invariant, never a 4xx), and the invite/resend surface is
 * CLI in this slice.
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
}
