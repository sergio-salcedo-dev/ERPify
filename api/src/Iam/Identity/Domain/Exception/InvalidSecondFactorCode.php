<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\Forbidden;

/**
 * A code presented from a live session refused: wrong, outside the accepted window, malformed or already
 * accepted once. One answer for all four, so the response never says which.
 *
 * 403 rather than 401 for the reason {@see InvalidCurrentPassword} gives: the PWA's HTTP client diverts any 401
 * outside the login handshake to `/login?reason=session-expired`, so a 401 would sign out someone who merely
 * mistyped. Nothing mutates on this answer.
 */
final class InvalidSecondFactorCode extends DomainException implements Forbidden
{
    public const string TYPE = 'invalid-second-factor-code';

    public function __construct()
    {
        parent::__construct(
            type: self::TYPE,
            title: 'The verification code is not valid.',
        );
    }
}
