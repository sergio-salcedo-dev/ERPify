<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * An enrolment or a confirmation refused because the identity's second factor is already enabled. A
 * {@see Conflict} marker → 409, with a `type()` of its own so the client can say what resolves it — disable the
 * factor first — rather than read a generic `conflict`.
 *
 * Refusing is the point. Starting a new enrolment over an enabled factor would let whoever holds the session
 * and the password replace the owner's authenticator without the code the owner holds, which is exactly the
 * removal without a code that disabling is designed to require.
 */
final class SecondFactorAlreadyEnabled extends DomainException implements Conflict
{
    public const string TYPE = 'second-factor-already-enabled';

    public function __construct()
    {
        parent::__construct(
            type: self::TYPE,
            title: 'Two-step verification is already enabled for this account.',
        );
    }
}
