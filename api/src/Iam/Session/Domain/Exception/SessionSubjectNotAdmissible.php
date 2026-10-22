<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\Unauthenticated;

/**
 * A login reached the mint and, once the identity's row was held, the identity was gone or no longer `ACTIVE` —
 * erased, suspended or deactivated by a write that committed while the login was in flight.
 *
 * An {@see Unauthenticated} marker → 401 `unauthenticated`, the same answer an invited identity gets at the
 * door: the credential was proved against a row that no longer admits anyone, and naming which wall closed in
 * the meantime would grade a race the client can do nothing about. Not the 503 an outage answers — nothing is
 * down and a retry meets the same wall. `ClientError`, so it does not reach Sentry.
 *
 * It carries no context: the identity is a person.
 */
final class SessionSubjectNotAdmissible extends DomainException implements Unauthenticated
{
    public static function atMint(): self
    {
        return new self(
            type: 'unauthenticated',
            title: 'The identity can no longer be signed in.',
        );
    }
}
