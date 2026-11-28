<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * A confirmation refused because the identity has no pending enrolment to confirm. A {@see Conflict} marker →
 * 409: the target is the caller's own account and it exists, so a 404 would misname the situation, while the
 * remedy — start an enrolment — is a change of state the client can make.
 */
final class SecondFactorNotEnrolled extends DomainException implements Conflict
{
    public const string TYPE = 'second-factor-not-enrolled';

    public function __construct()
    {
        parent::__construct(
            type: self::TYPE,
            title: 'There is no two-step verification enrolment to confirm. Start an enrolment first.',
        );
    }
}
