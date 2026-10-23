<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Domain\Exception;

use Erpify\Iam\Session\Domain\SubjectAdmission;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\Unauthenticated;
use LogicException;

/**
 * A login reached the mint and, once the identity's row was held, the identity was gone or no longer `ACTIVE` —
 * erased, suspended or deactivated by a write that committed while the login was in flight. It carries which of
 * the three through {@see reason()}, so the caller that owns the response can answer a suspension or a
 * deactivation with the wall the firewall itself would have shown.
 *
 * As raised, it answers the case nobody translates — the identity is gone — and that answer is the firewall's
 * own uniform failure: an {@see Unauthenticated} marker, 401 `unauthenticated`, and the very title the
 * authentication failure handler gives a wrong password, so an erasure that won the race is indistinguishable
 * from a credential that never matched. `ClientError`, so it does not reach Sentry.
 *
 * It carries no context: the identity is a person, and the reason is a property of the object rather than of
 * the wire body.
 */
final class SessionSubjectNotAdmissible extends DomainException implements Unauthenticated
{
    public const string TYPE = 'unauthenticated';

    private function __construct(private readonly SubjectAdmission $reason)
    {
        parent::__construct(type: self::TYPE, title: 'Invalid credentials.');
    }

    /**
     * @throws LogicException when handed {@see SubjectAdmission::Admissible}, which is not a refusal
     */
    public static function atMint(SubjectAdmission $reason): self
    {
        if (SubjectAdmission::Admissible === $reason) {
            throw new LogicException('An admissible identity is not a refused mint.');
        }

        return new self($reason);
    }

    public function reason(): SubjectAdmission
    {
        return $this->reason;
    }
}
