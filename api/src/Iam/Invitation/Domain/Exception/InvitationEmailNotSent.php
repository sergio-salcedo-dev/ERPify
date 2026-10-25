<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\ServiceUnavailable;

/**
 * The outcome of a console resend whose fresh link was minted and committed but whose email the mailer
 * refused. A {@see ServiceUnavailable} (503): the request was well-formed and authorized, and what failed is a
 * dependency the caller cannot fix by changing it — so it reaches the error tracker, as an operational fault
 * should.
 *
 * The CLI answers the same outcome by printing the accept token for an out-of-band hand-over; an HTTP response
 * may not, because the token is a credential and a response body is a place it must never travel. What the
 * console is told instead is what decides its next move: the previous link is already dead and the invitee
 * holds no working one, so the remedy is to resend once mail works. That retry is safe — it re-tokenises the
 * same row and invalidates the link this attempt minted.
 *
 * No `context`: the subject is a person, and unlike a 4xx this problem is sent to the error tracker.
 */
final class InvitationEmailNotSent extends DomainException implements ServiceUnavailable
{
    public function __construct()
    {
        parent::__construct(
            type: 'invitation-email-not-sent',
            title: 'A new invitation link was issued, but the email could not be sent. The previous link no '
                . 'longer works; resend the invitation once email delivery is restored.',
        );
    }
}
