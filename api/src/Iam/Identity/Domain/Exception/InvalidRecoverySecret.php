<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\InvalidInput;

/**
 * The single opaque failure of a recovery-secret redemption: a malformed presentation, an unknown selector, a
 * lapsed secret, a wrong secret, a row a rival redemption already consumed, and an exhausted per-selector
 * budget all collapse to this one exception. Carries the {@see InvalidInput} marker so the RFC 9457 pipeline
 * maps it to a 400, and overrides `type()` to the shared wire type `invalid-token`.
 *
 * Sharing that type with {@see InvalidResetToken} and the invitation dead link is the point rather than reuse:
 * this endpoint is anonymous, and the whole channel rests on a selector buying denial and never knowledge, so
 * a redemption failure must not even be distinguishable from a dead reset link. The `title` is identical for
 * the same reason — the reason never travels.
 *
 * A separate class from {@see InvalidResetToken} despite the identical wire shape: they are refusals of two
 * different flows, and one class shared between them would make a future change to either one silently
 * change the other.
 *
 * What is deliberately NOT folded in here is a valid secret over a non-`ACTIVE` identity. That case answers
 * 403 from `ensureActive()` and is IDENTIFIED, not opaque — the presenter has already proven possession of the
 * secret, so telling them why the account will not admit them reveals nothing they could not obtain by
 * redeeming a working one.
 */
final class InvalidRecoverySecret extends DomainException implements InvalidInput
{
    public function __construct()
    {
        parent::__construct(
            type: 'invalid-token',
            title: 'This link is no longer valid.',
        );
    }
}
